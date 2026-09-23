<?php
/**
 * Verified native-vector schema and durable per-post storage receipts.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

use RiTriever\Settings;

final class VectorSchema
{
    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_chunks";
    }

    public static function state_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . "ritriever_indexed_posts";
    }

    public static function recreate(): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare("DROP TABLE IF EXISTS %i", self::table_name()));
        Sql::query($wpdb->prepare("DROP TABLE IF EXISTS %i", self::state_table()));
        self::install_or_upgrade();
        if (!self::is_ready()) {
            throw new \RuntimeException("Vector schema verification failed after initialization.");
        }
    }

    public static function drop_vector_index(): void
    {
        global $wpdb;
        if (self::has_vector_index()) {
            Sql::query($wpdb->prepare("ALTER TABLE %i DROP INDEX embedding", self::table_name()));
        }
    }

    public static function create_vector_index(): void
    {
        global $wpdb;
        self::require_capabilities();
        if (!self::has_vector_index()) {
            $distance = Settings::get("vector_distance") === "euclidean" ? "euclidean" : "cosine";
            $sql = $distance === "euclidean"
                ? $wpdb->prepare("CREATE VECTOR INDEX embedding ON %i (embedding) M=%d DISTANCE=euclidean", self::table_name(), (int) Settings::get("vector_index_m"))
                : $wpdb->prepare("CREATE VECTOR INDEX embedding ON %i (embedding) M=%d DISTANCE=cosine", self::table_name(), (int) Settings::get("vector_index_m"));
            Sql::query($sql);
        }
        if (!self::is_ready()) {
            throw new \RuntimeException("Vector index verification failed; initialize the index again.");
        }
    }

    public static function has_vector_index(): bool
    {
        global $wpdb;
        $rows = Sql::rows($wpdb->prepare("SHOW INDEX FROM %i WHERE Key_name = %s", self::table_name(), "embedding"));
        foreach ($rows as $row) {
            if (strtoupper((string) ($row["Index_type"] ?? "")) === "VECTOR") {
                return true;
            }
        }
        return false;
    }

    public static function is_ready(): bool
    {
        global $wpdb;
        $rows = Sql::rows($wpdb->prepare("SHOW CREATE TABLE %i", self::table_name()));
        $ddl = (string) ($rows[0]["Create Table"] ?? "");
        $dimension = (int) Settings::get("embedding_dimensions");
        $distance = Settings::get("vector_distance") === "euclidean" ? "euclidean" : "cosine";
        $m = (int) Settings::get("vector_index_m");
        if (!preg_match('/`embedding`\s+vector\(' . $dimension . '\)/i', $ddl) ||
            !str_contains($ddl, "`index_generation`") ||
            !preg_match('/VECTOR\s+(?:KEY|INDEX)\s+`embedding`[^\r\n]*[ \t]`?DISTANCE`?\s*=\s*[\'"]?' . $distance . '[\'"]?(?=[\s,]|$)/i', $ddl) ||
            !preg_match('/VECTOR\s+(?:KEY|INDEX)\s+`embedding`[^\r\n]*[ \t]`?M`?\s*=\s*[\'"]?' . $m . '[\'"]?(?=[\s,]|$)/i', $ddl) ||
            !preg_match('/ENGINE\s*=\s*InnoDB/i', $ddl)) {
            return false;
        }
        $receipts = Sql::rows($wpdb->prepare("SHOW CREATE TABLE %i", self::state_table()));
        $receipt_ddl = (string) ($receipts[0]["Create Table"] ?? "");
        if (!str_contains($receipt_ddl, "`index_generation`") || !preg_match('/ENGINE\s*=\s*InnoDB/i', $receipt_ddl)) {
            return false;
        }
        $transactional = (int) Sql::value($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB' AND TABLE_NAME IN (%s,%s,%s,%s,%s,%s)",
            $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->options,
        ));
        return $transactional === 6 && self::has_vector_index();
    }

    public static function install_or_upgrade(): void
    {
        global $wpdb;
        self::require_capabilities();
        $distance = Settings::get("vector_distance") === "euclidean" ? "euclidean" : "cosine";
        $vector_index = $distance === "euclidean"
            ? $wpdb->prepare("VECTOR INDEX embedding (embedding) M=%d DISTANCE=euclidean", (int) Settings::get("vector_index_m"))
            : $wpdb->prepare("VECTOR INDEX embedding (embedding) M=%d DISTANCE=cosine", (int) Settings::get("vector_index_m"));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed, allowlisted index syntax is interpolated.
        $sql = $wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
            "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT," .
            "post_id BIGINT UNSIGNED NOT NULL," .
            "chunk_index INT UNSIGNED NOT NULL," .
            "chunk_text LONGTEXT NOT NULL," .
            "content_hash CHAR(64) NOT NULL," .
            "index_generation VARCHAR(64) NOT NULL DEFAULT ''," .
            "embedding_model VARCHAR(191) NOT NULL," .
            "embedding VECTOR(%d) NOT NULL," .
            "updated_at DATETIME NOT NULL," .
            "PRIMARY KEY (id)," .
            "UNIQUE KEY post_chunk_model (post_id, chunk_index, embedding_model)," .
            "KEY post_lookup (post_id)," .
            "KEY model_lookup (embedding_model)," .
            $vector_index . ") ENGINE=InnoDB", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Separately prepared index fragment with fixed cosine/euclidean literals.
            self::table_name(),
            (int) Settings::get("embedding_dimensions"),
        );
        Sql::query($sql . self::charset());
        // Legacy chunks deliberately remain untrusted until explicit initialization.
        Sql::query($wpdb->prepare("ALTER TABLE %i ADD COLUMN IF NOT EXISTS index_generation VARCHAR(64) NOT NULL DEFAULT ''", self::table_name()));
        Sql::query($wpdb->prepare(
            "CREATE TABLE IF NOT EXISTS %i (" .
            "post_id BIGINT UNSIGNED NOT NULL," .
            "index_generation VARCHAR(64) NOT NULL," .
            "content_hash CHAR(64) NOT NULL," .
            "embedding_model VARCHAR(191) NOT NULL," .
            "chunk_count INT UNSIGNED NOT NULL," .
            "PRIMARY KEY (post_id)" .
            ") ENGINE=InnoDB",
            self::state_table(),
        ) . self::charset());
    }

    private static function require_capabilities(): void
    {
        $cap = VectorCapabilities::detect();
        if ($cap["family"] !== "mariadb" || !$cap["native_vector"] || !$cap["vector_index"]) {
            throw new \RuntimeException("MariaDB native VECTOR and VECTOR INDEX support is required.");
        }
    }

    private static function charset(): string
    {
        global $wpdb;
        return " " . (string) preg_replace("/[^a-zA-Z0-9_ =-]/", "", $wpdb->get_charset_collate());
    }
}
