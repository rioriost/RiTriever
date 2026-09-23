<?php
/**
 * Standalone search, provider, and persistent-cache regressions.
 * Run: php tests/search-contract.php
 */
declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput -- CLI assertions only; no browser output or external input.

namespace RiTriever {
    final class Settings
    {
        public static array $values = [];
        public static function get(string $key) { return self::$values[$key] ?? null; }
        public static function should_intercept_search(bool $admin): bool { return self::get("search_mode") === "full"; }
    }
    final class IndexState
    {
        public static bool $ready = true;
        public static string $generation = "index-1";
        public static string $fingerprint = "configuration-1";
        public static function is_ready(): bool { return self::$ready; }
        public static function generation(): string { return self::$generation; }
        public static function fingerprint(): string { return self::$fingerprint; }
    }
    final class LanguageOptions
    {
        public static function with_embedding_context(string $text): string { return $text; }
    }
}

namespace RiTriever\Embedding {
    final class EmbeddingProviderFactory
    {
        public static function make(): object
        {
            return new class {
                public function embed(string $text): array
                {
                    ++$GLOBALS["embedding_calls"];
                    if (isset($GLOBALS["on_embed"])) {
                        ($GLOBALS["on_embed"])();
                    }
                    if (isset($GLOBALS["embedding_error"])) {
                        throw $GLOBALS["embedding_error"];
                    }
                    return $GLOBALS["embedding"] ?? [1.0, 0.5];
                }
                public function model(): string { return "test-model"; }
            };
        }
    }
}

namespace RiTriever\Database {
    final class LocalVectorRepository
    {
        public function search_with_chunks(array $embedding, string $model, int $limit): array
        {
            if (isset($GLOBALS["repository_error"])) {
                throw $GLOBALS["repository_error"];
            }
            return array_slice($GLOBALS["vector_hits"], 0, $limit, true);
        }
    }
}

namespace {
    use RiTriever\IndexState;
    use RiTriever\SearchInterceptor;
    use RiTriever\Settings;
    use RiTriever\Provider\LocalVectorProvider;

    const RITRIEVER_ADMIN_CAPABILITY = "manage_options";
    const ARRAY_A = "ARRAY_A";

    final class SearchTestDatabase
    {
        public string $last_error = "";
        public string $options = "wp_options";
        public string $prefix = "wp_";
        public bool $fail_reads = false;
        public function prepare(string $sql, ...$args): string { return json_encode([$sql, $args], JSON_THROW_ON_ERROR); }
        public function esc_like(string $value): string { return addcslashes($value, "_%\\"); }
        private function decode(string $sql): array { return str_starts_with($sql, "[") ? json_decode($sql, true) : [$sql, []]; }
        public function query(string $sql): int|false
        {
            [$statement, $args] = $this->decode($sql);
            $this->last_error = "";
            if (str_starts_with($statement, "INSERT INTO")) {
                $GLOBALS["options"][get_current_blog_id()][$args[1]] = maybe_unserialize($args[2]);
                return 1;
            }
            throw new \LogicException("Unexpected test write: " . $statement);
        }
        public function get_results(string $sql, string $format): ?array
        {
            [$statement, $args] = $this->decode($sql);
            $this->last_error = "";
            if ($this->fail_reads) {
                $this->last_error = "injected read failure";
                return null;
            }
            if (str_starts_with($statement, "SELECT option_value")) {
                $value = $GLOBALS["options"][get_current_blog_id()][$args[1]] ?? null;
                return $value === null ? [] : [["option_value" => maybe_serialize($value)]];
            }
            if (str_starts_with($statement, "SELECT option_name")) {
                return array_map(static fn(string $name): array => ["option_name" => $name], $this->get_col($sql));
            }
            if (preg_match('/SELECT (?:CONNECTION_ID|GET_LOCK|IS_USED_LOCK|RELEASE_LOCK)\(/', $statement)) {
                return [["value" => 1]];
            }
            throw new \LogicException("Unexpected test read: " . $statement);
        }
        public function get_col(string $sql): array
        {
            $names = [];
            if (!$GLOBALS["external_cache"]) {
                foreach (array_keys($GLOBALS["transients"][get_current_blog_id()] ?? []) as $key) {
                    $names[] = "_transient_" . $key;
                    $names[] = "_transient_timeout_" . $key;
                }
            }
            return $names;
        }
    }

