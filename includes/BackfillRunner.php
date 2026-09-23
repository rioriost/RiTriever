<?php
/**
 * Durable background queue with connection-owned workers and revision fences.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Database\BackfillQueueSchema;
use RiTriever\Database\DatabaseLock;
use RiTriever\Database\LocalVectorRepository;
use RiTriever\Database\Sql;
use RiTriever\Database\VectorSchema;

final class BackfillRunner
{
    public const OPTION_KEY = "ritriever_backfill_queue";
    public const CRON_HOOK = "ritriever_process_backfill_queue";
    public const DEFAULT_BATCH_SIZE = 20;
    public const MAX_ATTEMPTS = 5;

    public static function register(): void
    {
        add_action(self::CRON_HOOK, [self::class, "process_scheduled"]);
        add_filter("cron_schedules", [self::class, "cron_schedules"]);
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules["ritriever_minute"] = ["interval" => 60, "display" => "RiTriever watchdog"];
        return $schedules;
    }

    public static function status(): array
    {
        BackfillQueueSchema::install_or_upgrade();
        $job = self::latest_job();
        return $job === null ? self::idle_state() : self::job_state($job);
    }

    public static function create_queue(): array
    {
        return DatabaseLock::with("lifecycle", static function (): array {
            global $wpdb;
            BackfillQueueSchema::install_or_upgrade();
            self::stop_for_invalidation("Superseded by explicit initialization.");
            $generation = IndexState::begin_build();
            $job_id = 0;
            try {
                $job_id = self::new_job($generation, "initial");
                VectorSchema::recreate();
                $ids = self::eligible_post_ids();
                Sql::transaction(static function () use ($wpdb, $job_id, $ids): void {
                    foreach ($ids as $post_id) {
                        self::put_item($job_id, $post_id);
                    }
                    $actual = self::item_count($job_id);
                    if ($actual !== count($ids)) {
                        throw new \RuntimeException("Queue preparation count mismatch; initialize again.");
                    }
                    Sql::query($wpdb->prepare("UPDATE %i SET total_posts = %d, status = 'queued', phase = 'queued', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'preparing'", BackfillQueueSchema::jobs_table(), $actual, $job_id));
                });
                self::schedule_next_batch();
                return self::finish_or_continue($job_id);
            } catch (\RuntimeException $e) {
                if ($job_id > 0) {
                    self::fail_job($job_id, "Initialization failed; inspect database availability and explicitly retry initialization.");
                }
                IndexState::fail("Initialization failed; explicitly retry initialization.");
                throw $e;
            }
        });
    }

    public static function process_scheduled(): array
    {
        try {
            return self::process_batch();
        } catch (\RuntimeException $e) {
            // The recurring watchdog is already durable before provider/DB work.
            Logger::error("queue", "Queue attempt failed; watchdog will retry.", ["error" => $e->getMessage()]);
            return ["status" => "retrying", "last_error" => "Queue attempt failed; watchdog will retry."];
        }
    }

    public static function process_batch(int $limit = self::DEFAULT_BATCH_SIZE): array
    {
        Settings::refresh();
        BackfillQueueSchema::install_or_upgrade();
        $state = self::status();
        if (!in_array($state["status"], ["queued", "running"], true)) {
            return $state;
        }
        self::schedule_next_batch();
        $worker = DatabaseLock::acquire("worker");
        if ($worker === null) {
            $state["stop_reason"] = "Another worker owns the database session lock.";
            return $state;
        }
        $job_id = (int) $state["job_id"];
        $token = bin2hex(random_bytes(16));
        try {
            $ids = DatabaseLock::with("lifecycle", static function () use ($worker, $job_id, $token, $limit): array {
                global $wpdb;
                $worker->assert_owned();
                $job = self::job_by_id($job_id);
                if ($job === null || !in_array($job["status"], ["queued", "running"], true)) {
                    return [];
                }
                if (!IndexState::can_sync()) {
                    self::transition($job_id, "paused", "Global synchronization is stopped.");
                    self::unschedule();
                    return [];
                }
                if (($job["index_generation"] ?? "") !== IndexState::generation() || !IndexState::is_writable()) {
                    self::transition($job_id, "cancelled", "Index generation changed; explicitly initialize.");
                    self::unschedule();
                    return [];
                }
                // Owning the non-expiring worker lock proves no previous session
                // can commit. Reclaim its processing rows without waiting for a TTL.
                Sql::query($wpdb->prepare("UPDATE %i SET status = IF(attempts >= %d, 'failed', 'pending'), locked_by = '', locked_at = NULL, last_error = 'Previous worker stopped; reclaimed by watchdog.', updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status = 'processing'", BackfillQueueSchema::items_table(), self::MAX_ATTEMPTS, $job_id));
                Sql::query($wpdb->prepare("UPDATE %i SET status = 'processing', locked_by = %s, locked_at = UTC_TIMESTAMP(), claimed_revision = revision, attempts = attempts + 1, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status = 'pending' AND (available_at IS NULL OR available_at <= UTC_TIMESTAMP()) ORDER BY id LIMIT %d", BackfillQueueSchema::items_table(), $token, $job_id, max(1, min(200, $limit))));
                $ids = array_map("intval", array_column(Sql::rows($wpdb->prepare("SELECT post_id FROM %i WHERE job_id = %d AND status = 'processing' AND locked_by = %s ORDER BY id", BackfillQueueSchema::items_table(), $job_id, $token)), "post_id"));
                if ($ids !== []) {
                    self::transition($job_id, "running", "");
                }
                return $ids;
            });

            if ($ids !== []) {
                try {
                    $result = BulkBackfillIndexer::process_posts($ids, static function (int $post_id) use ($worker, $job_id, $token): void {
                        $worker->assert_owned();
                        self::assert_claim($job_id, $token, $post_id);
                    });
                } catch (\RuntimeException $e) {
                    $result = ["failures" => array_fill_keys($ids, $e), "stale_ids" => []];
                }
                DatabaseLock::with("lifecycle", static function () use ($worker, $job_id, $token, $ids, $result): void {
                    $worker->assert_owned();
                    foreach ($ids as $post_id) {
                        try {
                            self::assert_claim($job_id, $token, $post_id);
                        } catch (StaleIndexWork $e) {
                            continue;
                        }
                        if (in_array($post_id, $result["stale_ids"] ?? [], true)) {
                            self::retry_item($job_id, $token, $post_id, new StaleIndexWork("Post changed during indexing."), true);
                        } elseif (isset($result["failures"][$post_id])) {
                            self::retry_item($job_id, $token, $post_id, $result["failures"][$post_id]);
                        } else {
                            self::complete_item($job_id, $token, $post_id);
                        }
                    }
                });
            }
            return DatabaseLock::with("lifecycle", static fn(): array => self::finish_or_continue($job_id));
        } finally {
            $worker->release();
        }
    }

    public static function run(?callable $progress = null): array
    {
        $state = self::create_queue();
        while (in_array($state["status"], ["queued", "running"], true)) {
            $before = (int) $state["processed"];
            $state = self::process_batch();
            for ($i = $before + 1; $progress !== null && $i <= (int) $state["processed"]; ++$i) {
                $progress(0, $i, (int) $state["errors"]);
            }
            if ((int) $state["processed"] === $before && in_array($state["status"], ["queued", "running"], true)) {
                throw new \RuntimeException("No immediate progress: work is locked or backing off. The cron watchdog will continue; inspect queue status.");
            }
        }
        return ["processed" => (int) $state["processed"], "errors" => (int) $state["errors"], "completed_at" => (int) $state["completed_at"]];
    }

    /** Queue final WordPress state, coalescing repeated hooks into one item. */
    public static function enqueue_posts(array $post_ids): int
    {
        $post_ids = self::normalize_ids($post_ids);
        if ($post_ids === [] || !IndexState::can_sync()) {
            return 0;
        }
        return DatabaseLock::with("lifecycle", static function () use ($post_ids): int {
            global $wpdb;
            if (!IndexState::is_writable()) {
                return 0;
            }
            BackfillQueueSchema::install_or_upgrade();
            $job = self::latest_job();
            if ($job === null || $job["index_generation"] !== IndexState::generation() || !in_array($job["status"], ["queued", "running", "paused"], true)) {
                $job_id = self::new_job(IndexState::generation(), "sync");
                self::transition($job_id, "queued", "");
            } else {
                $job_id = (int) $job["id"];
            }
            Sql::transaction(static function () use ($post_ids, $job_id, $wpdb): void {
                foreach ($post_ids as $post_id) {
                    self::put_item($job_id, $post_id);
                }
                Sql::query($wpdb->prepare("UPDATE %i SET total_posts = %d, updated_at = UTC_TIMESTAMP() WHERE id = %d", BackfillQueueSchema::jobs_table(), self::item_count($job_id), $job_id));
            });
            if (($job["status"] ?? "") !== "paused") {
                self::schedule_next_batch();
            }
            return count($post_ids);
        });
    }

    /** Counts describe enqueue results, never promise indexing success. */
    public static function create_retry_queue(?int $post_id = null): array
    {
        return self::retry_failed($post_id !== null && $post_id > 0 ? [$post_id] : []);
    }

    /** Counts describe enqueue results, never promise indexing success. */
    public static function retry_failed(array $ids = []): array
    {
        return DatabaseLock::with("lifecycle", static function () use ($ids): array {
            global $wpdb;
            BackfillQueueSchema::install_or_upgrade();
            $job = self::latest_job();
            if ($ids === []) {
                $ids = array_column(Sql::rows($wpdb->prepare("SELECT DISTINCT post_id FROM %i WHERE meta_key = %s AND meta_value <> ''", $wpdb->postmeta, RITRIEVER_POSTMETA_LAST_ERROR)), "post_id");
                if ($job !== null) {
                    $ids = array_merge($ids, array_column(Sql::rows($wpdb->prepare("SELECT post_id FROM %i WHERE job_id = %d AND status = 'failed'", BackfillQueueSchema::items_table(), (int) $job["id"])), "post_id"));
                }
            }
            $ids = self::normalize_ids($ids);
            $eligible = array_values(array_filter($ids, static fn(int $id): bool => PostFilter::is_eligible($id)));
            $pending = 0;
            $active = $job !== null && in_array($job["status"], ["preparing", "queued", "running", "paused"], true);
            if ($eligible !== [] && IndexState::can_sync() && !$active) {
                IndexState::allow_retry();
                $job_id = self::new_job(IndexState::generation(), "retry");
                try {
                    Sql::transaction(static function () use ($eligible, $job_id, $wpdb): void {
                        foreach ($eligible as $post_id) {
                            self::put_item($job_id, $post_id);
                        }
                        if (self::item_count($job_id) !== count($eligible)) {
                            throw new \RuntimeException("Retry snapshot preparation count mismatch.");
                        }
                        Sql::query($wpdb->prepare("UPDATE %i SET total_posts = %d, status = 'queued', phase = 'queued', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'preparing'", BackfillQueueSchema::jobs_table(), count($eligible), $job_id));
                    });
                    self::schedule_next_batch();
                    $pending = count($eligible);
                } catch (\RuntimeException $e) {
                    self::fail_job($job_id, "Retry snapshot preparation failed. Correct the database issue and retry.");
                    IndexState::fail("Retry snapshot preparation failed. Correct the database issue and retry.");
                    throw $e;
                }
            }
            $state = self::status();
            if ($eligible !== [] && $pending === 0) {
                $state["stop_reason"] = $active
                    ? "An existing queue must finish or be cancelled before creating a retry snapshot."
                    : "Synchronization is disabled or globally stopped.";
            }
            return [
                "requested" => count($ids), "succeeded" => 0, "failed" => 0,
                "pending" => $pending, "skipped" => count($ids) - $pending, "state" => $state,
            ];
        });
    }

    public static function pause(): array
    {
        return self::control("paused");
    }

    public static function resume(): array
    {
        return self::control("queued");
    }

    public static function cancel(): array
    {
        return self::control("cancelled");
    }

    private static function control(string $target): array
    {
        return DatabaseLock::with("lifecycle", static function () use ($target): array {
            global $wpdb;
            BackfillQueueSchema::install_or_upgrade();
            $job = self::latest_job();
            $allowed = $target === "queued" ? ["paused"] : ["queued", "running", "paused"];
            if ($job !== null && in_array($job["status"], $allowed, true)) {
                if ($target === "queued" && ($job["index_generation"] !== IndexState::generation() || !IndexState::is_writable() || Settings::get("kill_switch_global") || !Settings::get("sync_enabled"))) {
                    throw new \RuntimeException("Cannot resume: enable synchronization and initialize current settings first.");
                }
                $job_id = (int) $job["id"];
                self::transition($job_id, $target, $target === "cancelled" ? "Cancelled by an administrator." : "");
                $item_status = $target === "cancelled" ? "cancelled" : "pending";
                Sql::query($wpdb->prepare("UPDATE %i SET status = %s, revision = revision + 1, locked_by = '', locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND status IN ('pending','processing')", BackfillQueueSchema::items_table(), $item_status, $job_id));
                if ($target === "queued") {
                    self::schedule_next_batch();
                } else {
                    self::unschedule();
                }
                if ($target === "cancelled") {
                    IndexState::fail("Indexing was cancelled. Explicitly initialize or retry before resuming synchronization.");
                }
            }
            return self::status();
        });
    }

    /** Called with the lifecycle lock by IndexState; never runs destructive DDL. */
    public static function stop_for_invalidation(string $reason): void
    {
        global $wpdb;
        BackfillQueueSchema::install_or_upgrade();
        Sql::query($wpdb->prepare("UPDATE %i SET status = 'cancelled', phase = 'cancelled', last_error = %s, updated_at = UTC_TIMESTAMP(), completed_at = UTC_TIMESTAMP() WHERE status IN ('preparing','queued','running','paused')", BackfillQueueSchema::jobs_table(), $reason));
        Sql::query($wpdb->prepare("UPDATE %i SET status = 'cancelled', revision = revision + 1, locked_by = '', locked_at = NULL WHERE status IN ('pending','processing')", BackfillQueueSchema::items_table()));
        self::unschedule();
    }

    public static function clear_queue(): void
    {
        DatabaseLock::with("lifecycle", static function (): void {
            global $wpdb;
            self::stop_for_invalidation("Queue cleared.");
            Sql::transaction(static function () use ($wpdb): void {
                Sql::query($wpdb->prepare("DELETE FROM %i", BackfillQueueSchema::items_table()));
                Sql::query($wpdb->prepare("DELETE FROM %i", BackfillQueueSchema::jobs_table()));
            });
            delete_option(self::OPTION_KEY);
        });
    }

    public static function schedule_next_batch(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $result = wp_schedule_event(time() + 5, "ritriever_minute", self::CRON_HOOK, [], true);
            if (is_wp_error($result) || $result === false) {
                throw new \RuntimeException("Unable to schedule the queue watchdog; configure WP-Cron or use WP-CLI.");
            }
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function assert_claim(int $job_id, string $token, int $post_id): void
    {
        global $wpdb;
        $count = Sql::value($wpdb->prepare(
            "SELECT COUNT(*) FROM %i i INNER JOIN %i j ON j.id = i.job_id WHERE i.job_id = %d AND i.post_id = %d AND i.status = 'processing' AND i.locked_by = %s AND i.revision = i.claimed_revision AND j.status IN ('queued','running') AND j.index_generation = %s",
            BackfillQueueSchema::items_table(), BackfillQueueSchema::jobs_table(), $job_id, $post_id, $token, IndexState::generation(),
        ));
        if ((int) $count !== 1) {
            throw new StaleIndexWork("Queue claim was superseded, paused, or cancelled.");
        }
    }

    private static function complete_item(int $job_id, string $token, int $post_id): void
    {
        global $wpdb;
        PostSync::refresh_post($post_id);
        if (PostFilter::is_eligible($post_id) && !(new LocalVectorRepository())->has_current($post_id, PostSync::content_hash($post_id), IndexState::generation())) {
            self::retry_item($job_id, $token, $post_id, new StaleIndexWork("Current post has no matching durable index receipt."), true);
            return;
        }
        Sql::query($wpdb->prepare("UPDATE %i SET status = 'done', locked_by = '', locked_at = NULL, last_error = '', updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND post_id = %d AND locked_by = %s AND status = 'processing' AND revision = claimed_revision", BackfillQueueSchema::items_table(), $job_id, $post_id, $token));
        delete_post_meta($post_id, RITRIEVER_POSTMETA_LAST_ERROR);
    }

    private static function retry_item(int $job_id, string $token, int $post_id, \Throwable $error, bool $stale = false): void
    {
        global $wpdb;
        $attempts = (int) Sql::value($wpdb->prepare("SELECT attempts FROM %i WHERE job_id = %d AND post_id = %d AND locked_by = %s", BackfillQueueSchema::items_table(), $job_id, $post_id, $token));
        $retryable = $stale || self::is_retryable($error);
        $pending = $retryable && ($stale || $attempts < self::MAX_ATTEMPTS);
        $delay = $stale ? 5 : min(3600, 30 * (2 ** max(0, $attempts - 1)));
        if (method_exists($error, "retry_after")) {
            $delay = max($delay, min(86400, (int) $error->retry_after()));
        } elseif ($error instanceof \RiTriever\Embedding\EmbeddingProviderException) {
            $delay = max($delay, min(86400, $error->retry_after));
        }
        $message = $stale ? "Post changed; retrying its current revision." : self::safe_error($error, $pending);
        Sql::query($wpdb->prepare("UPDATE %i SET status = %s, locked_by = '', locked_at = NULL, available_at = %s, last_error = %s, updated_at = UTC_TIMESTAMP() WHERE job_id = %d AND post_id = %d AND locked_by = %s AND status = 'processing' AND revision = claimed_revision", BackfillQueueSchema::items_table(), $pending ? "pending" : "failed", gmdate("Y-m-d H:i:s", time() + $delay), $message, $job_id, $post_id, $token));
        Sql::query($wpdb->prepare("UPDATE %i SET last_error = %s, phase = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status IN ('queued','running')", BackfillQueueSchema::jobs_table(), $message, $pending ? "backoff" : "embedding", $job_id));
        if (!$stale) {
            update_post_meta($post_id, RITRIEVER_POSTMETA_LAST_ERROR, $message);
        }
    }

    public static function is_retryable(\Throwable $error): bool
    {
        if (!($error instanceof \RuntimeException)) {
            return false;
        }
        if ($error instanceof \RiTriever\Embedding\EmbeddingProviderException) {
            return $error->retryable;
        }
        if (method_exists($error, "is_retryable")) {
            return (bool) $error->is_retryable();
        }
        $message = strtolower($error->getMessage());
        return $error instanceof StaleIndexWork || in_array((int) $error->getCode(), [408, 425, 429, 500, 502, 503, 504, 1205, 1213, 2006, 2013], true) ||
            preg_match('/429|503|502|504|500|timeout|timed out|connection|database|rate limit|temporar/i', $message) === 1;
    }

    private static function safe_error(\Throwable $error, bool $pending): string
    {
        $code = (int) $error->getCode();
        return ($pending ? "Temporary indexing failure; scheduled retry." : "Indexing failed or retry limit reached; retry after correcting the provider/database settings.") . ($code > 0 ? " Code: " . $code . "." : "");
    }

    private static function finish_or_continue(int $job_id): array
    {
        global $wpdb;
        $job = self::job_by_id($job_id);
        if ($job === null) {
            return self::idle_state();
        }
        $state = self::job_state($job);
        if (!in_array($job["status"], ["queued", "running"], true)) {
            return $state;
        }
        if ($job["index_generation"] !== IndexState::generation()) {
            self::transition($job_id, "cancelled", "Index generation changed.");
            return self::status();
        }
        $counts = $state["counts"];
        if (array_sum($counts) !== (int) $job["total_posts"]) {
            self::fail_job($job_id, "Queue item count mismatch; explicitly initialize again.");
            IndexState::fail("Queue item count mismatch; explicitly initialize again.");
            self::unschedule();
            return self::status();
        }
        if (($counts["pending"] ?? 0) + ($counts["processing"] ?? 0) > 0) {
            self::schedule_next_batch();
            return $state;
        }
        if (($counts["failed"] ?? 0) + ($counts["cancelled"] ?? 0) > 0) {
            foreach (Sql::rows($wpdb->prepare("SELECT post_id FROM %i WHERE job_id = %d AND status = 'failed'", BackfillQueueSchema::items_table(), $job_id)) as $row) {
                if (get_post_meta((int) $row["post_id"], RITRIEVER_POSTMETA_LAST_ERROR, true) === "") {
                    update_post_meta((int) $row["post_id"], RITRIEVER_POSTMETA_LAST_ERROR, "Indexing failed or its worker exhausted the retry limit.");
                }
            }
            self::fail_job($job_id, "Indexing failed; correct the cause and retry failed posts.");
            IndexState::fail("Indexing failed; correct the cause and retry failed posts.");
            self::unschedule();
            return self::status();
        }
        try {
            $repository = new LocalVectorRepository();
            $ids = array_map("intval", array_column(Sql::rows($wpdb->prepare("SELECT post_id FROM %i WHERE job_id = %d AND status = 'done'", BackfillQueueSchema::items_table(), $job_id)), "post_id"));
            foreach ($ids as $post_id) {
                PostSync::refresh_post($post_id);
                if (PostFilter::is_eligible($post_id) && !$repository->has_current($post_id, PostSync::content_hash($post_id), (string) $job["index_generation"])) {
                    self::put_item($job_id, $post_id);
                }
            }
            if ((int) Sql::value($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE job_id = %d AND status = 'pending'", BackfillQueueSchema::items_table(), $job_id)) > 0) {
                self::schedule_next_batch();
                return self::status();
            }
            VectorSchema::create_vector_index();
            $missing = [];
            foreach (self::eligible_post_ids() as $post_id) {
                PostSync::refresh_post($post_id);
                if (!$repository->has_current($post_id, PostSync::content_hash($post_id), (string) $job["index_generation"])) {
                    $missing[] = $post_id;
                    update_post_meta($post_id, RITRIEVER_POSTMETA_LAST_ERROR, "Current-generation index data is missing. Explicitly retry this post or initialize.");
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException("Current-generation coverage is incomplete; retry missing posts or initialize.");
            }
            $remaining_failures = self::remaining_failures();
            $completion_reason = $remaining_failures > 0 ? "Retry snapshot completed; other eligible posts still have indexing failures." : "";
            if ($remaining_failures > 0) {
                IndexState::fail($completion_reason);
            } else {
                IndexState::mark_ready((string) $job["index_generation"]);
            }
            self::transition($job_id, "complete", $completion_reason);
            Sql::query($wpdb->prepare("UPDATE %i SET completed_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'complete'", BackfillQueueSchema::jobs_table(), $job_id));
            if ($remaining_failures === 0) {
                Settings::mark_initial_backfill_complete(["processed" => count($ids), "errors" => 0, "completed_at" => time()]);
            }
            self::unschedule();
            SearchInterceptor::purge_query_cache();
        } catch (\RuntimeException $e) {
            self::fail_job($job_id, "Index/schema verification failed; explicitly retry initialization.");
            IndexState::fail("Index/schema verification failed; explicitly retry initialization.");
            self::unschedule();
            throw $e;
        }
        return self::status();
    }

    private static function new_job(string $generation, string $kind): int
    {
        global $wpdb;
        Sql::query($wpdb->prepare("INSERT INTO %i (status, phase, kind, index_generation, total_posts, created_at, updated_at, last_error) VALUES ('preparing', 'preparing', %s, %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), '')", BackfillQueueSchema::jobs_table(), $kind, $generation));
        return (int) Sql::value("SELECT LAST_INSERT_ID()");
    }

    private static function put_item(int $job_id, int $post_id): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare("INSERT INTO %i (job_id, post_id, status, attempts, revision, claimed_revision, available_at, locked_by, created_at, updated_at) VALUES (%d, %d, 'pending', 0, 1, 0, UTC_TIMESTAMP(), '', UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, revision = revision + 1, available_at = UTC_TIMESTAMP(), locked_by = '', locked_at = NULL, last_error = '', updated_at = UTC_TIMESTAMP()", BackfillQueueSchema::items_table(), $job_id, $post_id));
    }

    private static function item_count(int $job_id): int
    {
        global $wpdb;
        return (int) Sql::value($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE job_id = %d", BackfillQueueSchema::items_table(), $job_id));
    }

    private static function transition(int $job_id, string $status, string $error): void
    {
        global $wpdb;
        // All callers hold lifecycle; SQL status conditions also fence old workers.
        $allowed = $status === "queued" ? ["preparing", "paused", "failed", "complete", "queued", "running"] : ["preparing", "queued", "running", "paused"];
        $job = self::job_by_id($job_id);
        if ($job === null || !in_array($job["status"], $allowed, true)) {
            return;
        }
        Sql::query($wpdb->prepare("UPDATE %i SET status = %s, phase = %s, last_error = %s, updated_at = UTC_TIMESTAMP(), completed_at = NULL WHERE id = %d AND status = %s", BackfillQueueSchema::jobs_table(), $status, $status, $error, $job_id, $job["status"]));
    }

    private static function fail_job(int $job_id, string $error): void
    {
        self::transition($job_id, "failed", $error);
    }

    private static function latest_job(): ?array
    {
        global $wpdb;
        $rows = Sql::rows($wpdb->prepare("SELECT * FROM %i ORDER BY id DESC LIMIT 1", BackfillQueueSchema::jobs_table()));
        return $rows[0] ?? null;
    }

    private static function job_by_id(int $job_id): ?array
    {
        global $wpdb;
        $rows = Sql::rows($wpdb->prepare("SELECT * FROM %i WHERE id = %d", BackfillQueueSchema::jobs_table(), $job_id));
        return $rows[0] ?? null;
    }

    private static function job_state(array $job): array
    {
        global $wpdb;
        $id = (int) $job["id"];
        $counts = [];
        foreach (Sql::rows($wpdb->prepare("SELECT status, COUNT(*) AS count FROM %i WHERE job_id = %d GROUP BY status", BackfillQueueSchema::items_table(), $id)) as $row) {
            $counts[(string) $row["status"]] = (int) $row["count"];
        }
        $failed_ids = array_map("intval", array_column(Sql::rows($wpdb->prepare("SELECT post_id FROM %i WHERE job_id = %d AND status = 'failed' ORDER BY id LIMIT 200", BackfillQueueSchema::items_table(), $id)), "post_id"));
        $timing = Sql::rows($wpdb->prepare("SELECT MAX(attempts) AS attempts, MIN(CASE WHEN status = 'pending' THEN available_at END) AS next_attempt FROM %i WHERE job_id = %d", BackfillQueueSchema::items_table(), $id));
        $remaining_failures = self::remaining_failures();
        if ((string) ($job["index_generation"] ?? "") !== IndexState::generation()) {
            $job["status"] = "cancelled";
            $job["phase"] = "superseded";
            $job["last_error"] = "This job belongs to an obsolete generation; explicitly initialize the current settings.";
        } elseif ($job["status"] === "complete" && !IndexState::is_ready()) {
            if (($job["kind"] ?? "") === "retry" && $remaining_failures > 0) {
                $job["phase"] = "complete_with_remaining_failures";
                $job["last_error"] = "Retry snapshot completed; other eligible posts still have indexing failures.";
            } else {
                $job["status"] = "failed";
                $job["phase"] = "verification";
                $job["last_error"] = "Index generation/schema is not ready; explicitly initialize or retry.";
            }
        }
        return [
            "job_id" => $id, "status" => (string) $job["status"], "phase" => (string) $job["phase"], "ids" => [],
            "total" => (int) $job["total_posts"],
            "processed" => ($counts["done"] ?? 0) + ($counts["failed"] ?? 0) + ($counts["cancelled"] ?? 0),
            "errors" => $counts["failed"] ?? 0, "failed_ids" => $failed_ids, "counts" => $counts,
            "created_at" => self::timestamp($job["created_at"] ?? ""), "updated_at" => self::timestamp($job["updated_at"] ?? ""),
            "completed_at" => self::timestamp($job["completed_at"] ?? ""),
            "last_error" => (string) ($job["last_error"] ?? ""), "stop_reason" => (string) ($job["last_error"] ?? ""),
            "attempts" => (int) ($timing[0]["attempts"] ?? 0), "next_attempt" => self::timestamp($timing[0]["next_attempt"] ?? ""),
            "next_scheduled" => (int) wp_next_scheduled(self::CRON_HOOK),
            "remaining" => ($counts["pending"] ?? 0) + ($counts["processing"] ?? 0),
            "remaining_failures" => $remaining_failures,
            "next_run_at" => in_array($job["status"], ["queued", "running"], true)
                ? max((int) wp_next_scheduled(self::CRON_HOOK), self::timestamp($timing[0]["next_attempt"] ?? "")) : 0,
            "generation" => (string) ($job["index_generation"] ?? ""),
        ];
    }

    private static function idle_state(): array
    {
        return ["job_id" => 0, "status" => "idle", "phase" => "idle", "ids" => [], "total" => 0, "processed" => 0, "errors" => 0, "failed_ids" => [], "created_at" => 0, "updated_at" => 0, "completed_at" => 0, "last_error" => "", "stop_reason" => "", "counts" => [], "attempts" => 0, "next_attempt" => 0, "next_scheduled" => 0, "remaining" => 0, "remaining_failures" => 0, "next_run_at" => 0, "generation" => ""];
    }

    private static function remaining_failures(): int
    {
        global $wpdb;
        $rows = Sql::rows($wpdb->prepare("SELECT DISTINCT post_id FROM %i WHERE meta_key = %s AND meta_value <> ''", $wpdb->postmeta, RITRIEVER_POSTMETA_LAST_ERROR));
        return count(array_filter(array_map("intval", array_column($rows, "post_id")), static fn(int $id): bool => PostFilter::is_eligible($id)));
    }

    private static function normalize_ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map("intval", $ids), static fn(int $id): bool => $id > 0)));
    }

    public static function eligible_post_ids(): array
    {
        global $wpdb;
        $types = array_values((array) Settings::get("post_types"));
        $statuses = array_values((array) Settings::get("post_statuses"));
        if ($types === [] || $statuses === []) {
            return [];
        }
        $type_slots = implode(",", array_fill(0, count($types), "%s"));
        $status_slots = implode(",", array_fill(0, count($statuses), "%s"));
        $sql = "SELECT ID FROM %i WHERE post_type IN (" . $type_slots . ") AND post_status IN (" . $status_slots . ") AND post_password = '' ORDER BY ID";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Variable fragments contain only generated %s placeholders.
        $rows = Sql::rows($wpdb->prepare($sql, $wpdb->posts, ...array_merge($types, $statuses)));
        return array_values(array_filter(self::normalize_ids(array_column($rows, "ID")), static fn(int $id): bool => PostFilter::is_eligible($id)));
    }

    private static function timestamp($value): int
    {
        return is_string($value) && $value !== "" ? (int) strtotime($value . " UTC") : 0;
    }
}
