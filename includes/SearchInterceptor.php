<?php
/**
 * Hybrid RAG + core search rewrite.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Provider\LocalVectorProvider;
use RiTriever\Database\DatabaseLock;
use RiTriever\Database\Sql;

final class SearchInterceptor
{
    private const CACHE_VERSION = "4";
    public const CACHE_INDEX_OPTION = "ritriever_query_cache_keys";
    public const CACHE_GENERATION_OPTION = "ritriever_query_cache_generation";
    public const LIVE_CACHE_INDEX_OPTION = "ritriever_live_query_keys";
    private const LEGACY_LIVE_CACHE_INDEX_OPTION = "ritriever_live_query_transient_keys";
    private const CACHE_MAX_ENTRIES = 100;
    private static ?\WeakMap $processed_queries = null;
    /** @var array<int, string> */
    private static array $hit_sources = [];

    private function __construct() {}

    public static function register(): void
    {
        // Capture the effective context after themes/plugins set standard query constraints.
        add_action("pre_get_posts", [self::class, "on_pre_get_posts"], PHP_INT_MAX);
        add_filter("posts_search", [self::class, "on_posts_search"], 10, 2);
        add_filter("the_title", [self::class, "on_the_title"], 10, 2);
        add_action("switch_blog", [self::class, "reset_runtime_state"]);
    }

    public static function on_pre_get_posts($query): void
    {
        if (
            !($query instanceof \WP_Query) ||
            !$query->is_main_query() ||
            is_admin() ||
            !$query->is_search() ||
            $query->get("suppress_filters")
        ) {
            return;
        }
        self::$processed_queries ??= new \WeakMap();
        if (isset(self::$processed_queries[$query])) {
            return;
        }
        self::$processed_queries[$query] = "skipped";
        self::$hit_sources = [];
        $search = $query->get("s");
        $user_query = is_string($search) ? trim($search) : "";
        if (
            $user_query === "" ||
            Settings::get("kill_switch_global") ||
            !Settings::should_intercept_search(
                current_user_can((string) RITRIEVER_ADMIN_CAPABILITY),
            ) ||
            !IndexState::is_ready() ||
            !self::supports_query($query, $user_query)
        ) {
            return;
        }

        // Keep the unmodified context and generation across API calls and SQL.
        $source_query = clone $query;
        $generation = self::cache_generation();
        if ($generation === "") {
            return;
        }
        try {
            $key = self::cache_key($user_query, $source_query, $generation);
        } catch (\JsonException $e) {
            return;
        }
        $cacheable = !is_user_logged_in();
        $cached = $cacheable ? self::cache_lookup($key) : null;
        if ($cached !== null) {
            $eligible = self::filter_ids_by_query_context(
                PostFilter::filter_eligible_ids($cached["ids"]),
                $source_query,
            );
            if ($eligible === null || !self::generation_is_current($generation)) {
                return;
            }
            if ($eligible !== [] || $cached["ids"] === []) {
                self::remember_hit_sources($eligible, $cached["sources"]);
                self::rewrite_query($query, $eligible);
                self::$processed_queries[$query] = "rewritten";
                return;
            }
        }

        $rag = (new LocalVectorProvider())->retrieve(
            TextNormalizer::vector_query($user_query),
        );
        if (!$rag->ok) {
            return;
        }
        $rag_ids = self::filter_ids_by_query_context(
            PostFilter::filter_eligible_ids($rag->post_ids()),
            $source_query,
        );
        $native_ids = self::core_search_ids($source_query, $user_query);
        if ($rag_ids === null || $native_ids === null) {
            return;
        }
        $core_ids = PostFilter::filter_eligible_ids(
            $native_ids,
        );
        $combined = self::merge_ranked_ids($rag_ids, $core_ids);
        if (!self::generation_is_current($generation)) {
            return;
        }
        $sources = self::build_source_map($combined, $rag_ids, $core_ids);
        self::remember_hit_sources($combined, $sources);
        self::rewrite_query($query, $combined);
        self::$processed_queries[$query] = "rewritten";
        if ($cacheable) {
            self::cache_store($key, $combined, $sources, $generation);
        }
    }

    public static function on_posts_search($search, $query)
    {
        if (
            $query instanceof \WP_Query &&
            (self::$processed_queries[$query] ?? "") ===
                "rewritten"
        ) {
            return "";
        }
        return $search;
    }

    public static function on_the_title($title, $post_id = 0): string
    {
        $title = (string) $title;
        if (
            !(bool) Settings::get("display_source_badges") ||
            !Settings::should_intercept_search(
                current_user_can((string) RITRIEVER_ADMIN_CAPABILITY),
            ) ||
            Settings::get("kill_switch_global") ||
            $title === "" ||
            str_contains($title, "ritriever-hit-badges")
        ) {
            return $title;
        }
        $source = self::source_for_render_post((int) $post_id);
        return $source === null
            ? $title
            : self::badge_html($source) . esc_html($title);
    }

    private static function core_search_ids(
        \WP_Query $source_query,
        string $user_query,
    ): ?array {
        global $wpdb;
        $args = is_array($source_query->query_vars)
            ? $source_query->query_vars
            : [];
        unset(
            $args["paged"],
            $args["offset"],
            $args["fields"],
            $args["no_found_rows"],
        );
        $args["fields"] = "ids";
        $args["posts_per_page"] = (int) Settings::get("top_k");
        $args["posts_per_archive_page"] = $args["posts_per_page"];
        $args["showposts"] = 0;
        $args["nopaging"] = false;
        $args["paged"] = 1;
        $args["no_found_rows"] = true;
        $args["suppress_filters"] = false;
        $out = [];
        foreach (
            TextNormalizer::lexical_query_variants($user_query)
            as $variant
        ) {
            $args["s"] = $variant;
            $q = new \WP_Query($args);
            if ($wpdb->last_error !== "" || !is_array($q->posts)) {
                return null;
            }
            foreach (array_map("intval", $q->posts) as $post_id) {
                if (!in_array($post_id, $out, true)) {
                    $out[] = $post_id;
                }
            }
        }
        return array_slice(array_values(array_diff(
            $out,
            array_map("intval", (array) $source_query->get("post__not_in")),
        )), 0, (int) Settings::get("top_k"));
    }

    private static function merge_ranked_ids(
        array $rag_ids,
        array $core_ids,
    ): array {
        $k = 60.0;
        $items = [];
        foreach (["rag" => $rag_ids, "core" => $core_ids] as $ids) {
            foreach ($ids as $i => $id) {
                $id = (int) $id;
                $items[$id] ??= [
                    "id" => $id,
                    "score" => 0.0,
                    "best" => PHP_INT_MAX,
                ];
                $rank = $i + 1;
                $items[$id]["score"] += 1.0 / ($k + $rank);
                $items[$id]["best"] = min($items[$id]["best"], $rank);
            }
        }
        usort(
            $items,
            static fn(array $a, array $b): int => $b["score"] <=> $a["score"] ?:
            $a["best"] <=> $b["best"],
        );
        return array_values(
            array_map(static fn(array $item): int => (int) $item["id"], $items),
        );
    }

    private static function filter_ids_by_query_context(
        array $post_ids,
        \WP_Query $source_query,
    ): ?array {
        global $wpdb;
        $post_ids = array_values(array_unique(array_map("intval", $post_ids)));
        if ($post_ids === []) {
            return [];
        }

        $args = $source_query->query_vars;
        if (!empty($args["post__in"])) {
            $post_ids = array_values(array_intersect(
                $post_ids,
                array_map("intval", (array) $args["post__in"]),
            ));
        }
        $post_ids = array_values(array_diff(
            $post_ids,
            array_map("intval", (array) $source_query->get("post__not_in")),
        ));
        if ($post_ids === []) {
            return [];
        }
        unset($args["paged"], $args["offset"]);
        // Removing `s` must not turn the default searchable types into only posts.
        if (empty($args["post_type"])) {
            $args["post_type"] = "any";
        }
        $args = array_merge($args, [
            "s" => "",
            "post__in" => $post_ids,
            "fields" => "ids",
            "posts_per_page" => count($post_ids),
            "posts_per_archive_page" => count($post_ids),
            "showposts" => 0,
            "nopaging" => false,
            "orderby" => "post__in",
            "no_found_rows" => true,
            "ignore_sticky_posts" => true,
            "suppress_filters" => false,
        ]);
        $q = new \WP_Query($args);
        if ($wpdb->last_error !== "" || !is_array($q->posts)) {
            return null;
        }
        return array_values(array_intersect($post_ids, array_map("intval", $q->posts)));
    }

    private static function build_source_map(
        array $combined,
        array $rag_ids,
        array $core_ids,
    ): array {
        $rag = array_fill_keys(array_map("intval", $rag_ids), true);
        $core = array_fill_keys(array_map("intval", $core_ids), true);
        $out = [];
        foreach ($combined as $id) {
            $id = (int) $id;
            $out[$id] = isset($rag[$id], $core[$id])
                ? "both"
                : (isset($rag[$id])
                    ? "rag"
                    : "core");
        }
        return $out;
    }

    private static function remember_hit_sources(
        array $ids,
        array $sources,
    ): void {
        self::$hit_sources = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($sources[$id])) {
                self::$hit_sources[$id] = (string) $sources[$id];
            }
        }
    }

    private static function rewrite_query(
        \WP_Query $query,
        array $post_ids,
    ): void {
        $query->set("post__in", $post_ids === [] ? [0] : $post_ids);
        $query->set("orderby", "post__in");
        $query->set("order", "ASC");
        $query->set("ignore_sticky_posts", true);
    }

    private static function source_for_render_post(int $post_id): ?string
    {
        if ($post_id <= 0 && function_exists("get_the_ID")) {
            $post_id = (int) get_the_ID();
        }
        if (
            $post_id <= 0 ||
            !isset(self::$hit_sources[$post_id]) ||
            is_admin()
        ) {
            return null;
        }
        if (function_exists("is_search") && !is_search()) {
            return null;
        }
        if (function_exists("in_the_loop") && !in_the_loop()) {
            return null;
        }
        return self::$hit_sources[$post_id];
    }

    private static function badge_html(string $source): string
    {
        $labels = [];
        if ($source === "rag" || $source === "both") {
            $labels[] = "RAG";
        }
        if ($source === "core" || $source === "both") {
            $labels[] = self::standard_search_label();
        }
        $html =
            '<span class="ritriever-hit-badges" style="display:block;font-size:.72em;font-weight:400;line-height:1.4;margin:0 0 .15em;color:#666;">';
        foreach ($labels as $label) {
            $html .=
                '<span style="display:inline-block;margin-right:.35em;">[' .
                esc_html($label) .
                "]</span>";
        }
        return $html . "</span>";
    }

    private static function standard_search_label(): string
    {
        $english = "Standard search";
        $translated = get_translations_for_domain("ritriever")->translate(
            $english,
        );
        if (
            $translated === $english &&
            str_starts_with(strtolower((string) get_locale()), "ja")
        ) {
            return "標準検索";
        }
        return $translated;
    }

    private static function cache_key(
        string $query,
        \WP_Query $source_query,
        string $generation,
    ): string {
        $settings = [];
        foreach ([
            "top_k", "min_score", "japanese_normalization_enabled",
        ] as $name) {
            $settings[$name] = Settings::get($name);
        }
        return "ritriever_q_" .
            substr(
                hash(
                    "sha256",
                    wp_json_encode([
                        self::CACHE_VERSION, get_current_blog_id(), get_locale(),
                        $generation, IndexState::fingerprint(), $query,
                        $source_query->query_vars, $settings,
                    ], JSON_THROW_ON_ERROR),
                ),
                0,
                32,
            );
    }

    private static function cache_lookup(string $key): ?array
    {
        $raw = get_transient($key);
        if (
            !is_array($raw) ||
            !isset($raw["ids"], $raw["sources"]) ||
            !is_array($raw["ids"]) ||
            !is_array($raw["sources"])
        ) {
            return null;
        }
        foreach ($raw["ids"] as $id) {
            if (!is_int($id) || $id < 1 || !in_array($raw["sources"][$id] ?? null, ["rag", "core", "both"], true)) {
                return null;
            }
        }
        return $raw;
    }

    private static function cache_store(
        string $key,
        array $ids,
        array $sources,
        string $generation,
    ): void {
        try {
            DatabaseLock::with("query-cache", static function () use ($key, $ids, $sources, $generation): void {
                if (!self::generation_is_current($generation)) {
                    return;
                }
                self::register_cache_key($key);
                set_transient(
                    $key,
                    ["ids" => $ids, "sources" => $sources],
                    max(1, (int) Settings::get("cache_ttl_seconds")),
                );
                if (!self::generation_is_current($generation)) {
                    delete_transient($key);
                }
            });
        } catch (\RuntimeException $e) {
            // Caching is optional; never leave an untracked value after a failed write.
            delete_transient($key);
        }
    }

    public static function remember_cache_key(string $key): void
    {
        if (!self::is_owned_cache_key($key)) {
            throw new \InvalidArgumentException("Only RiTriever query transient keys may be registered.");
        }
        try {
            DatabaseLock::with("query-cache", static function () use ($key): void {
                self::register_cache_key($key);
            });
        } catch (\RuntimeException $e) {
            delete_transient($key);
        }
    }

    public static function store_live_query_result(
        string $key,
        array $payload,
        int $ttl,
    ): bool {
        if (preg_match('/^ritriever_live_query_[0-9]+$/D', $key) !== 1 || $ttl < 1) {
            throw new \InvalidArgumentException("A live query key and positive expiration are required.");
        }
        try {
            return DatabaseLock::with("query-cache", static function () use ($key, $payload, $ttl): bool {
                self::register_cache_key($key, self::LIVE_CACHE_INDEX_OPTION, time() + $ttl);
                $stored = set_transient($key, $payload, $ttl) ||
                    get_transient($key) === $payload;
                if (!$stored) {
                    delete_transient($key);
                }
                return $stored;
            });
        } catch (\RuntimeException $e) {
            delete_transient($key);
            return false;
        }
    }

    private static function register_cache_key(
        string $key,
        string $option = self::CACHE_INDEX_OPTION,
        ?int $expires_at = null,
    ): void
    {
        $raw = self::read_cache_option($option);
        $index = is_array($raw) ? array_filter(
            $raw,
            static fn($name): bool => is_string($name) && self::is_owned_cache_key($name),
            ARRAY_FILTER_USE_KEY,
        ) : [];
        if ($expires_at !== null) {
            foreach ($index as $old_key => $expiry) {
                if (!is_numeric($expiry) || (float) $expiry <= time()) {
                    delete_transient((string) $old_key);
                    unset($index[$old_key]);
                }
            }
        }
        // Always retain the incoming key, even when it has the shortest lifetime.
        unset($index[$key]);
        arsort($index);
        $index = [$key => $expires_at ?? microtime(true)] + $index;
        $kept = array_slice($index, 0, self::CACHE_MAX_ENTRIES, true);
        foreach (array_diff(array_keys($index), array_keys($kept)) as $stale) {
            delete_transient((string) $stale);
        }
        self::write_cache_option($option, $kept);
    }

    private static function cache_generation(): string
    {
        try {
            $generation = self::read_cache_option(self::CACHE_GENERATION_OPTION);
            if (!is_string($generation) || $generation === "") {
                $generation = DatabaseLock::with("query-cache", static function (): string {
                    $current = self::read_cache_option(self::CACHE_GENERATION_OPTION);
                    if (is_string($current) && $current !== "") {
                        return $current;
                    }
                    $current = wp_generate_uuid4();
                    self::write_cache_option(self::CACHE_GENERATION_OPTION, $current);
                    return $current;
                });
            }
            return $generation . ":" . IndexState::generation();
        } catch (\RuntimeException $e) {
            return "";
        }
    }

    private static function read_cache_option(string $name)
    {
        global $wpdb;
        // Read through the DB: another worker's purge cannot refresh our local option cache.
        $raw = Sql::value($wpdb->prepare("SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, $name));
        return is_string($raw) ? maybe_unserialize($raw) : null;
    }

    private static function write_cache_option(string $name, $value): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare(
            "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $wpdb->options,
            $name,
            maybe_serialize($value),
        ));
        wp_cache_delete($name, "options");
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
    }

    private static function generation_is_current(string $generation): bool
    {
        return $generation !== "" &&
            $generation === self::cache_generation() &&
            !Settings::get("kill_switch_global") &&
            IndexState::is_ready();
    }

    private static function is_owned_cache_key(string $key): bool
    {
        return preg_match('/^ritriever_(?:q_[a-zA-Z0-9_]+|live_query_[0-9]+)$/D', $key) === 1;
    }

    private static function supports_query(\WP_Query $query, string $search): bool
    {
        // Phrase, exclusion, exact and punctuation grammars remain entirely native.
        if (
            preg_match('/^[\p{L}\p{N}_\s]+$/uD', $search) !== 1 ||
            count(preg_split('/\s+/u', $search) ?: []) > 9 ||
            $query->get("sentence") ||
            $query->get("exact") ||
            $query->get("embed") ||
            !empty($query->get("search_columns")) ||
            !in_array($query->get("orderby"), ["", null, false, "relevance"], true) ||
            !in_array($query->get("fields"), ["", null, false, "all"], true) ||
            !in_array($query->get("post_status"), ["", null, false, "publish", ["publish"]], true)
        ) {
            return false;
        }
        $supported = [
            "s", "sentence", "exact", "search_columns", "title", "embed", "error", "m", "p",
            "post_parent", "subpost", "subpost_id", "attachment", "attachment_id",
            "name", "pagename", "page_id", "second", "minute", "hour", "day",
            "monthnum", "year", "w", "category_name", "tag", "cat", "tag_id",
            "author", "author_name", "feed", "tb", "paged", "meta_key",
            "meta_value", "preview", "suppress_filters", "cache_results",
            "update_post_term_cache", "update_menu_item_cache", "lazy_load_term_meta",
            "update_post_meta_cache", "post_type", "posts_per_page",
            "posts_per_archive_page", "nopaging", "comments_per_page", "no_found_rows",
            "order", "orderby", "fields", "post_status", "ignore_sticky_posts",
            "offset", "page", "cpage", "showposts", "perm", "has_password",
            "post_password", "post_mime_type", "comment_count", "menu_order",
            "post__in", "post__not_in", "post_name__in", "post_parent__in",
            "post_parent__not_in", "author__in", "author__not_in",
            "category__in", "category__not_in", "category__and", "tag__in",
            "tag__not_in", "tag__and", "tag_slug__in", "tag_slug__and",
            "taxonomy", "term", "tax_query", "date_query", "meta_query",
            "meta_compare", "meta_type", "meta_compare_key", "meta_type_key",
        ];
        foreach ($query->query_vars as $name => $value) {
            if (!in_array($name, $supported, true) || !self::is_context_value($value)) {
                return false;
            }
        }
        return !self::has_custom_query_filters();
    }

    private static function is_context_value($value, int $depth = 0): bool
    {
        if ($depth > 20) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (
                    (is_string($key) && preg_match("//u", $key) !== 1) ||
                    !self::is_context_value($item, $depth + 1)
                ) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || is_bool($value) || is_int($value) ||
            (is_float($value) && is_finite($value)) ||
            (is_string($value) && preg_match("//u", $value) === 1);
    }

    private static function has_custom_query_filters(): bool
    {
        global $wp_filter, $wpdb;
        foreach ($wp_filter as $name => $hook) {
            if (
                !str_starts_with($name, "posts_") &&
                !str_starts_with($name, "found_posts") &&
                !in_array($name, [
                    "all", "pre_get_posts", "parse_query", "the_posts",
                    "split_the_query", "post_search_columns",
                    "wp_query_search_exclusion_prefix", "wp_search_stopwords",
                    "get_meta_sql", "get_tax_sql", "query", "pre_get_terms",
                    "get_terms_args", "terms_pre_query", "terms_clauses", "get_terms",
                ], true)
            ) {
                continue;
            }
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback["function"];
                    if (
                        ($name === "pre_get_posts" && $function === [self::class, "on_pre_get_posts"]) ||
                        ($name === "posts_search" && $function === [self::class, "on_posts_search"])
                    ) {
                        continue;
                    }
                    // Earlier pre_get_posts callbacks have already run on the main query.
                    // Their resulting constraints are cloned and checked for every candidate.
                    if ($name === "pre_get_posts" && (int) $priority < PHP_INT_MAX) {
                        continue;
                    }
                    // These core callbacks format placeholders, labels or comment status, not membership.
                    if (
                        ($name === "query" && $function === [$wpdb, "remove_placeholder_escape"]) ||
                        ($name === "get_terms" && $function === "_post_format_get_terms") ||
                        ($name === "the_posts" && $function === "_close_comments_for_old_posts")
                    ) {
                        continue;
                    }
                    return true;
                }
            }
        }
        return false;
    }

    public static function reset_runtime_state(): void
    {
        self::$processed_queries = null;
        self::$hit_sources = [];
    }

    public static function purge_query_cache(): int
    {
        return DatabaseLock::with("query-cache", static fn(): int => self::purge_locked());
    }

    private static function purge_locked(): int
    {
        global $wpdb;

        self::write_cache_option(self::CACHE_GENERATION_OPTION, wp_generate_uuid4());
        $registered = self::read_cache_option(self::CACHE_INDEX_OPTION);
        $keys = is_array($registered) ? array_keys($registered) : [];
        foreach ([self::LIVE_CACHE_INDEX_OPTION, self::LEGACY_LIVE_CACHE_INDEX_OPTION] as $option) {
            $live = self::read_cache_option($option);
            if (is_array($live)) {
                $keys = array_merge($keys, array_keys($live));
            }
        }

        // Legacy database-backed entries may predate the registry.
        $option_rows = Sql::rows(
            $wpdb->prepare(
                "SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->options,
                $wpdb->esc_like("_transient_ritriever_q_") . "%",
                $wpdb->esc_like("_transient_timeout_ritriever_q_") . "%",
                $wpdb->esc_like("_transient_ritriever_live_query_") . "%",
                $wpdb->esc_like("_transient_timeout_ritriever_live_query_") . "%",
            ),
        );

        foreach ($option_rows as $row) {
            $option_name = (string) $row["option_name"];
            if (str_starts_with($option_name, "_transient_timeout_")) {
                $keys[] = substr($option_name, strlen("_transient_timeout_"));
            } elseif (str_starts_with($option_name, "_transient_")) {
                $keys[] = substr($option_name, strlen("_transient_"));
            }
        }

        $keys = array_values(
            array_unique(
                array_filter(
                    $keys,
                    static fn($key): bool => is_string($key) && self::is_owned_cache_key($key),
                ),
            ),
        );
        foreach ($keys as $key) {
            delete_transient($key);
        }
        delete_option(self::CACHE_INDEX_OPTION);
        delete_option(self::LIVE_CACHE_INDEX_OPTION);
        delete_option(self::LEGACY_LIVE_CACHE_INDEX_OPTION);

        return count($keys);
    }
}
