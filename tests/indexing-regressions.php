<?php
/**
 * Standalone indexing regressions; SQLite-backed WordPress/HTTP/DDL doubles.
 * Run: php tests/indexing-regressions.php
 */

declare(strict_types=1);

namespace {
    define("ARRAY_A", "ARRAY_A");
    define("RITRIEVER_OPTION_KEY", "ritriever_settings");
    define("RITRIEVER_POSTMETA_CONTENT_HASH", "_ritriever_content_hash");
    define("RITRIEVER_POSTMETA_INDEXED_AT", "_ritriever_indexed_at");
    define("RITRIEVER_POSTMETA_LAST_ERROR", "_ritriever_last_error");

    final class IndexingWpdb
    {
        public string $prefix = "wp_";
        public string $options = "wp_options";
        public string $posts = "wp_posts";
        public string $postmeta = "wp_postmeta";
        public string $terms = "wp_terms";
        public string $term_taxonomy = "wp_term_taxonomy";
        public string $term_relationships = "wp_term_relationships";
        public string $last_error = "";
        public int $insert_id = 0;
        public $dbh = null;
        public \PDO $db;
        public array $sql = [];
        public string $fail = "";
        public int $fail_nth = 1;
        public bool $ambiguous_commit = false;
        public bool $vector_index = true;
        public array $locks = [];
        public int $connection_id = 7;
        public bool $busy_worker = false;
        public ?array $vector_rows = null;
        public bool $programming_error = false;
        public ?string $vector_ddl = null;

        public function __construct()
        {
            $this->db = new \PDO("sqlite::memory:");
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ([
                "CREATE TABLE wp_options(option_name TEXT PRIMARY KEY,option_value TEXT,autoload TEXT)",
                "CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY,post_title TEXT,post_content TEXT,post_excerpt TEXT,post_type TEXT,post_status TEXT,post_password TEXT)",
                "CREATE TABLE wp_postmeta(meta_id INTEGER PRIMARY KEY AUTOINCREMENT,post_id INTEGER,meta_key TEXT,meta_value TEXT)",
                "CREATE TABLE wp_terms(term_id INTEGER PRIMARY KEY,name TEXT)",
                "CREATE TABLE wp_term_taxonomy(term_taxonomy_id INTEGER PRIMARY KEY,term_id INTEGER,taxonomy TEXT)",
                "CREATE TABLE wp_term_relationships(object_id INTEGER,term_taxonomy_id INTEGER)",
            ] as $sql) {
                $this->db->exec($sql);
            }
        }

        public function get_charset_collate(): string { return ""; }

        public function prepare(string $sql, ...$args): string
        {
            $offset = 0;
            return preg_replace_callback('/%[idsf]/', function (array $match) use (&$offset, $args): string {
                $value = $args[$offset++];
                return match ($match[0]) {
                    "%i" => "`" . $value . "`", "%d" => (string) (int) $value,
                    "%f" => (string) (float) $value, default => $this->db->quote((string) $value),
                };
            }, $sql);
        }

        private function fault(string $sql): bool
        {
            $this->sql[] = $sql;
            $this->last_error = "";
            if ($this->fail !== "" && str_contains($sql, $this->fail) && --$this->fail_nth === 0) {
                $this->last_error = "Injected SQL failure";
                $this->fail = "";
                return true;
            }
            return false;
        }