    final class WP_Post
    {
        public string $post_password = "";
        public string $post_status = "publish";
        public function __construct(
            public int $ID,
            public string $post_title,
            public int $post_author = 1,
            public string $post_type = "post",
            public string $color = "red",
            public int $year = 2026,
            public string $category = "fruit",
        ) {}
    }

    final class WP_Query
    {
        public array $query_vars = [];
        public array $posts = [];
        public int $found_posts = 0;
        public int $max_num_pages = 0;
        public bool $main = false;
        public function __construct(array $args = [])
        {
            $this->query_vars = $args;
            if ($args !== []) {
                $this->execute();
            }
        }
        public function get(string $key) { return $this->query_vars[$key] ?? ""; }
        public function set(string $key, $value): void { $this->query_vars[$key] = $value; }
        public function is_main_query(): bool { return $this->main; }
        public function is_search(): bool { return $this->get("s") !== ""; }
        public function execute(bool $remove_search = false): void
        {
            global $wpdb;
            $wpdb->last_error = "";
            if (!$this->main && ($GLOBALS["core_sql_error"] ?? false)) {
                $wpdb->last_error = "injected core SQL failure";
                $this->posts = [];
                return;
            }
            $out = [];
            foreach ($GLOBALS["posts"] as $id => $post) {
                if ($this->get("post__in") && !in_array($id, $this->get("post__in"), true)) { continue; }
                if (in_array($id, (array) $this->get("post__not_in"), true)) { continue; }
                if ($this->get("author") && $post->post_author !== (int) $this->get("author")) { continue; }
                if ($this->get("author__in") && !in_array($post->post_author, $this->get("author__in"), true)) { continue; }
                $type = $this->get("post_type") ?: ($this->get("s") !== "" ? "any" : "post");
                if ($type !== "any" && !in_array($post->post_type, (array) $type, true)) { continue; }
                if ($this->get("meta_key") === "color" && $post->color !== $this->get("meta_value")) { continue; }
                if ($this->get("meta_query") && $post->color !== $this->get("meta_query")[0]["value"]) { continue; }
                if ($this->get("year") && $post->year !== (int) $this->get("year")) { continue; }
                if ($this->get("date_query") && $post->year !== (int) $this->get("date_query")[0]["year"]) { continue; }
                if ($this->get("tax_query") && !in_array($post->category, $this->get("tax_query")[0]["terms"], true)) { continue; }
                if (!$remove_search && $this->get("s") !== "") {
                    $terms = preg_split('/\s+/', strtolower((string) $this->get("s"))) ?: [];
                    foreach ($terms as $term) {
                        if (in_array($term, ["the", "a", "of"], true)) { continue; }
                        $negative = str_starts_with($term, "-");
                        $term = trim($term, '"-');
                        $contains = str_contains(strtolower($post->post_title), $term);
                        if (($negative && $contains) || (!$negative && !$contains)) { continue 2; }
                    }
                }
                $out[] = $id;
            }
            if ($this->get("orderby") === "post__in") {
                $out = array_values(array_intersect($this->get("post__in"), $out));
            }
            $this->found_posts = count($out);
            $per_page = max(1, (int) ($this->get("posts_per_page") ?: 10));
            $this->max_num_pages = (int) ceil(count($out) / $per_page);
            $offset = $this->get("offset") !== "" ? (int) $this->get("offset") : (max(1, (int) $this->get("paged")) - 1) * $per_page;
            $this->posts = array_slice($out, $offset, $per_page);
        }
    }

