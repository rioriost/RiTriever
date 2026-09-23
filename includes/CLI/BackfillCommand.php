<?php
/**
 * WP-CLI commands.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\CLI;

use RiTriever\BackfillRunner;

final class BackfillCommand
{
    public static function register(): void
    {
        if (class_exists("\\WP_CLI")) {
            \WP_CLI::add_command("ritriever backfill", [
                self::class,
                "backfill",
            ]);
        }
    }

    /**
     * Manage the RiTriever background indexing queue.
     *
     * ## OPTIONS
     *
     * [--start]
     * : Rebuild the queue before processing.
     *
     * [--status]
     * : Print queue status without processing.
     *
     * [--batch-size=<n>]
     * : Posts to process per batch. Default 20.
     *
     * [--all]
     * : Keep processing until the queue is complete or failed.
     */
    public function backfill(array $args, array $assoc_args): void
    {
        try {
            $this->execute_backfill($args, $assoc_args);
        } catch (\RuntimeException $error) {
            \WP_CLI::error($error->getMessage());
        }
    }

    private function execute_backfill(array $args, array $assoc_args): void
    {
        if (!empty($assoc_args["start"])) {
            $state = BackfillRunner::create_queue();
            self::log_state("queue created", $state);
        } else {
            $state = BackfillRunner::status();
        }

        if (!empty($assoc_args["status"])) {
            self::log_state("queue status", $state);
            return;
        }

        if (!in_array((string) $state["status"], ["queued", "running"], true)) {
            self::log_state("queue not active", $state);
            return;
        }

        $batch_size = isset($assoc_args["batch-size"])
            ? (int) $assoc_args["batch-size"]
            : BackfillRunner::DEFAULT_BATCH_SIZE;
        do {
            $before = (int) $state["processed"];
            $state = BackfillRunner::process_batch($batch_size);
            self::log_state("batch processed", $state);
            if (
                !empty($assoc_args["all"]) &&
                (int) $state["processed"] === $before &&
                in_array((string) $state["status"], ["queued", "running"], true)
            ) {
                \WP_CLI::warning("No immediate progress: another worker owns the queue or retries are backing off. The WP-Cron watchdog will continue; use --status for the next attempt.");
                break;
            }
        } while (
            !empty($assoc_args["all"]) &&
            in_array((string) $state["status"], ["queued", "running"], true)
        );

        if ((string) $state["status"] === "complete") {
            \WP_CLI::success("backfill complete");
        } elseif ((string) $state["status"] === "failed") {
            \WP_CLI::error("backfill completed with errors");
        }
    }

    /** @param array<string,mixed> $state */
    private static function log_state(string $label, array $state): void
    {
        \WP_CLI::log(
            $label .
                ": status=" .
                (string) $state["status"] .
                ", processed=" .
                (int) $state["processed"] .
                "/" .
                (int) $state["total"] .
                ", errors=" .
                (int) $state["errors"] .
                ", attempts=" . (int) ($state["attempts"] ?? 0) .
                ", next_attempt=" . (int) ($state["next_attempt"] ?? 0) .
                ", next_scheduled=" . (int) ($state["next_scheduled"] ?? 0) .
                ", reason=" . (string) ($state["stop_reason"] ?? ""),
        );
    }
}
