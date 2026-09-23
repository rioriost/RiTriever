<?php
/**
 * Index coverage and failure diagnostics.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics read custom vector/index metadata on demand; these values are volatile and not useful to cache.

use RiTriever\Database\VectorSchema;
use RiTriever\Database\LocalVectorRepository;
use RiTriever\Database\Sql;

final class IndexDiagnostics
{
    private function __construct() {}

    /** @return array{eligible_posts:int,indexed_posts:int,chunk_count:int,coverage_percent:float,failed_count:int,queue_status:string,queue_processed:int,queue_total:int,queue_errors:int,failed_posts:array<int,array{post_id:int,title:string,status:string,error:string,edit_url:string}>} */
    public static function summary(int $failed_limit = 20): array
    {
        try {
            return self::checked_summary($failed_limit);
        } catch (\RuntimeException $e) {
            Logger::warn("diagnostics", "Index diagnostics failed; coverage is unknown.", [
                "code" => (int) $e->getCode(),
            ]);
            return [
                "eligible_posts" => 0, "indexed_posts" => 0, "chunk_count" => 0,
                "coverage_percent" => 0.0, "failed_count" => 0, "queue_status" => "unavailable",
                "queue_processed" => 0, "queue_total" => 0, "queue_errors" => 0, "failed_posts" => [],
                "ready" => false, "diagnostic_error" => "Index diagnostics failed. Check the database and explicitly initialize or retry; coverage is unknown.",
            ];
        }
    }

    private static function checked_summary(int $failed_limit): array
    {
        $eligible_ids = self::eligible_post_ids();
        $indexed_eligible = 0;
        $ready = IndexState::is_ready();
        if (IndexState::is_writable()) {
            $repository = new LocalVectorRepository();
            foreach ($eligible_ids as $post_id) {
                PostSync::refresh_post($post_id);
                if ($repository->has_current($post_id, PostSync::content_hash($post_id), IndexState::generation())) {
                    ++$indexed_eligible;
                }
            }
        }

        $eligible_count = count($eligible_ids);
        $queue = BackfillRunner::status();

        return [
            "eligible_posts" => $eligible_count,
            "indexed_posts" => $indexed_eligible,
            "chunk_count" => self::chunk_count(),
            "coverage_percent" =>
                $eligible_count > 0
                    ? round(($indexed_eligible / $eligible_count) * 100, 1)
                    : 0.0,
            "failed_count" => self::failed_count(),
            "queue_status" => (string) $queue["status"],
            "queue_processed" => (int) $queue["processed"],
            "queue_total" => (int) $queue["total"],
            "queue_errors" => (int) $queue["errors"],
            "failed_posts" => self::failed_posts($failed_limit),
            "ready" => $ready,
            "diagnostic_error" => $ready ? "" : ((string) (IndexState::state()["reason"] ?? "") ?: "Index requires explicit initialization or schema repair."),
        ];
    }

    /** @return int[] */
    private static function eligible_post_ids(): array
    {
        return BackfillRunner::eligible_post_ids();
    }

    private static function chunk_count(): int
    {
        global $wpdb;
        if (!self::vector_table_exists()) {
            return 0;
        }
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) Sql::value(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE index_generation = %s", $table, IndexState::generation()),
        );
    }

    private static function failed_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) Sql::value(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value <> ''",
                $wpdb->postmeta,
                RITRIEVER_POSTMETA_LAST_ERROR,
            ),
        );
    }

    /** @return int[] */
    public static function failed_post_ids(int $limit = 200): array
    {
        global $wpdb;
        $limit = max(1, min(1000, $limit));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = Sql::rows(
            $wpdb->prepare(
                "SELECT post_id FROM %i WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id DESC LIMIT %d",
                $wpdb->postmeta,
                RITRIEVER_POSTMETA_LAST_ERROR,
                $limit,
            ),
        );
        return array_values(array_map("intval", array_column($rows, "post_id")));
    }

    /** @return array<int,array{post_id:int,title:string,status:string,error:string,edit_url:string}> */
    private static function failed_posts(int $limit): array
    {
        global $wpdb;
        $limit = max(1, min(100, $limit));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = Sql::rows(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM %i WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id DESC LIMIT %d",
                $wpdb->postmeta,
                RITRIEVER_POSTMETA_LAST_ERROR,
                $limit,
            ),
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $post_id = (int) ($row["post_id"] ?? 0);
            $post = get_post($post_id);
            $out[] = [
                "post_id" => $post_id,
                "title" =>
                    $post instanceof \WP_Post ? get_the_title($post) : "",
                "status" =>
                    $post instanceof \WP_Post
                        ? (string) $post->post_status
                        : "",
                "error" => is_scalar($row["meta_value"] ?? "")
                    ? (string) $row["meta_value"]
                    : "",
                "edit_url" => get_edit_post_link($post_id, "raw") ?: "",
            ];
        }
        return $out;
    }

    private static function vector_table_exists(): bool
    {
        global $wpdb;
        $table = VectorSchema::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (string) Sql::value(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table),
        ) === $table;
    }
}
