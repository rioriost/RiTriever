<?php
/**
 * Settings schema and sanitization.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

final class Settings
{
    public const DEFAULTS = [
        "schema_version" => RITRIEVER_VERSION,
        "search_mode" => "off", // off|a_b_admin|full.
        "sync_enabled" => true,
        "kill_switch_global" => false,
        "target_locale" => "site",
        "embedding_provider" => "openai", // openai|azure_openai|ollama|infinity|tei|lmstudio|custom_http.
        "openai_api_key" => "",
        "openai_embedding_model" => "text-embedding-3-small",
        "custom_embedding_endpoint" => "",
        "custom_embedding_api_key" => "",
        "custom_embedding_model" => "",
        "custom_embedding_preset" => "custom",
        "custom_embedding_format" => "openai_compatible",
        "embedding_dimensions" => 1536,
        "chunk_max_chars" => 2400,
        "chunk_overlap_chars" => 250,
        "vector_distance" => "cosine", // cosine|euclidean.
        "vector_index_m" => 8,
        "top_k" => 50,
        "min_score" => 0.25,
        "cache_ttl_seconds" => 86400,
        "display_source_badges" => true,
        "japanese_normalization_enabled" => false,
        "post_types" => ["post"],
        "post_statuses" => ["publish"],
        "indexed_custom_fields" => [],
        "indexed_taxonomies" => [],
        "sync_excluded_post_ids" => [],
        "initial_backfill_completed_at" => 0,
        "initial_backfill_processed" => 0,
        "initial_backfill_errors" => 0,
        "initial_backfill_reset_reason" => "",
        "log_level" => "info",
    ];

    private const ENUMS = [
        "search_mode" => ["off", "a_b_admin", "full"],
        "embedding_provider" => [
            "openai",
            "azure_openai",
            "ollama",
            "infinity",
            "tei",
            "lmstudio",
            "custom_http",
        ],
        "custom_embedding_preset" => [
            "custom",
            "azure_openai_3_small",
            "azure_openai_3_large",
            "ollama_nomic",
            "ollama_mxbai",
            "infinity_bge_m3",
            "infinity_multilingual_e5_small",
            "tei_bge_m3",
            "lmstudio_nomic",
        ],
        "custom_embedding_format" => [
            "openai_compatible",
            "ollama",
            "azure_openai",
        ],
        "vector_distance" => ["cosine", "euclidean"],
        "log_level" => ["debug", "info", "warn", "error"],
    ];

    private static ?array $cache = null;
    private static ?int $cache_blog_id = null;

    private function __construct() {}

    public static function all(): array
    {
        $blog_id = function_exists("get_current_blog_id") ? get_current_blog_id() : 1;
        if (self::$cache !== null && self::$cache_blog_id === $blog_id) {
            return self::$cache;
        }
        $stored = get_option(RITRIEVER_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        self::$cache = self::sanitize($stored);
        self::$cache_blog_id = $blog_id;
        return self::$cache;
    }

    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function install_or_upgrade(): void
    {
        $current = get_option(RITRIEVER_OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }
        $current["schema_version"] = RITRIEVER_VERSION;
        update_option(
            RITRIEVER_OPTION_KEY,
            self::sanitize(array_replace(self::DEFAULTS, $current)),
            false,
        );
        self::$cache = null;
    }

    public static function sanitize(array $raw): array
    {
        $stored = get_option(RITRIEVER_OPTION_KEY, []);
        $base = array_replace(self::DEFAULTS, is_array($stored) ? $stored : []);
        $out = $base;
        foreach (self::DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $raw)
                ? $raw[$key]
                : $base[$key] ?? $default;
            if (isset(self::ENUMS[$key])) {
                $out[$key] = in_array((string) $value, self::ENUMS[$key], true)
                    ? (string) $value
                    : $default;
            } elseif (is_bool($default)) {
                $out[$key] = (bool) $value;
            } elseif (is_int($default)) {
                $out[$key] = max(0, (int) $value);
            } elseif (is_float($default)) {
                $out[$key] = max(0.0, min(1.0, (float) $value));
            } elseif (is_array($default)) {
                if (is_string($value)) {
                    $value = preg_split('/[\r\n,]+/', $value) ?: [];
                }
                $out[$key] = is_array($value)
                    ? array_values(
                        array_filter(
                            array_map(
                                static fn($v) => is_scalar($v)
                                    ? trim((string) $v)
                                    : "",
                                $value,
                            ),
                            static fn(string $v): bool => $v !== "",
                        ),
                    )
                    : $default;
            } else {
                $out[$key] = is_scalar($value)
                    ? trim((string) $value)
                    : $default;
            }
        }

        // Migrate the removed shadow mode to the safe disabled state.
        if ((string) ($raw["search_mode"] ?? "") === "shadow") {
            $out["search_mode"] = "off";
        }

        foreach (
            ["openai_api_key", "custom_embedding_api_key"]
            as $secret_key
        ) {
            if (
                array_key_exists($secret_key, $raw) &&
                trim((string) ($raw[$secret_key] ?? "")) === "" &&
                !empty($base[$secret_key])
            ) {
                $out[$secret_key] = (string) $base[$secret_key];
            }
        }

        $out["openai_embedding_model"] = self::normalize_openai_embedding_model(
            (string) $out["openai_embedding_model"],
        );
        if ($out["embedding_provider"] === "openai") {
            $out["embedding_dimensions"] = self::dimensions_for_openai_model(
                (string) $out["openai_embedding_model"],
            );
        } else {
            $presets = self::custom_embedding_presets();
            $preset_key = (string) $out["custom_embedding_preset"];
            if (
                !array_key_exists("custom_embedding_preset", $raw) &&
                $out["embedding_provider"] !== "custom_http" &&
                (
                    $out["embedding_provider"] !== $base["embedding_provider"] ||
                    !array_key_exists("custom_embedding_preset", (array) $stored)
                )
            ) {
                $preset_key = self::default_custom_preset_for_provider(
                    (string) $out["embedding_provider"],
                    $preset_key,
                );
                $out["custom_embedding_preset"] = $preset_key;
            }
            if ($preset_key !== "custom" && isset($presets[$preset_key])) {
                $preset = $presets[$preset_key];
                $explicit_selection =
                    $preset_key !== $base["custom_embedding_preset"] ||
                    (
                        $out["embedding_provider"] !== $base["embedding_provider"] &&
                        $preset["provider"] === $out["embedding_provider"]
                    );
                foreach ([
                    "custom_embedding_endpoint" => "endpoint",
                    "custom_embedding_model" => "model",
                    "custom_embedding_format" => "format",
                    "embedding_dimensions" => "dimensions",
                ] as $field => $preset_field) {
                    if (
                        !array_key_exists($field, $raw) &&
                        ($explicit_selection || !array_key_exists($field, (array) $stored))
                    ) {
                        $out[$field] = $preset[$preset_field];
                    }
                }
            }
            $out["embedding_dimensions"] = max(
                1,
                min(4096, (int) $out["embedding_dimensions"]),
            );
        }
        $out["target_locale"] = LanguageOptions::sanitize_locale(
            (string) $out["target_locale"],
        );
        $out["initial_backfill_completed_at"] = max(
            0,
            (int) $out["initial_backfill_completed_at"],
        );
        $out["initial_backfill_processed"] = max(
            0,
            (int) $out["initial_backfill_processed"],
        );
        $out["initial_backfill_errors"] = max(
            0,
            (int) $out["initial_backfill_errors"],
        );
        $out["vector_index_m"] = max(3, min(200, (int) $out["vector_index_m"]));
        $out["top_k"] = max(1, min(200, (int) $out["top_k"]));
        $out["schema_version"] = RITRIEVER_VERSION;

        return $out;
    }

    public static function should_intercept_search(
        bool $current_user_is_admin,
    ): bool {
        if ((bool) self::get("kill_switch_global")) {
            return false;
        }
        switch (self::get("search_mode")) {
            case "a_b_admin":
                return $current_user_is_admin;
            case "full":
                return true;
            case "off":
            default:
                return false;
        }
    }

    public static function mark_initial_backfill_complete(array $result): void
    {
        $current = self::all();
        $current["initial_backfill_completed_at"] = max(
            1,
            (int) ($result["completed_at"] ?? time()),
        );
        $current["initial_backfill_processed"] = max(
            0,
            (int) ($result["processed"] ?? 0),
        );
        $current["initial_backfill_errors"] = max(
            0,
            (int) ($result["errors"] ?? 0),
        );
        $current["initial_backfill_reset_reason"] = "";
        update_option(RITRIEVER_OPTION_KEY, self::sanitize($current), false);
        self::$cache = null;
    }

    public static function clear_initial_backfill_state(string $reason): void
    {
        $current = self::all();
        $current["initial_backfill_completed_at"] = 0;
        $current["initial_backfill_processed"] = 0;
        $current["initial_backfill_errors"] = 0;
        $current["initial_backfill_reset_reason"] = $reason;
        update_option(RITRIEVER_OPTION_KEY, self::sanitize($current), false);
        self::$cache = null;
    }

    /** @return array<string,array{provider:string,label:string,endpoint:string,model:string,dimensions:int,format:string}> */
    public static function custom_embedding_presets(): array
    {
        return [
            "azure_openai_3_small" => [
                "provider" => "azure_openai",
                "label" => "text-embedding-3-small deployment (1536)",
                "endpoint" =>
                    "https://YOUR-RESOURCE.openai.azure.com/openai/deployments/YOUR-DEPLOYMENT/embeddings?api-version=2024-02-01",
                "model" => "text-embedding-3-small",
                "dimensions" => 1536,
                "format" => "azure_openai",
            ],
            "azure_openai_3_large" => [
                "provider" => "azure_openai",
                "label" => "text-embedding-3-large deployment (3072)",
                "endpoint" =>
                    "https://YOUR-RESOURCE.openai.azure.com/openai/deployments/YOUR-DEPLOYMENT/embeddings?api-version=2024-02-01",
                "model" => "text-embedding-3-large",
                "dimensions" => 3072,
                "format" => "azure_openai",
            ],
            "ollama_nomic" => [
                "provider" => "ollama",
                "label" => "nomic-embed-text (768)",
                "endpoint" => "http://host.docker.internal:11434/api/embed",
                "model" => "nomic-embed-text",
                "dimensions" => 768,
                "format" => "ollama",
            ],
            "ollama_mxbai" => [
                "provider" => "ollama",
                "label" => "mxbai-embed-large (1024)",
                "endpoint" => "http://host.docker.internal:11434/api/embed",
                "model" => "mxbai-embed-large",
                "dimensions" => 1024,
                "format" => "ollama",
            ],
            "infinity_bge_m3" => [
                "provider" => "infinity",
                "label" => "BAAI/bge-m3 (1024)",
                "endpoint" => "http://host.docker.internal:7997/embeddings",
                "model" => "BAAI/bge-m3",
                "dimensions" => 1024,
                "format" => "openai_compatible",
            ],
            "infinity_multilingual_e5_small" => [
                "provider" => "infinity",
                "label" => "intfloat/multilingual-e5-small (384)",
                "endpoint" => "http://host.docker.internal:7997/embeddings",
                "model" => "intfloat/multilingual-e5-small",
                "dimensions" => 384,
                "format" => "openai_compatible",
            ],
            "tei_bge_m3" => [
                "provider" => "tei",
                "label" => "BAAI/bge-m3 (1024)",
                "endpoint" => "http://host.docker.internal:8080/v1/embeddings",
                "model" => "BAAI/bge-m3",
                "dimensions" => 1024,
                "format" => "openai_compatible",
            ],
            "lmstudio_nomic" => [
                "provider" => "lmstudio",
                "label" => "nomic-embed-text-v1.5 (768)",
                "endpoint" => "http://host.docker.internal:1234/v1/embeddings",
                "model" => "text-embedding-nomic-embed-text-v1.5",
                "dimensions" => 768,
                "format" => "openai_compatible",
            ],
        ];
    }

    public static function default_custom_preset_for_provider(
        string $provider,
        string $current = "custom",
    ): string {
        $presets = self::custom_embedding_presets();
        if (
            isset($presets[$current]) &&
            ($presets[$current]["provider"] ?? "") === $provider
        ) {
            return $current;
        }
        foreach ($presets as $key => $preset) {
            if (($preset["provider"] ?? "") === $provider) {
                return (string) $key;
            }
        }
        return "custom";
    }

    public static function dimensions_for_openai_model(string $model): int
    {
        return $model === "text-embedding-3-large" ? 3072 : 1536;
    }

    public static function normalize_openai_embedding_model(
        string $model,
    ): string {
        return $model === "text-embedding-3-large"
            ? "text-embedding-3-large"
            : "text-embedding-3-small";
    }

    public static function _flush_cache_for_tests(): void
    {
        self::clear_cache();
    }

    public static function clear_cache(): void
    {
        self::$cache = null;
        self::$cache_blog_id = null;
    }

    public static function reset_cache(): void
    {
        self::clear_cache();
        if (function_exists("wp_cache_delete")) {
            wp_cache_delete(RITRIEVER_OPTION_KEY, "options");
            wp_cache_delete("alloptions", "options");
            wp_cache_delete("notoptions", "options");
        }
    }

    public static function refresh(): void
    {
        self::reset_cache();
        self::all();
    }
}