        public function query(string $sql)
        {
            if ($this->fault($sql)) {
                return false;
            }
            if ($sql === "COMMIT" && $this->ambiguous_commit) {
                $this->db->exec("COMMIT");
                $this->ambiguous_commit = false;
                $this->last_error = "Ambiguous COMMIT response";
                return false;
            }
            if (str_starts_with($sql, "ALTER TABLE") || str_starts_with($sql, "SET TRANSACTION")) {
                return 0;
            }
            if (str_starts_with($sql, "CREATE VECTOR INDEX")) {
                $this->vector_index = true;
                return 0;
            }
            if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $match)) {
                $name = $match[1];
                $columns = match ($name) {
                    "wp_ritriever_chunks" => "id INTEGER PRIMARY KEY AUTOINCREMENT,post_id INTEGER,chunk_index INTEGER,chunk_text TEXT,content_hash TEXT,index_generation TEXT,embedding_model TEXT,embedding TEXT,updated_at TEXT",
                    "wp_ritriever_indexed_posts" => "post_id INTEGER PRIMARY KEY,index_generation TEXT,content_hash TEXT,embedding_model TEXT,chunk_count INTEGER",
                    "wp_ritriever_backfill_jobs" => "id INTEGER PRIMARY KEY AUTOINCREMENT,status TEXT,phase TEXT,kind TEXT,index_generation TEXT,total_posts INTEGER,created_at TEXT,updated_at TEXT,completed_at TEXT,last_error TEXT",
                    "wp_ritriever_backfill_items" => "id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER,post_id INTEGER,status TEXT,attempts INTEGER,revision INTEGER,claimed_revision INTEGER,available_at TEXT,locked_at TEXT,locked_by TEXT,last_error TEXT,created_at TEXT,updated_at TEXT,UNIQUE(job_id,post_id)",
                    default => throw new \RuntimeException("Unexpected table " . $name),
                };
                $sql = "CREATE TABLE IF NOT EXISTS `$name` ($columns)";
            }
            $sql = $this->translate($sql);
            try {
                $count = $this->db->exec($sql);
                $this->insert_id = (int) $this->db->lastInsertId();
                return $count;
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        private function translate(string $sql): string
        {
            $sql = str_replace(["UTC_TIMESTAMP()", "VEC_FromText(", "IF(", " FOR UPDATE", "START TRANSACTION"], ["datetime('now')", "json(", "IIF(", "", "BEGIN IMMEDIATE"], $sql);
            if (str_contains($sql, " ON DUPLICATE KEY UPDATE ")) {
                $sql = str_replace(" ON DUPLICATE KEY UPDATE ", " ON CONFLICT DO UPDATE SET ", $sql);
                $sql = preg_replace('/VALUES\((\w+)\)/', 'excluded.$1', $sql);
            }
            if (preg_match('/^(UPDATE `[^`]+` SET .+?) WHERE (.+) ORDER BY id LIMIT (\d+)$/', $sql, $m)) {
                preg_match('/UPDATE (`[^`]+`)/', $sql, $table);
                $sql = $m[1] . " WHERE id IN (SELECT id FROM " . $table[1] . " WHERE " . $m[2] . " ORDER BY id LIMIT " . $m[3] . ")";
            }
            return $sql;
        }

        public function get_results(string $sql, $mode = null)
        {
            if ($this->programming_error) {
                $this->programming_error = false;
                throw new \LogicException("Synthetic programming fault");
            }
            if ($this->fault($sql)) {
                return null;
            }
            if ($sql === "SELECT CONNECTION_ID()") {
                return [["value" => $this->connection_id]];
            }
            if ($sql === "SELECT LAST_INSERT_ID()") {
                return [["value" => $this->insert_id]];
            }
            if (str_contains($sql, "FROM information_schema.TABLES")) {
                return [["value" => 6]];
            }
            if (preg_match("/SELECT (GET_LOCK|IS_USED_LOCK|RELEASE_LOCK)\('([^']+)'/", $sql, $m)) {
                $name = $m[2];
                if ($m[1] === "GET_LOCK") {
                    if ($this->busy_worker) {
                        return [["value" => 0]];
                    }
                    $this->locks[$name] = ($this->locks[$name] ?? 0) + 1;
                    return [["value" => 1]];
                }
                if ($m[1] === "IS_USED_LOCK") {
                    return [["value" => !empty($this->locks[$name]) ? 7 : null]];
                }
                --$this->locks[$name];
                return [["value" => 1]];
            }
            if (str_starts_with($sql, "SHOW INDEX")) {
                return $this->vector_index ? [["Index_type" => "VECTOR"]] : [];
            }
            if (str_starts_with($sql, "SHOW COLUMNS")) {
                return array_map(static fn(string $field): array => ["Field" => $field], ["kind", "index_generation", "revision", "claimed_revision", "available_at"]);
            }
            if (str_starts_with($sql, "SHOW CREATE TABLE")) {
                if ($this->vector_ddl !== null && str_contains($sql, "wp_ritriever_chunks")) {
                    return [["Create Table" => $this->vector_ddl]];
                }
                return [["Create Table" => "CREATE TABLE `table` (`embedding` vector(2), `index_generation` varchar(64), VECTOR KEY `embedding` (`embedding`) M=8 DISTANCE=cosine\n) ENGINE=InnoDB"]];
            }
            if (str_starts_with($sql, "SHOW TABLES")) {
                return [["table" => "wp_ritriever_chunks"]];
            }
            if (str_contains($sql, "VEC_DISTANCE") && $this->vector_rows !== null) {
                return $this->vector_rows;
            }
            try {
                return $this->db->query($this->translate($sql))->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $this->last_error = $e->getMessage();
                return null;
            }
        }
    }

    class WP_Post
    {
        public function __construct(array $row) { foreach ($row as $key => $value) { $this->$key = $value; } }
        public $ID, $post_title, $post_content, $post_excerpt, $post_type, $post_status, $post_password;
    }
    class WP_Term { public function __construct(public string $name) {} }
    class WP_Query
    {
        public array $posts;
        public function __construct(array $args)
        {
            global $wpdb;
            $this->posts = array_map("intval", array_column($wpdb->get_results("SELECT ID FROM wp_posts ORDER BY ID"), "ID"));
        }
    }
    function get_post($id) { global $wpdb; if ($id instanceof WP_Post) { return $id; } $rows = $wpdb->get_results("SELECT * FROM wp_posts WHERE ID = " . (int) $id); return empty($rows) ? null : new WP_Post($rows[0]); }
    class WP_CLI
    {
        public static array $errors = [];
        public static function error(string $message): void { self::$errors[] = $message; }
        public static function log(string $message): void {}
        public static function success(string $message): void {}
        public static function warning(string $message): void {}
    }
    function get_post_meta($id, $key, $single = true) { global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s", $id, $key)); $values = array_map("maybe_unserialize", array_column($rows, "meta_value")); return $single ? ($values[0] ?? "") : $values; }
    function update_post_meta($id, $key, $value) { global $wpdb; delete_post_meta($id, $key); return $wpdb->query($wpdb->prepare("INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES(%d,%s,%s)", $id, $key, maybe_serialize($value))); }
    function delete_post_meta($id, $key) { global $wpdb; return $wpdb->query($wpdb->prepare("DELETE FROM wp_postmeta WHERE post_id = %d AND meta_key = %s", $id, $key)); }
    function get_option($key, $default = false) { global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SELECT option_value FROM wp_options WHERE option_name = %s", $key)); return isset($rows[0]) ? maybe_unserialize($rows[0]["option_value"]) : $default; }
    function delete_option($key) { global $wpdb; return $wpdb->query($wpdb->prepare("DELETE FROM wp_options WHERE option_name = %s", $key)); }
    function maybe_serialize($value) { return is_array($value) ? serialize($value) : (string) $value; }
    function maybe_unserialize($value) { if (!is_string($value)) { return $value; } $decoded = @unserialize($value); return $decoded === false ? $value : $decoded; }
    function wp_cache_delete(...$args) {}
    function wp_is_post_revision($id) { return false; }
    function wp_is_post_autosave($id) { return false; }
    function wp_strip_all_tags($value) { return strip_tags($value); }
    function strip_shortcodes($value) { return $value; }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_parse_url($value) { return parse_url($value); }
    function get_locale() { return "ja"; }
    function taxonomy_exists($taxonomy) { return true; }
    function get_the_terms($id, $taxonomy) { global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SELECT t.name FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id JOIN wp_term_relationships tr ON tr.term_taxonomy_id=tt.term_taxonomy_id WHERE tr.object_id=%d AND tt.taxonomy=%s", $id, $taxonomy)); return array_map(static fn(array $r): WP_Term => new WP_Term($r["name"]), $rows); }
    function add_action(...$args) { $GLOBALS["actions"][] = $args; }
    function add_filter(...$args) {}
    function wp_next_scheduled($hook, $args = []) { return $GLOBALS["cron"][$args === [] ? $hook : $hook . serialize($args)] ?? false; }
    function wp_schedule_event($time, $recurrence, $hook, $args = [], $error = false) { $GLOBALS["cron"][$hook] = $time; return true; }
    function wp_schedule_single_event($time, $hook, $args = []) { $GLOBALS["cron"][$hook . serialize($args)] = $time; return true; }
    function wp_clear_scheduled_hook($hook, $args = []) { unset($GLOBALS["cron"][$args === [] ? $hook : $hook . serialize($args)]); }
    function is_wp_error($result) { return false; }
    function esc_html($value) { return (string) $value; }
    function wp_reset_postdata() {}
    function get_edit_post_link($id, $context) { return ""; }
    function get_the_title($post) { return $post->post_title; }

    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, "RiTriever\\")) {
            $file = dirname(__DIR__) . "/includes/" . str_replace("\\", "/", substr($class, 10)) . ".php";
            if (file_exists($file)) { require $file; }
        }
    });
}