    function is_admin(): bool { return $GLOBALS["admin"] ?? false; }
    function current_user_can(string $capability): bool { return false; }
    function is_user_logged_in(): bool { return $GLOBALS["logged_in"] ?? false; }
    function is_search(): bool { return $GLOBALS["search_page"] ?? true; }
    function in_the_loop(): bool { return $GLOBALS["in_loop"] ?? true; }
    function get_current_blog_id(): int { return $GLOBALS["blog"] ?? 1; }
    function get_locale(): string { return $GLOBALS["locale"] ?? "en_US"; }
    function get_post($post): ?WP_Post { return $post instanceof WP_Post ? $post : ($GLOBALS["posts"][$post] ?? null); }
    function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags | JSON_THROW_ON_ERROR); }
    function maybe_serialize($value): string { return is_array($value) ? serialize($value) : (string) $value; }
    function maybe_unserialize(string $value) { return str_starts_with($value, "a:") ? unserialize($value, ["allowed_classes" => false]) : $value; }
    function get_option(string $key, $default = false) { return $GLOBALS["options"][get_current_blog_id()][$key] ?? $default; }
    function delete_option(string $key): bool { unset($GLOBALS["options"][get_current_blog_id()][$key]); return true; }
    function wp_cache_delete(string $key, string $group): bool { return true; }
    function get_transient(string $key) { return $GLOBALS["transients"][get_current_blog_id()][$key] ?? false; }
    function set_transient(string $key, $value, int $ttl): bool
    {
        if ($GLOBALS["fail_cache_write"] ?? false) { return false; }
        $GLOBALS["transients"][get_current_blog_id()][$key] = $value;
        if (isset($GLOBALS["on_cache_write"])) { ($GLOBALS["on_cache_write"])(); }
        return true;
    }
    function delete_transient(string $key): bool { unset($GLOBALS["transients"][get_current_blog_id()][$key]); return true; }
    function wp_generate_uuid4(): string { static $id = 0; return "generation-" . ++$id; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, "UTF-8"); }
    function get_translations_for_domain(string $domain): object { return new class { public function translate(string $text): string { return $text; } }; }

    foreach ([
        "Database/Sql", "Database/DatabaseLock",
        "Embedding/EmbeddingProviderException", "Embedding/EmbeddingResponseValidator",
        "Provider/ResultHit", "Provider/RetrieveResult", "Provider/LocalVectorProvider",
        "PostFilter", "TextNormalizer", "SearchInterceptor",
    ] as $file) {
        require_once dirname(__DIR__) . "/includes/" . $file . ".php";
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
        ++$GLOBALS["assertions"];
    }
    function reset_fixture(): void
    {
        foreach (["embedding_error", "repository_error", "on_embed", "on_cache_write", "core_sql_error", "embedding", "fail_cache_write"] as $name) { unset($GLOBALS[$name]); }
        SearchInterceptor::reset_runtime_state();
        Settings::$values = [
            "search_mode" => "full", "kill_switch_global" => false,
            "post_types" => ["post", "page"], "post_statuses" => ["publish"],
            "sync_excluded_post_ids" => [], "top_k" => 3, "min_score" => 0.0,
            "cache_ttl_seconds" => 3600, "embedding_dimensions" => 2,
            "vector_distance" => "cosine", "display_source_badges" => true,
        ];
        IndexState::$ready = true;
        IndexState::$generation = "index-1";
        IndexState::$fingerprint = "configuration-1";
        $GLOBALS["wpdb"] = new SearchTestDatabase();
        $GLOBALS["wp_filter"] = [];
        $GLOBALS["blog"] = 1;
        $GLOBALS["locale"] = "en_US";
        $GLOBALS["admin"] = false;
        $GLOBALS["logged_in"] = false;
        $GLOBALS["search_page"] = true;
        $GLOBALS["in_loop"] = true;
        $GLOBALS["embedding_calls"] = 0;
        $GLOBALS["options"] = [];
        $GLOBALS["transients"] = [];
        $GLOBALS["external_cache"] = true;
        $GLOBALS["posts"] = [
            1 => new WP_Post(1, "apple banana", 1, "post", "red", 2026, "fruit"),
            2 => new WP_Post(2, "pineapple", 2, "page", "blue", 2025, "fruit"),
            3 => new WP_Post(3, "apple apricot", 1, "post", "blue", 2024, "news"),
            4 => new WP_Post(4, "semantic pear", 2, "post", "green", 2026, "news"),
        ];
        $GLOBALS["vector_hits"] = [
            4 => ["score" => 0.9, "chunk_text" => "pear"],
            2 => ["score" => 0.8, "chunk_text" => "pineapple"],
            1 => ["score" => 0.7, "chunk_text" => "apple"],
        ];
    }
    function main_query(array $args = []): WP_Query
    {
        $query = new WP_Query();
        $query->main = true;
        $query->query_vars = array_merge(["s" => "apple", "posts_per_page" => 10], $args);
        return $query;
    }
    function run_query(array $args = []): WP_Query
    {
        $query = main_query($args);
        SearchInterceptor::on_pre_get_posts($query);
        $query->execute(SearchInterceptor::on_posts_search("native-search", $query) === "");
        return $query;
    }
    function assert_native(array $args, string $label): void
    {
        $query = main_query($args);
        $original = $query->query_vars;
        $native = clone $query;
        $native->execute();
        SearchInterceptor::on_pre_get_posts($query);
        check($query->query_vars === $original, $label . ": original arguments changed");
        check(SearchInterceptor::on_posts_search("native-search", $query) === "native-search", $label . ": native SQL removed");
        $query->execute();
        check([$query->posts, $query->found_posts, $query->max_num_pages] === [$native->posts, $native->found_posts, $native->max_num_pages], $label . ": native pagination changed");
    }

    $GLOBALS["assertions"] = 0;
    reset_fixture();
    $result = run_query(["post__in" => [2], "paged" => 1, "no_found_rows" => true]);
    check($result->get("post__in") === [2], "post__in was broadened");
    check($result->get("no_found_rows") === true && $result->get("paged") === 1, "pagination flags were overwritten");
    check($result->posts === [2], "native substring match was rejected");
    $GLOBALS["posts"][2]->post_title = "semantic blueberry";
    SearchInterceptor::purge_query_cache();
    check(in_array(2, run_query()->posts, true), "default search types excluded semantic-only page candidates");

    $contexts = [
        ["author" => 1], ["author" => 2], ["post_type" => "post"], ["post_type" => "page"],
        ["meta_key" => "color", "meta_value" => "red"],
        ["meta_query" => [["key" => "color", "value" => "blue"]]],
        ["date_query" => [["year" => 2026]]],
        ["tax_query" => [["taxonomy" => "category", "terms" => ["news"]]]],
        ["post__in" => [1, 2, 3], "post__not_in" => [1, 3]],
        ["author" => 1, "post_type" => "post", "meta_query" => [["key" => "color", "value" => "blue"]], "date_query" => [["year" => 2024]], "tax_query" => [["taxonomy" => "category", "terms" => ["news"]]]],
    ];
    foreach ([false, true] as $reverse) {
        reset_fixture();
        foreach ($reverse ? array_reverse($contexts) : $contexts as $context) {
            $query = run_query($context);
            $baseline = new WP_Query(array_merge(["post_type" => "any"], $context, ["s" => "", "posts_per_page" => 100]));
            check(array_diff($query->get("post__in"), $baseline->posts) === [], "candidate escaped original query constraints");
            $cached = run_query($context);
            check($query->posts === $cached->posts, "warm cache changed constrained results");
        }
        check($GLOBALS["embedding_calls"] === count($contexts), "distinct contexts collided or cache was stored after rewrite");
    }

    reset_fixture();
    foreach ([
        ["s" => "apple -banana"], ["s" => '"apple banana"'], ["sentence" => true],
        ["exact" => true], ["search_columns" => ["post_title"]], ["orderby" => "title"],
        ["post_status" => "private"], ["custom_query_var" => "tenant-1"], ["embed" => true],
        ["s" => "one two three four five six seven eight nine ten"],
    ] as $args) {
        assert_native($args, "unsupported grammar/context");
    }
    $invalid_context = main_query(["meta_query" => [new \stdClass()]]);
    $original = $invalid_context->query_vars;
    SearchInterceptor::on_pre_get_posts($invalid_context);
    check($invalid_context->query_vars === $original, "object context was rewritten");
    check($GLOBALS["embedding_calls"] === 0, "unsupported query reached embedding API");
    $query = run_query(["s" => "the apple"]);
    check(in_array(2, $query->posts, true), "stopword/substrings were independently reinterpreted");

    foreach (["posts_where", "posts_clauses", "post_search_columns", "wp_search_stopwords", "the_posts", "all"] as $hook) {
        reset_fixture();
        $GLOBALS["wp_filter"][$hook] = (object) ["callbacks" => [10 => [["function" => static fn() => null]]]];
        assert_native([], "custom filter " . $hook);
        check($GLOBALS["embedding_calls"] === 0, "custom filters still used shared retrieval");
    }
    reset_fixture();
    $GLOBALS["posts"] = [1 => new WP_Post(1, "ミツバチの飼育")];
    $GLOBALS["vector_hits"] = [1 => ["score" => 0.9, "chunk_text" => "ミツバチの飼育"]];
    $GLOBALS["wp_filter"]["pre_get_posts"] = (object) ["callbacks" => [10 => [
        ["function" => static fn() => null],
    ]]];
    $native = new WP_Query(["s" => "蜜蜂"]);
    check($native->posts === [], "synonym fixture must have no literal match");
    $synonym = run_query(["s" => "蜜蜂"]);
    check($synonym->posts === [1], "an earlier unrelated query hook suppressed semantic-only Japanese hits");
    check($GLOBALS["embedding_calls"] === 1 && $synonym->get("orderby") === "post__in", "synonym query must perform actual retrieval and rewrite");
    check(run_query(["s" => "蜜蜂"])->posts === [1] && $GLOBALS["embedding_calls"] === 1, "warm synonym cache lost semantic hits");
    check(run_query(["s" => "蜜蜂", "post__not_in" => [1]])->posts === [], "effective theme exclusion must still constrain semantic candidates");
    $GLOBALS["wp_filter"]["pre_get_posts"]->callbacks[PHP_INT_MAX][] = ["function" => static fn() => null];
    assert_native(["s" => "蜜蜂"], "query callback at or after our final context boundary");

    reset_fixture();
    foreach (["pre_get_posts" => "on_pre_get_posts", "posts_search" => "on_posts_search"] as $hook => $method) {
        $GLOBALS["wp_filter"][$hook] = (object) ["callbacks" => [10 => [["function" => [SearchInterceptor::class, $method]]]]];
    }
    run_query();
    check($GLOBALS["embedding_calls"] === 1, "own hooks incorrectly disabled hybrid search");
    $GLOBALS["wp_filter"]["query"] = (object) ["callbacks" => [0 => [["function" => [$GLOBALS["wpdb"], "remove_placeholder_escape"]]]]];
    $GLOBALS["wp_filter"]["get_terms"] = (object) ["callbacks" => [10 => [["function" => "_post_format_get_terms"]]]];
    $GLOBALS["wp_filter"]["the_posts"] = (object) ["callbacks" => [10 => [["function" => "_close_comments_for_old_posts"]]]];
    $core_defaults_query = run_query(["title" => "", "embed" => ""]);
    check($core_defaults_query->get("orderby") === "post__in", "WordPress core query defaults or built-in filters disabled hybrid search");
    $GLOBALS["wp_filter"]["query"]->callbacks[10][] = ["function" => static fn($sql) => $sql];
    assert_native([], "additional query filter alongside core placeholder filter");

    foreach (["embedding", "repository", "core", "cache-generation"] as $failure) {
        reset_fixture();
        for ($id = 1; $id <= 100; ++$id) { $GLOBALS["posts"][$id] = new WP_Post($id, "apple"); }
        if ($failure === "embedding") { $GLOBALS["embedding_error"] = new \RuntimeException("injected API failure"); }
        if ($failure === "repository") { $GLOBALS["repository_error"] = new \RuntimeException("injected DB failure"); }
        if ($failure === "core") { $GLOBALS["core_sql_error"] = true; }
        if ($failure === "cache-generation") { $GLOBALS["wpdb"]->fail_reads = true; }
        assert_native(["paged" => 7, "posts_per_page" => 10], $failure . " failure");
        check(($GLOBALS["transients"][1] ?? []) === [], "failure poisoned normal cache");
        unset($GLOBALS["embedding_error"], $GLOBALS["repository_error"], $GLOBALS["core_sql_error"]);
        $GLOBALS["wpdb"]->fail_reads = false;
        $query = run_query();
        check($query->found_posts <= 6 && $query->found_posts > 0, "healthy bounded hybrid did not recover");
    }
    reset_fixture();
    foreach (["off", "kill", "incomplete"] as $gate) {
        Settings::$values["search_mode"] = $gate === "off" ? "off" : "full";
        Settings::$values["kill_switch_global"] = $gate === "kill";
        IndexState::$ready = $gate !== "incomplete";
        assert_native([], $gate . " readiness gate");
    }
    check($GLOBALS["embedding_calls"] === 0, "not-ready search reached provider");

    reset_fixture();
    $GLOBALS["vector_hits"] = [];
    $GLOBALS["posts"] = [];
    $query = run_query();
    check($query->get("post__in") === [0], "healthy empty result did not restrict query");
    run_query();
    check($GLOBALS["embedding_calls"] === 1, "healthy empty result was not cached");

    foreach ([true, false] as $external) {
        reset_fixture();
        $GLOBALS["external_cache"] = $external;
        run_query();
        $keys = array_keys(get_option(SearchInterceptor::CACHE_INDEX_OPTION));
        set_transient("ritriever_live_query_7", ["ok" => true], 300);
        SearchInterceptor::remember_cache_key("ritriever_live_query_7");
        set_transient("unrelated_plugin", "keep", 300);
        $generation = get_option(SearchInterceptor::CACHE_GENERATION_OPTION);
        check(SearchInterceptor::purge_query_cache() === 2, "purge failed to count query and live keys");
        check(get_transient($keys[0]) === false && get_transient("ritriever_live_query_7") === false, "purge missed external transient");
        check(get_transient("unrelated_plugin") === "keep", "purge flushed another plugin");
        check(get_option(SearchInterceptor::CACHE_GENERATION_OPTION) !== $generation, "purge did not advance generation");
        run_query();
        check($GLOBALS["embedding_calls"] === 2, "purge did not invalidate cached search");
    }
    reset_fixture();
    set_transient("ritriever_live_query_12", ["ok" => true], 300);
    $GLOBALS["options"][1][SearchInterceptor::LIVE_CACHE_INDEX_OPTION] = ["ritriever_live_query_12" => time() + 300];
    check(SearchInterceptor::purge_query_cache() === 1 && get_transient("ritriever_live_query_12") === false, "admin live-query registry was not purged");
    check(get_option(SearchInterceptor::LIVE_CACHE_INDEX_OPTION) === false, "admin live-query registry was not cleared");
    for ($i = 0; $i < 110; ++$i) {
        check(SearchInterceptor::store_live_query_result("ritriever_live_query_" . $i, ["ok" => true], 300), "atomic live-query store failed");
    }
    check(count(get_option(SearchInterceptor::LIVE_CACHE_INDEX_OPTION)) === 100 && count($GLOBALS["transients"][1]) === 100, "atomic live-query registry was not bounded");
    check(get_transient("ritriever_live_query_0") === false && get_transient("ritriever_live_query_109") !== false, "equal-expiry live-query eviction removed newest entry");
    check(SearchInterceptor::store_live_query_result("ritriever_live_query_110", [], 1) && isset(get_option(SearchInterceptor::LIVE_CACHE_INDEX_OPTION)["ritriever_live_query_110"]), "short-lifetime live result was published without registry ownership");
    $GLOBALS["wpdb"]->fail_reads = true;
    check(!SearchInterceptor::store_live_query_result("ritriever_live_query_200", ["ok" => true], 300) && get_transient("ritriever_live_query_200") === false, "failed registration published an untracked live query");
    reset_fixture();
    check(SearchInterceptor::store_live_query_result("ritriever_live_query_1", ["ok" => true], 300), "initial live payload was not stored");
    $GLOBALS["fail_cache_write"] = true;
    check(SearchInterceptor::store_live_query_result("ritriever_live_query_1", ["ok" => true], 300), "unchanged WordPress transient was misreported as failure");
    check(get_transient("ritriever_live_query_1") === ["ok" => true], "idempotent live store deleted the valid payload");
    check(!SearchInterceptor::store_live_query_result("ritriever_live_query_1", ["ok" => false], 300) && get_transient("ritriever_live_query_1") === false, "failed changed live store retained stale payload");
    reset_fixture();
    for ($i = 0; $i < 110; ++$i) {
        set_transient("ritriever_live_query_" . $i, [], 300);
        SearchInterceptor::remember_cache_key("ritriever_live_query_" . $i);
    }
    check(count(get_option(SearchInterceptor::CACHE_INDEX_OPTION)) === 100, "registry is not bounded");
    check(count($GLOBALS["transients"][1]) === 100 && get_transient("ritriever_live_query_0") === false, "registry eviction did not delete external keys");
    try {
        SearchInterceptor::remember_cache_key("another_plugin");
        throw new \LogicException("invalid cache prefix accepted");
    } catch (\InvalidArgumentException $expected) {
        check(true, "invalid prefix rejected");
    }

    reset_fixture();
    $GLOBALS["on_embed"] = static function (): void {
        unset($GLOBALS["on_embed"]);
        SearchInterceptor::purge_query_cache();
    };
    assert_native([], "purge during retrieval");
    check(($GLOBALS["transients"][1] ?? []) === [], "old worker populated normal cache after invalidation");
    run_query();
    check($GLOBALS["embedding_calls"] === 2, "new generation did not retry retrieval");

    reset_fixture();
    $GLOBALS["on_embed"] = static function (): void { IndexState::$generation = "index-2"; unset($GLOBALS["on_embed"]); };
    assert_native([], "index generation changed during retrieval");
    reset_fixture();
    $GLOBALS["on_cache_write"] = static function (): void { unset($GLOBALS["on_cache_write"]); SearchInterceptor::purge_query_cache(); };
    run_query();
    check(($GLOBALS["transients"][1] ?? []) === [], "purge during store resurrected transient");

    reset_fixture();
    $GLOBALS["logged_in"] = true;
    run_query();
    run_query();
    check($GLOBALS["embedding_calls"] === 2 && ($GLOBALS["transients"][1] ?? []) === [], "authenticated requests used shared cache");
    reset_fixture();
    run_query();
    $first_keys = array_keys(get_option(SearchInterceptor::CACHE_INDEX_OPTION));
    $GLOBALS["blog"] = 2;
    SearchInterceptor::reset_runtime_state();
    run_query();
    check($GLOBALS["embedding_calls"] === 2, "blog switch retained another site's result");
    check(array_keys(get_option(SearchInterceptor::CACHE_INDEX_OPTION)) !== $first_keys, "blog identity missing from cache key");
    SearchInterceptor::purge_query_cache();
    check(isset($GLOBALS["transients"][1][$first_keys[0]]), "purge crossed blog boundary");
    $GLOBALS["blog"] = 1;
    SearchInterceptor::reset_runtime_state();
    run_query();
    check($GLOBALS["embedding_calls"] === 2, "switch-back lost correct site's cache");
    $GLOBALS["locale"] = "ja";
    run_query();
    check($GLOBALS["embedding_calls"] === 3, "locale context collided");
    Settings::$values["min_score"] = 0.95;
    run_query();
    check($GLOBALS["embedding_calls"] === 4, "retrieval settings context collided");
    IndexState::$fingerprint = "configuration-2";
    run_query();
    check($GLOBALS["embedding_calls"] === 5, "index fingerprint context collided");

    reset_fixture();
    $title = '<em>Fish &amp; chips</em> "quoted" & raw';
    Settings::$values["display_source_badges"] = false;
    check(SearchInterceptor::on_the_title($title, 1) === $title, "disabled badges altered title");
    Settings::$values["display_source_badges"] = true;
    check(SearchInterceptor::on_the_title($title, 1) === $title, "nondecorated title changed");
    run_query();
    $decorated = SearchInterceptor::on_the_title($title, 1);
    check(str_ends_with($decorated, esc_html($title)) && str_contains($decorated, "ritriever-hit-badges"), "decorated title was not escaped at the output boundary");
    $malicious_title = '<img src=x onerror="alert(1)"><script>alert(1)</script>';
    $safe_decorated = SearchInterceptor::on_the_title($malicious_title, 1);
    check(
        str_ends_with($safe_decorated, esc_html($malicious_title)) &&
        !str_contains($safe_decorated, "<img") &&
        !str_contains($safe_decorated, "<script"),
        "decorated title retained executable markup",
    );
    check(SearchInterceptor::on_the_title($malicious_title, 999) === $malicious_title, "nondecorated title lost identity");
    check(SearchInterceptor::on_the_title($decorated, 1) === $decorated, "repeat title filter was not idempotent");
    Settings::$values["search_mode"] = "off";
    check(SearchInterceptor::on_the_title($title, 1) === $title, "off search changed title with stale hit state");
    Settings::$values["search_mode"] = "full";
    $GLOBALS["in_loop"] = false;
    check(SearchInterceptor::on_the_title($title, 1) === $title, "non-loop title changed");
    $GLOBALS["in_loop"] = true;
    SearchInterceptor::reset_runtime_state();
    check(SearchInterceptor::on_the_title($title, 1) === $title, "reset retained badge state");

    reset_fixture();
    foreach ([[0, 0], [1], ["bad", null], [INF, 1]] as $invalid) {
        $GLOBALS["embedding"] = $invalid;
        check(!(new LocalVectorProvider())->retrieve("apple")->ok, "invalid provider vector accepted");
    }
    unset($GLOBALS["embedding"]);
    $GLOBALS["vector_hits"] = [1 => ["score" => NAN, "chunk_text" => ""]];
    check(!(new LocalVectorProvider())->retrieve("apple")->ok, "invalid repository score accepted");
    $GLOBALS["embedding_error"] = new \TypeError("programming defect");
    try {
        (new LocalVectorProvider())->retrieve("apple");
        throw new \LogicException("programming error swallowed");
    } catch (\TypeError $expected) {
        check(true, "programming error not hidden");
    }
    echo "Search contract regressions passed (" . $GLOBALS["assertions"] . " assertions).\n";
}
