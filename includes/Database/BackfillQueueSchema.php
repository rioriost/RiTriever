<?php
/**
 * Durable, revision-fenced queue schema.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

final class BackfillQueueSchema
{
    private static array $installed = [];

    public static function jobs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_backfill_jobs";
    }

    public static function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_backfill_items";
    }

    public static function install_or_upgrade(): void
    {
        if (isset(self::$installed[self::jobs_table()])) {
            return;
        }
        DatabaseLock::with("queue-schema", static function (): void {
            self::install_schema();
        });
    }

    private static function install_schema(): void
    {
        global $wpdb;
        $jobs = self::jobs_table();
        if (isset(self::$installed[$jobs])) {
            return;
        }
        $charset = " " . (string) preg_replace("/[^a-zA-Z0-9_ =-]/", "", $wpdb->get_charset_collate());
        Sql::query($wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT," .
            "status VARCHAR(20) NOT NULL DEFAULT 'preparing'," .
            "phase VARCHAR(40) NOT NULL DEFAULT 'preparing'," .
            "kind VARCHAR(16) NOT NULL DEFAULT 'initial'," .
            "index_generation VARCHAR(64) NOT NULL DEFAULT ''," .
            "total_posts BIGINT UNSIGNED NOT NULL DEFAULT 0," .
            "created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL," .
            "completed_at DATETIME NULL DEFAULT NULL, last_error TEXT NULL," .
            "PRIMARY KEY (id), KEY status_updated (status, updated_at)" .
            ") ENGINE=InnoDB",
            $jobs,
        ) . $charset);
        Sql::query($wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT," .
            "job_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL," .
            "status VARCHAR(20) NOT NULL DEFAULT 'pending'," .
            "attempts INT UNSIGNED NOT NULL DEFAULT 0," .
            "revision BIGINT UNSIGNED NOT NULL DEFAULT 1," .
            "claimed_revision BIGINT UNSIGNED NOT NULL DEFAULT 0," .
            "available_at DATETIME NULL DEFAULT NULL," .
            "locked_at DATETIME NULL DEFAULT NULL, locked_by VARCHAR(64) NOT NULL DEFAULT ''," .
            "last_error TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL," .
            "PRIMARY KEY (id), UNIQUE KEY job_post (job_id, post_id)," .
            "KEY job_status_id (job_id, status, id), KEY job_lock (job_id, locked_by)" .
            ") ENGINE=InnoDB",
            self::items_table(),
        ) . $charset);
        $job_columns = array_column(Sql::rows($wpdb->prepare("SHOW COLUMNS FROM %i", $jobs)), "Field");
        if (!in_array("kind", $job_columns, true)) {
            Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'initial'", $jobs));
        }
        if (!in_array("index_generation", $job_columns, true)) {
            Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN index_generation VARCHAR(64) NOT NULL DEFAULT ''", $jobs));
        }
        $item_columns = array_column(Sql::rows($wpdb->prepare("SHOW COLUMNS FROM %i", self::items_table())), "Field");
        if (!in_array("revision", $item_columns, true)) {
            Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1", self::items_table()));
        }
        if (!in_array("claimed_revision", $item_columns, true)) {
            Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN claimed_revision BIGINT UNSIGNED NOT NULL DEFAULT 0", self::items_table()));
        }
        if (!in_array("available_at", $item_columns, true)) {
            Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN available_at DATETIME NULL DEFAULT NULL", self::items_table()));
        }
        // Never infer success for legacy jobs whose generation was not recorded.
        Sql::query($wpdb->prepare("UPDATE %i SET status = 'cancelled', phase = 'migration', last_error = 'Legacy job requires explicit initialization.' WHERE index_generation = '' AND status IN ('queued','running','paused','preparing','complete')", $jobs));
        self::$installed[$jobs] = true;
    }

    public static function drop(): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare("DROP TABLE IF EXISTS %i", self::items_table()));
        Sql::query($wpdb->prepare("DROP TABLE IF EXISTS %i", self::jobs_table()));
        unset(self::$installed[self::jobs_table()]);
    }
}