namespace RiTriever {
    final class Settings
    {
        public static array $values = [
            "embedding_provider" => "custom_http", "custom_embedding_model" => "fixture", "custom_embedding_format" => "openai_compatible",
            "custom_embedding_endpoint" => "https://fixture.invalid/embed", "embedding_dimensions" => 2,
            "chunk_max_chars" => 500, "chunk_overlap_chars" => 100, "target_locale" => "ja",
            "vector_distance" => "cosine", "vector_index_m" => 8, "post_types" => ["post"],
            "post_statuses" => ["publish"], "sync_excluded_post_ids" => [], "indexed_custom_fields" => ["source"],
            "indexed_taxonomies" => ["category"], "sync_enabled" => true, "kill_switch_global" => false,
        ];
        public static bool $complete = false;
        public static function get(string $key) { return self::$values[$key] ?? null; }
        public static function all(): array { return self::$values; }
        public static function refresh(): void {}
        public static function clear_initial_backfill_state($reason): void { self::$complete = false; }
        public static function mark_initial_backfill_complete($stats): void { self::$complete = true; }
    }
    final class LanguageOptions
    {
        public const CONTEXT_VERSION = "locale-v2";
        public static function selected_locale(): string { return "ja"; }
        public static function with_embedding_context(string $text): string { return "[ja] " . $text; }
    }
    final class SearchInterceptor { public static function purge_query_cache(): void {} }
    final class Logger
    {
        public static int $warnings = 0;
        public static function error(...$args): void {}
        public static function warn(...$args): void { ++self::$warnings; }
    }
}
namespace RiTriever\Database {
    final class VectorCapabilities { public static function detect(): array { return ["family" => "mariadb", "native_vector" => true, "vector_index" => true]; } }
}
namespace RiTriever\Embedding {
    final class EmbeddingProviderFactory
    {
        public static int $calls = 0;
        public static $callback = null;
        public static function make(): self { return new self(); }
        public function model(): string { return "fixture"; }
        public function embed_many(array $texts): array
        {
            ++self::$calls;
            if (self::$callback !== null) { $callback = self::$callback; self::$callback = null; $callback(); }
            return array_map(static fn(string $text): array => [1.0, 0.5], $texts);
        }
    }
}
namespace {
    use RiTriever\BackfillRunner;
    use RiTriever\BulkBackfillIndexer;
    use RiTriever\IndexState;
    use RiTriever\PostSync;
    use RiTriever\Settings;
    use RiTriever\Database\LocalVectorRepository;
    use RiTriever\Database\VectorSchema;
    use RiTriever\Embedding\EmbeddingProviderFactory;

