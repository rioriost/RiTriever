<?php
/**
 * One source of truth for index identity, generation, and readiness.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Database\DatabaseLock;
use RiTriever\Database\Sql;
use RiTriever\Database\VectorSchema;

final class IndexState
{
    public const OPTION_KEY = "ritriever_index_state";
    public const CHUNK_VERSION = "unicode-codepoints-v2";
    public const CONTEXT_VERSION = LanguageOptions::CONTEXT_VERSION;
    public const GENERATION_META = "_ritriever_index_generation";

    public static function fingerprint(?array $settings = null): string
    {
        $settings = $settings ?? self::current_settings();
        $keys = [
            "embedding_provider", "openai_embedding_model", "custom_embedding_model",
            "custom_embedding_format", "embedding_dimensions", "chunk_max_chars",
            "chunk_overlap_chars", "vector_distance", "vector_index_m", "post_types",
            "post_statuses", "sync_excluded_post_ids", "indexed_custom_fields", "indexed_taxonomies",
        ];
        $identity = [];
        foreach ($keys as $key) {
            $value = $settings[$key] ?? null;
            if (is_array($value)) {
                sort($value);
            }
            $identity[$key] = $value;
        }
        // Authentication material must not become part of the persisted identity.
        $endpoint = (string) ($settings["custom_embedding_endpoint"] ?? "");
        $parts = wp_parse_url($endpoint);
        $endpoint_identity = is_array($parts) ? [
            "scheme" => strtolower((string) ($parts["scheme"] ?? "")),
            "host" => strtolower((string) ($parts["host"] ?? "")),
            "port" => $parts["port"] ?? null,
            "path" => $parts["path"] ?? "",
        ] : [];
        $query = [];
        parse_str((string) ($parts["query"] ?? ""), $query);
        foreach ($query as $key => $value) {
            if (preg_match('/key|token|secret|password|signature|credential|auth/i', (string) $key)) {
                unset($query[$key]);
            }
        }
        ksort($query);
        $endpoint_identity["query"] = $query;
        $identity["endpoint"] = ($settings["embedding_provider"] ?? "") === "openai" ? "openai" : $endpoint_identity;
        $locale = (string) ($settings["target_locale"] ?? "site");
        $identity["locale"] = $locale === "site" || $locale === "" ? get_locale() : $locale;
        $identity["chunk_version"] = self::CHUNK_VERSION;
        $identity["context_version"] = self::CONTEXT_VERSION;
        return hash("sha256", (string) wp_json_encode($identity));
    }

    /** Read the database, never a worker's stale option cache. */
    public static function state(): array
    {
        global $wpdb;
        $raw = Sql::value($wpdb->prepare("SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, self::OPTION_KEY));
        $state = is_string($raw) ? maybe_unserialize($raw) : [];
        return is_array($state) ? $state : [];
    }

    public static function generation(): string
    {
        return (string) (self::state()["generation"] ?? "");
    }

    public static function ensure_current(): void
    {
        $state = self::state();
        if (empty($state["generation"]) || ($state["fingerprint"] ?? "") !== self::fingerprint()) {
            self::invalidate("The index identity changed or predates generation tracking. Run explicit initialization.");
        }
    }

    public static function is_writable(): bool
    {
        $state = self::state();
        $settings = self::current_settings();
        return !($settings["kill_switch_global"] ?? false) &&
            in_array($state["status"] ?? "", ["building", "ready"], true) &&
            ($state["fingerprint"] ?? "") === self::fingerprint($settings) &&
            ($state["fingerprint"] ?? "") === self::fingerprint(Settings::all());
    }

    public static function can_sync(): bool
    {
        $settings = self::current_settings();
        return !empty($settings["sync_enabled"]) && empty($settings["kill_switch_global"]);
    }

    /** Do not authorize a commit using an option value cached before HTTP I/O. */
    private static function current_settings(): array
    {
        global $wpdb;
        $raw = Sql::value($wpdb->prepare("SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, RITRIEVER_OPTION_KEY));
        $stored = is_string($raw) ? maybe_unserialize($raw) : [];
        return is_array($stored) ? array_replace(Settings::all(), $stored) : Settings::all();
    }

    public static function is_ready(): bool
    {
        try {
            return (self::state()["status"] ?? "") === "ready" &&
                self::is_writable() && VectorSchema::is_ready();
        } catch (\RuntimeException $e) {
            Logger::warn("index_state", "Index readiness verification failed; standard search will be used.", [
                "code" => (int) $e->getCode(),
            ]);
            return false;
        }
    }

    public static function invalidate(string $reason): void
    {
        DatabaseLock::with("lifecycle", static function () use ($reason): void {
            self::write([
                "generation" => bin2hex(random_bytes(16)),
                "fingerprint" => self::fingerprint(),
                "status" => "invalid",
                "reason" => $reason,
            ]);
            BackfillRunner::stop_for_invalidation($reason);
            Settings::clear_initial_backfill_state($reason);
            SearchInterceptor::purge_query_cache();
        });
    }

    /** Caller holds lifecycle lock; no destructive changes occur on settings save. */
    public static function begin_build(): string
    {
        $generation = bin2hex(random_bytes(16));
        self::write(["generation" => $generation, "fingerprint" => self::fingerprint(), "status" => "building", "reason" => "Initialization in progress."]);
        Settings::clear_initial_backfill_state("Initialization in progress.");
        SearchInterceptor::purge_query_cache();
        return $generation;
    }

    public static function mark_ready(string $generation): void
    {
        $state = self::state();
        if (($state["generation"] ?? "") !== $generation || !self::is_writable() || !VectorSchema::is_ready()) {
            throw new \RuntimeException("Index generation or schema is not ready.");
        }
        $state["status"] = "ready";
        $state["reason"] = "";
        self::write($state);
    }

    public static function fail(string $reason): void
    {
        $state = self::state();
        $state["status"] = "failed";
        $state["reason"] = $reason;
        self::write($state);
        Settings::clear_initial_backfill_state($reason);
    }

    public static function allow_retry(): void
    {
        $state = self::state();
        if (($state["fingerprint"] ?? "") !== self::fingerprint() || empty($state["generation"]) || ($state["status"] ?? "") === "invalid") {
            throw new \RuntimeException("Settings changed; explicitly initialize the index before retrying.");
        }
        $state["status"] = "building";
        self::write($state);
    }

    private static function write(array $state): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare("INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'", $wpdb->options, self::OPTION_KEY, maybe_serialize($state)));
        wp_cache_delete(self::OPTION_KEY, "options");
        wp_cache_delete("alloptions", "options");
    }
}