    $wpdb = new IndexingWpdb();
    $GLOBALS["cron"] = [];
    $assertions = 0;
    function check(bool $condition, string $message): void
    {
        ++$GLOBALS["assertions"];
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function raises(callable $callback, string $message): void
    {
        try { $callback(); } catch (\Throwable $e) { check(true, $message); return; }
        check(false, $message);
    }
    function set_post(int $id, string $content = "original", string $status = "publish"): void
    {
        global $wpdb;
        $wpdb->db->exec($wpdb->prepare("INSERT INTO wp_posts(ID,post_title,post_content,post_excerpt,post_type,post_status,post_password) VALUES(%d,'Fixture',%s,'','post',%s,'') ON CONFLICT(ID) DO UPDATE SET post_content=excluded.post_content,post_status=excluded.post_status", $id, $content, $status));
    }
    function chunks(int $id): array { global $wpdb; return $wpdb->get_results("SELECT * FROM wp_ritriever_chunks WHERE post_id=" . $id . " ORDER BY chunk_index"); }
    function initialize(): array { $state = BackfillRunner::create_queue(); for ($i = 0; $i < 10 && in_array($state["status"], ["queued","running"], true); ++$i) { $state = BackfillRunner::process_batch(200); } check($state["status"] === "complete", "Initialization completes: " . json_encode($state)); return $state; }

    // UTF-8 code-point length, overlap, final-boundary behavior, and no mbstring dependency.
    foreach ([str_repeat("日本語😀A", 300), str_repeat("a", 500), str_repeat("🚀", 501), "", " \n "] as $text) {
        $result = PostSync::chunk_text($text);
        foreach ($result as $chunk) {
            check(preg_match("//u", $chunk) === 1 && json_encode($chunk) !== false, "Every chunk is valid UTF-8/JSON");
            check(count(preg_split("//u", $chunk, -1, PREG_SPLIT_NO_EMPTY)) <= 500, "Chunk settings count Unicode code points");
        }
    }
    check(count(PostSync::chunk_text(str_repeat("a", 500))) === 1, "Exact boundary has no overlap-only tail");
    Settings::$values["chunk_overlap_chars"] = PHP_INT_MAX;
    check(count(PostSync::chunk_text(str_repeat("日", 1500))) < 10, "Extreme overlap terminates");
    Settings::$values["chunk_overlap_chars"] = 100;
    raises(static fn() => PostSync::chunk_text("\xFF"), "Invalid UTF-8 rejected before provider request");

    set_post(1);
    initialize();
    $first = chunks(1);
    $first_generation = IndexState::generation();
    initialize();
    check(count(chunks(1)) === count($first) && IndexState::generation() !== $first_generation, "Repeated initialization rebuilds identical content");
    $calls = EmbeddingProviderFactory::$calls;
    BulkBackfillIndexer::process_posts([1]);
    check(EmbeddingProviderFactory::$calls === $calls, "Matching current receipt skips provider");
    $wpdb->db->exec("DELETE FROM wp_ritriever_chunks WHERE post_id=1");
    BulkBackfillIndexer::process_posts([1]);
    check(count(chunks(1)) === 1 && EmbeddingProviderFactory::$calls > $calls, "Missing chunks invalidate matching metadata");

    // All transaction failures retain the old complete set and metadata.
    $repository = new LocalVectorRepository();
    foreach (["START TRANSACTION", "DELETE FROM `wp_ritriever_chunks`", "INSERT INTO `wp_ritriever_chunks`", "INSERT INTO `wp_ritriever_indexed_posts`", "INSERT INTO `wp_postmeta`", "COMMIT"] as $failure) {
        $before = chunks(1);
        $hash = get_post_meta(1, RITRIEVER_POSTMETA_CONTENT_HASH);
        $wpdb->fail = $failure;
        $wpdb->fail_nth = $failure === "INSERT INTO `wp_ritriever_chunks`" ? 2 : 1;
        raises(static fn() => $repository->replace_post_embeddings(1, "fixture", "replacement", ["new-a", "new-b"], [[1.0, 0.1], [1.0, 0.2]]), "SQL boundary failure propagates: " . $failure);
        check(chunks(1) === $before && get_post_meta(1, RITRIEVER_POSTMETA_CONTENT_HASH) === $hash, "SQL failure does not advance chunks/metadata: " . $failure);
    }
    foreach ([[1.0], [NAN, 1.0], ["1", 0.0], [0.0, 0.0], [1e300, 1.0], [1e-80, 1e-80]] as $bad) {
        raises(static fn() => $repository->replace_post_embeddings(1, "fixture", "bad", ["new"], [$bad]), "Invalid vectors rejected before deletion");
    }
    $wpdb->ambiguous_commit = true;
    raises(static fn() => $repository->replace_post_embeddings(1, "fixture", PostSync::content_hash(1), ["committed"], [[1.0, 0.5]]), "Lost COMMIT acknowledgement is not success");
    check($repository->has_current(1, PostSync::content_hash(1), IndexState::generation()), "Durable receipt reconciles an ambiguous committed transaction");
    $calls = EmbeddingProviderFactory::$calls;
    BulkBackfillIndexer::process_posts([1]);
    check(EmbeddingProviderFactory::$calls === $calls, "Retry after committed response loss converges without embedding");

    set_post(1, "edited");
    EmbeddingProviderFactory::$callback = static function (): void { set_post(1, "newest"); };
    $result = BulkBackfillIndexer::process_posts([1]);
    check($result["stale_ids"] === [1] && chunks(1)[0]["chunk_text"] === "committed", "Stale edit response cannot replace current rows");
    BulkBackfillIndexer::process_posts([1]);
    check(str_contains(chunks(1)[0]["chunk_text"], "newest"), "Retry indexes newest revision");

    set_post(1, "newest", "draft");
    PostSync::on_save_post(1, get_post(1));
    check(chunks(1) === [] && get_post_meta(1, RITRIEVER_POSTMETA_CONTENT_HASH) === "", "Unpublishing removes chunks and sync metadata");
    BackfillRunner::process_batch();
    set_post(1, "newest", "publish");
    PostSync::on_save_post(1, get_post(1));
    BackfillRunner::process_batch();
    check(count(chunks(1)) === 1, "Republishing unchanged content repairs index");

    $old_rows = chunks(1);
    Settings::$values["custom_embedding_endpoint"] = "https://new.invalid/embed";
    IndexState::invalidate("Endpoint changed.");
    check(chunks(1) === $old_rows && !IndexState::is_ready(), "Setting invalidation retains data but disables search");
    check(BackfillRunner::enqueue_posts([1]) === 0, "Invalid settings cannot silently restart indexing");
    initialize();

    // Fencing cancelled and reinitialized jobs at the provider response boundary.
    set_post(1, "cancel response");
    PostSync::on_save_post(1, get_post(1));
    $before = chunks(1);
    EmbeddingProviderFactory::$callback = static fn() => BackfillRunner::cancel();
    $state = BackfillRunner::process_batch();
    check($state["status"] === "cancelled" && chunks(1) === $before, "Cancelled job cannot be revived or commit an old response");
    initialize();
    set_post(1, "old-generation response");
    PostSync::on_save_post(1, get_post(1));
    EmbeddingProviderFactory::$callback = static fn() => BackfillRunner::create_queue();
    BackfillRunner::process_batch();
    check(chunks(1) === [], "Old generation cannot write into reinitialized table");
    BackfillRunner::process_batch();
    check(count(chunks(1)) === 1, "New generation completes after stale response");

    // Retry/backoff, lock contention, reclaim, and stopping the watchdog.
    set_post(1, "retry temporary");
    PostSync::on_save_post(1, get_post(1));
    EmbeddingProviderFactory::$callback = static function (): void { throw new \RuntimeException("429 rate limit", 429); };
    $state = BackfillRunner::process_batch();
    check($state["counts"]["pending"] === 1 && $state["attempts"] === 1 && $state["next_attempt"] > time() && $state["next_scheduled"] > 0, "429 retains a durable scheduled backoff");
    $wpdb->busy_worker = true;
    $state = BackfillRunner::process_batch();
    $wpdb->busy_worker = false;
    check($state["next_scheduled"] > 0 && str_contains($state["stop_reason"], "worker"), "Lock contention retains watchdog");
    BackfillRunner::pause();
    check(!wp_next_scheduled(BackfillRunner::CRON_HOOK), "Pause does not restart automatically");
    BackfillRunner::resume();
    $wpdb->db->exec("UPDATE wp_ritriever_backfill_items SET status='processing',locked_by='dead-session',attempts=1 WHERE status='pending'");
    $state = BackfillRunner::process_batch();
    check($state["counts"]["pending"] === 1 || $state["status"] === "complete", "New session reclaims interrupted worker without lease race");
    $wpdb->db->exec("UPDATE wp_ritriever_backfill_items SET available_at='2000-01-01 00:00:00' WHERE status='pending'");
    BackfillRunner::process_batch();

    set_post(1, "permanent");
    PostSync::on_save_post(1, get_post(1));
    EmbeddingProviderFactory::$callback = static function (): void { throw new \RuntimeException("401 credentials rejected", 401); };
    $state = BackfillRunner::process_batch();
    check($state["status"] === "failed" && !Settings::$complete && !IndexState::is_ready(), "Permanent failure cannot mark the job/index ready");
    $retry = BackfillRunner::retry_failed();
    check($retry["requested"] === 1 && $retry["pending"] === 1 && $retry["succeeded"] === 0, "Retry counters report pending rather than false success");
    BackfillRunner::process_batch();
    check(BackfillRunner::status()["status"] === "complete", "Retry reconciles job and post failure state");

    // Selected metadata/term events enqueue; plugin-owned metadata cannot recurse.
    $calls = EmbeddingProviderFactory::$calls;
    update_post_meta(1, "source", "new meta");
    PostSync::on_meta_change(1, 1, "source", "new meta");
    PostSync::on_meta_change(2, 1, RITRIEVER_POSTMETA_CONTENT_HASH, "ignored");
    check(EmbeddingProviderFactory::$calls === $calls && BackfillRunner::status()["counts"]["pending"] === 1, "Meta hooks coalesce without inline provider requests");
    BackfillRunner::process_batch();
    check(str_contains(chunks(1)[0]["chunk_text"], "new meta"), "Final selected metadata is indexed");
    $wpdb->db->exec("INSERT INTO wp_terms VALUES(1,'Term'); INSERT INTO wp_term_taxonomy VALUES(1,1,'category'); INSERT INTO wp_term_relationships VALUES(1,1)");
    PostSync::on_set_terms(1, [], [1], "category");
    BackfillRunner::process_batch();
    $wpdb->db->exec("UPDATE wp_terms SET name='Renamed' WHERE term_id=1");
    PostSync::on_edited_term(1, 1, "category");
    BackfillRunner::process_batch();
    check(str_contains(chunks(1)[0]["chunk_text"], "Renamed"), "Term rename queues related posts");

    // Preparing jobs, DDL verification, and SQL COUNT failures cannot become complete.
    $wpdb->fail = "INSERT INTO `wp_ritriever_backfill_items`";
    $wpdb->fail_nth = 1;
    raises(static fn() => BackfillRunner::create_queue(), "Queue insertion failure propagates");
    check(BackfillRunner::status()["status"] === "failed" && !Settings::$complete, "Partial queue remains non-processable");
    initialize();
    $wpdb->fail = "SHOW CREATE TABLE";
    $wpdb->fail_nth = 1;
    raises(static fn() => BackfillRunner::create_queue(), "DDL verification failure propagates");
    check(!Settings::$complete, "DDL failure cannot mark initialization complete");
    initialize();
    $wpdb->fail = "SELECT status, COUNT(*)";
    $wpdb->fail_nth = 1;
    raises(static fn() => BackfillRunner::status(), "COUNT failure cannot appear as empty successful job");
    $wpdb->fail = "VEC_DISTANCE";
    $wpdb->fail_nth = 1;
    raises(static fn() => $repository->search_with_chunks([1.0,0.5], "fixture", 3), "Vector SQL failure propagates to fallback");

    // Never release another session's lock after reconnect.
    $lock = \RiTriever\Database\DatabaseLock::acquire("test-owner");
    $wpdb->connection_id = 8;
    raises(static fn() => $lock->assert_owned(), "Reconnected worker loses lock authority");
    $released_before = count(array_filter($wpdb->sql, static fn(string $sql): bool => str_contains($sql, "RELEASE_LOCK")));
    $lock->release();
    $released_after = count(array_filter($wpdb->sql, static fn(string $sql): bool => str_contains($sql, "RELEASE_LOCK")));
    check($released_before === $released_after, "Lost session never releases a replacement owner lock");
    $wpdb->connection_id = 7;

    // Bulk transactions retain all old posts if an insert in a later post fails.
    set_post(2, "second post");
    BulkBackfillIndexer::process_posts([2]);
    $old_one = chunks(1);
    $old_two = chunks(2);
    $wpdb->fail = "INSERT INTO `wp_ritriever_chunks`";
    $wpdb->fail_nth = 2;
    raises(static fn() => $repository->replace_many_post_embeddings([
        ["post_id" => 1, "model" => "fixture", "content_hash" => "bulk", "chunks" => ["first"], "embeddings" => [[1.0,0.5]]],
        ["post_id" => 2, "model" => "fixture", "content_hash" => "bulk", "chunks" => ["second"], "embeddings" => [[1.0,0.5]]],
    ]), "Later bulk insert failure propagates");
    check(chunks(1) === $old_one && chunks(2) === $old_two, "Bulk rollback retains every old post set");

    // Fingerprints ignore credentials but include effective endpoint/chunk identity.
    $settings = Settings::all();
    $fingerprint = IndexState::fingerprint($settings);
    $settings["custom_embedding_api_key"] = "synthetic-key-not-sent";
    check(IndexState::fingerprint($settings) === $fingerprint, "API key rotation does not change embedding identity");
    $settings["custom_embedding_endpoint"] .= "?api_key=synthetic";
    check(IndexState::fingerprint($settings) === $fingerprint, "Endpoint credentials are excluded from fingerprint");
    ++$settings["chunk_max_chars"];
    check(IndexState::fingerprint($settings) !== $fingerprint, "Chunk settings change embedding identity");

    // A changed settings option must fence a long-lived worker even with its old cache.
    $stored = Settings::all();
    $stored["kill_switch_global"] = true;
    $wpdb->db->exec($wpdb->prepare("INSERT INTO wp_options(option_name,option_value,autoload) VALUES(%s,%s,'no')", RITRIEVER_OPTION_KEY, serialize($stored)));
    check(!IndexState::is_writable() && !IndexState::can_sync(), "Persisted emergency stop wins over a worker's settings cache");
    delete_option(RITRIEVER_OPTION_KEY);

    // A late edit invalidates the claimed revision and queues exactly one current item.
    set_post(1, "edit while provider runs");
    PostSync::on_save_post(1, get_post(1));
    EmbeddingProviderFactory::$callback = static function (): void {
        set_post(1, "final revision");
        PostSync::on_save_post(1, get_post(1));
        PostSync::on_meta_change(1, 1, "source", "same operation");
    };
    $state = BackfillRunner::process_batch();
    check(($state["counts"]["pending"] ?? 0) === 1, "Concurrent edit fences claim and coalesces one retry");
    BackfillRunner::process_batch();
    check(str_contains(chunks(1)[0]["chunk_text"], "final revision"), "Revision-fenced retry indexes final content");

    // Retry-all must cover more than the UI's 200-row failure preview.
    for ($id = 3; $id <= 207; ++$id) {
        set_post($id, "retry fixture " . $id);
        update_post_meta($id, RITRIEVER_POSTMETA_LAST_ERROR, "Synthetic failure");
    }
    $retry = BackfillRunner::retry_failed();
    check($retry["requested"] === 205 && $retry["pending"] === 205 && $retry["succeeded"] === 0, "Retry-all does not truncate to 200 or claim unpersisted success");
    BackfillRunner::process_batch(200);
    $state = BackfillRunner::process_batch(200);
    check($state["status"] === "complete" && $state["errors"] === 0, "All 205 queued retries converge");

    set_post(2, "bounded retry");
    PostSync::on_save_post(2, get_post(2));
    for ($attempt = 1; $attempt <= BackfillRunner::MAX_ATTEMPTS; ++$attempt) {
        EmbeddingProviderFactory::$callback = static function (): void {
            throw new \RiTriever\Embedding\EmbeddingProviderException("Synthetic unavailable", 503, true, 120);
        };
        $state = BackfillRunner::process_batch();
        if ($attempt < BackfillRunner::MAX_ATTEMPTS) {
            check(($state["counts"]["pending"] ?? 0) === 1 && $state["next_attempt"] >= time() + 119 && $state["next_scheduled"] > 0, "Structured Retry-After remains scheduled within attempt budget");
            $wpdb->db->exec("UPDATE wp_ritriever_backfill_items SET available_at='2000-01-01 00:00:00' WHERE status='pending'");
        } else {
            check($state["status"] === "failed" && $state["attempts"] === BackfillRunner::MAX_ATTEMPTS && !wp_next_scheduled(BackfillRunner::CRON_HOOK), "Retry budget stops permanently with an inspectable failure");
        }
    }
    BackfillRunner::retry_failed();
    BackfillRunner::process_batch();
    $wpdb->db->exec("DELETE FROM wp_term_relationships WHERE object_id=1");
    PostSync::on_deleted_relationships(1, [1], "category");
    BackfillRunner::process_batch();
    check(!str_contains(chunks(1)[0]["chunk_text"], "Renamed"), "Term relationship deletion removes indexed term text");
    delete_post_meta(1, "source");
    PostSync::on_meta_change([1], 1, "source", "");
    BackfillRunner::process_batch();
    check(!str_contains(chunks(1)[0]["chunk_text"], "new meta"), "Selected metadata deletion reindexes final state");

    $wpdb->fail = "INSERT INTO `wp_ritriever_backfill_items`";
    $wpdb->fail_nth = 3;
    raises(static fn() => BackfillRunner::create_queue(), "Nth queue INSERT failure rolls back preparation");
    $state = BackfillRunner::status();
    check($state["status"] === "failed" && array_sum($state["counts"]) === 0 && !Settings::$complete, "Partial queue is neither processable nor complete");
    initialize();
    $wpdb->fail = "CREATE TABLE IF NOT EXISTS `wp_ritriever_chunks`";
    $wpdb->fail_nth = 1;
    raises(static fn() => BackfillRunner::create_queue(), "CREATE failure propagates");
    check(!IndexState::is_ready() && !Settings::$complete, "CREATE failure cannot expose a ready index");
    initialize();
    $wpdb->vector_index = false;
    $wpdb->fail = "CREATE VECTOR INDEX";
    $wpdb->fail_nth = 1;
    raises(static fn() => VectorSchema::create_vector_index(), "VECTOR index DDL failure propagates");
    check(!IndexState::is_ready(), "Missing native vector index disables readiness");
    $wpdb->vector_index = true;
    $wpdb->db->exec("DELETE FROM wp_ritriever_chunks WHERE post_id=2");
    $retry = BackfillRunner::create_retry_queue(2);
    check($retry["pending"] === 1 && $retry["state"]["remaining"] === 1 && $retry["state"]["next_run_at"] > 0, "Single retry alias exposes truthful admin queue counters");
    BackfillRunner::process_batch();
    check(count(chunks(2)) === 1, "Individual retry repairs missing data despite a matching old hash");
    $valid_hit = ["post_id" => "2", "distance" => "0.25", "chunk_text" => "fixture"];
    $wpdb->vector_rows = [$valid_hit];
    check($repository->search_with_chunks([1.0, 0.5], "fixture", 3)[2]["score"] === 0.8, "Native numeric-string distances retain normalized scores");
    foreach ([null, "invalid", INF, NAN, "1e999"] as $invalid_distance) {
        $wpdb->vector_rows = [array_replace($valid_hit, ["distance" => $invalid_distance])];
        raises(static fn() => $repository->search_with_chunks([1.0, 0.5], "fixture", 3), "Invalid native distances propagate rather than becoming perfect hits");
    }
    foreach ([0, -1, "invalid", 1.5, true] as $invalid_id) {
        $wpdb->vector_rows = [array_replace($valid_hit, ["post_id" => $invalid_id])];
        raises(static fn() => $repository->search_with_chunks([1.0, 0.5], "fixture", 3), "Malformed native post IDs propagate rather than silently truncating hits");
    }
    $wpdb->vector_rows = [array_replace($valid_hit, ["chunk_text" => null])];
    raises(static fn() => $repository->search_with_chunks([1.0, 0.5], "fixture", 3), "Malformed native snippet triggers fallback");
    $wpdb->vector_rows = null;
    update_post_meta(1, RITRIEVER_POSTMETA_LAST_ERROR, "Synthetic failed snapshot member");
    update_post_meta(2, RITRIEVER_POSTMETA_LAST_ERROR, "Synthetic remaining failure");
    $prior_job = BackfillRunner::status()["job_id"];
    $retry = BackfillRunner::retry_failed([1]);
    check($retry["state"]["job_id"] !== $prior_job && $retry["state"]["total"] === 1 && $retry["state"]["processed"] === 0, "Retry uses a new exact snapshot, not previous job successes");
    check($retry["state"]["remaining_failures"] === 2, "Retry snapshot exposes current site-wide eligible failures");
    $state = BackfillRunner::process_batch();
    check($state["status"] === "complete" && $state["remaining_failures"] === 1 && !IndexState::is_ready(), "One successful retry does not claim unrelated failures are repaired");
    $generation = IndexState::generation();
    Settings::$values["sync_enabled"] = false;
    $retry = BackfillRunner::retry_failed();
    check($retry["pending"] === 0 && $retry["requested"] === 1 && $retry["skipped"] === 1 && IndexState::generation() === $generation, "Disabled synchronization cannot falsely report retry work queued");
    Settings::$values["sync_enabled"] = true;
    $retry = BackfillRunner::retry_failed();
    $state = BackfillRunner::process_batch();
    check($retry["pending"] === 1 && $state["remaining_failures"] === 0 && IndexState::is_ready(), "Remaining failure snapshot converges without generation reset");
    set_post(3, "active queue remains intact");
    PostSync::on_save_post(3, get_post(3));
    $active = BackfillRunner::status();
    $retry = BackfillRunner::retry_failed([2]);
    check($retry["pending"] === 0 && $retry["state"]["job_id"] === $active["job_id"], "Retry cannot replace an already active initialization/sync queue");
    BackfillRunner::process_batch();
    $wpdb->db->exec("DELETE FROM wp_posts WHERE ID > 3");
    BackfillRunner::create_queue();
    BackfillRunner::cancel();
    $generation = IndexState::generation();
    BackfillRunner::retry_failed([1]);
    raises(static fn() => BackfillRunner::process_batch(), "A single retry cannot complete a cancelled, partially empty generation");
    check(!IndexState::is_ready() && BackfillRunner::status()["remaining_failures"] === 2, "Missing current-generation receipts become inspectable retry failures");
    $retry = BackfillRunner::retry_failed();
    BackfillRunner::process_batch();
    check($retry["pending"] === 2 && IndexState::is_ready() && IndexState::generation() === $generation, "Coverage reconciliation repairs missing receipts without resetting generation");
    $warnings = \RiTriever\Logger::$warnings;
    $wpdb->fail = "SELECT option_value";
    $wpdb->fail_nth = 1;
    check(!IndexState::is_ready() && \RiTriever\Logger::$warnings === $warnings + 1, "Readiness fails safely and logs operational SQL failures");
    $wpdb->programming_error = true;
    try {
        IndexState::is_ready();
        check(false, "Readiness must not conceal programming faults");
    } catch (\LogicException $e) {
        check(true, "Readiness propagates programming faults");
    }
    // Option quoting copied from MariaDB 11.7/11.8 SHOW CREATE TABLE output.
    $real_vector_ddl = "CREATE TABLE `wp_ritriever_chunks` (\n" .
        "  `embedding` vector(16) NOT NULL,\n" .
        "  `index_generation` varchar(64) NOT NULL,\n" .
        "  VECTOR KEY `embedding` (`embedding`) `M`='8' `DISTANCE`='cosine'\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    Settings::$values["embedding_dimensions"] = 16;
    $wpdb->vector_ddl = $real_vector_ddl;
    check(VectorSchema::is_ready(), "Real MariaDB quoted VECTOR option names/values are recognized");
    foreach ([
        str_replace("vector(16)", "vector(17)", $real_vector_ddl),
        str_replace("`M`='8'", "`M`='80'", $real_vector_ddl),
        str_replace("`M`='8'", "`M`='8.0'", $real_vector_ddl),
        str_replace("`DISTANCE`='cosine'", "`DISTANCE`='cosine_extra'", $real_vector_ddl),
        str_replace("`DISTANCE`='cosine'", "`DISTANCE`='euclidean'", $real_vector_ddl),
        str_replace("VECTOR KEY `embedding`", "VECTOR KEY `other`", $real_vector_ddl),
    ] as $wrong_ddl) {
        $wpdb->vector_ddl = $wrong_ddl;
        check(!VectorSchema::is_ready(), "Schema verification retains exact dimension/index/distance/M boundaries");
    }
    Settings::$values["embedding_dimensions"] = 2;
    $wpdb->vector_ddl = null;
    $wpdb->fail = "SELECT * FROM `wp_ritriever_backfill_jobs`";
    $wpdb->fail_nth = 1;
    (new \RiTriever\CLI\BackfillCommand())->backfill([], ["status" => true]);
    check(count(\WP_CLI::$errors) === 1 && str_contains(\WP_CLI::$errors[0], "Database read failed"), "CLI reports operational failures via WP_CLI::error rather than a PHP fatal");
    foreach ([
        static fn() => PostSync::on_save_post(1, null),
        static fn() => PostSync::on_delete_post(1),
        static fn() => \RiTriever\IndexDiagnostics::summary(),
        static fn() => BackfillRunner::process_scheduled(),
        static fn() => (new \RiTriever\CLI\BackfillCommand())->backfill([], ["status" => true]),
    ] as $boundary) {
        $wpdb->programming_error = true;
        try {
            $boundary();
            check(false, "Operational boundaries must not suppress programming faults");
        } catch (\LogicException $e) {
            check(true, "Operational boundary propagates programming fault");
        }
    }
    $warnings = \RiTriever\Logger::$warnings;
    $wpdb->fail = "SELECT ID FROM";
    $wpdb->fail_nth = 1;
    $diagnostics = \RiTriever\IndexDiagnostics::summary();
    check($diagnostics["queue_status"] === "unavailable" && \RiTriever\Logger::$warnings === $warnings + 1, "Diagnostics still report and log operational failure safely");
    foreach (["bulk", "batch", "cron"] as $boundary) {
        set_post(1, "programming fault boundary " . $boundary);
        PostSync::on_save_post(1, get_post(1));
        $hash = get_post_meta(1, RITRIEVER_POSTMETA_CONTENT_HASH);
        EmbeddingProviderFactory::$callback = static function (): void { throw new \TypeError("Synthetic provider input programming fault"); };
        try {
            if ($boundary === "bulk") {
                BulkBackfillIndexer::process_posts([1]);
            } elseif ($boundary === "batch") {
                BackfillRunner::process_batch();
            } else {
                BackfillRunner::process_scheduled();
            }
            check(false, "Provider programming Error must propagate through " . $boundary);
        } catch (\TypeError $e) {
            check(true, "Provider programming Error propagated through " . $boundary);
        }
        check(get_post_meta(1, RITRIEVER_POSTMETA_CONTENT_HASH) === $hash && get_post_meta(1, RITRIEVER_POSTMETA_LAST_ERROR) === "", "Programming Error does not become a normal failed post or advance metadata");
        BackfillRunner::process_batch();
    }
    if (function_exists("mysqli_init")) {
        $connection = mysqli_init();
        $wpdb->dbh = $connection;
        \RiTriever\Database\Sql::pin();
        $connection->close();
        try {
            foreach (["query", "rows"] as $operation) {
                try {
                    \RiTriever\Database\Sql::$operation("SELECT 1");
                    check(false, "Closed pinned session must fail");
                } catch (\RuntimeException $e) {
                    check($e->getCode() === 2006 && str_contains($e->getMessage(), "session closed"), "Closed mysqli Error becomes a specific operational session-loss error");
                }
            }
        } finally {
            \RiTriever\Database\Sql::unpin();
            $wpdb->dbh = null;
        }
        $connection = mysqli_init();
        $wpdb->dbh = $connection;
        \RiTriever\Database\Sql::pin();
        try {
            try {
                \RiTriever\Database\Sql::query("SELECT 1");
                check(false, "An uninitialized driver handle must not be accepted");
            } catch (\Error $e) {
                check(str_contains($e->getMessage(), "not fully initialized"), "Other driver programming Errors are not normalized into operational failures");
            }
        } finally {
            \RiTriever\Database\Sql::unpin();
            $wpdb->dbh = null;
            $connection->close();
        }
    }
    check(!BackfillRunner::is_retryable(new \TypeError("database timeout", 503)), "Programming Errors cannot become retryable from their message or numeric code");
    check(!BackfillRunner::is_retryable(new \LogicException("connection error", 429)), "Logic faults cannot be misclassified as transient provider failures");

    echo "indexing-regressions: " . $assertions . " assertions passed\n";
}
