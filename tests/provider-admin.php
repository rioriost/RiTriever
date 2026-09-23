<?php
declare(strict_types=1);

namespace RiTriever {
    final class BackfillRunner
    {
        public static function create_queue(): array
        {
            \operation_failure("initialize");
            return ["status" => "queued", "total" => 0];
        }
        public static function process_batch(int $limit): array
        {
            \operation_failure("ajax");
            return ["status" => "idle"];
        }
        public static function retry_failed(array $ids = []): array
        {
            \operation_failure("retry");
            $GLOBALS["retry_id"] = $ids[0] ?? null;
            $total = $ids === [] ? 201 : 1;
            $reason = $GLOBALS["blocked_retry_reasons"][$GLOBALS["mode"]] ?? null;
            if ($reason !== null) {
                return ["requested" => $total, "pending" => 0, "skipped" => $total, "state" => ["stop_reason" => $reason]];
            }
            return ["requested" => $total, "pending" => $total, "skipped" => 0, "state" => ["status" => "queued", "total" => $total]];
        }
    }
    final class SearchInterceptor
    {
        public const LIVE_CACHE_INDEX_OPTION = "ritriever_live_query_keys";
        public static bool $store_success = true;
        public static function store_live_query_result(string $key, array $payload, int $ttl): bool
        {
            $GLOBALS["live_store_args"] = [$key, $payload, $ttl];
            return self::$store_success;
        }
    }
    final class IndexDiagnostics
    {
        public static function summary(int $failed_limit = 20): array
        {
            return [
                "eligible_posts" => 0, "indexed_posts" => 0, "chunk_count" => 0,
                "coverage_percent" => 0.0, "failed_count" => 0, "queue_status" => "unavailable",
                "queue_processed" => 0, "queue_total" => 0, "queue_errors" => 0, "failed_posts" => [],
                "ready" => false, "diagnostic_error" => "Database <unavailable>; coverage is unknown.",
            ];
        }
    }
}

namespace RiTriever\Database {
    final class VectorCapabilities
    {
        public static function detect(): array
        {
            return ["native_vector" => true, "vector_index" => true];
        }
        public static function run_probe(): array
        {
            \operation_failure("database");
            return ["ok" => true];
        }
    }
}

namespace {
    const RITRIEVER_VERSION = "test";
    const RITRIEVER_OPTION_KEY = "ritriever_settings";
    const RITRIEVER_ADMIN_CAPABILITY = "manage_options";
    const MINUTE_IN_SECONDS = 60;
    $options = [];
    $transients = [];
    $admin_locale = "en_US";
    $mode = $argv[1] ?? "";
    $blocked_retry_reasons = [
        "retry-sync-disabled" => "Synchronization is disabled; enable it before retrying.",
        "retry-global-stop" => "Global stop is active; disable it before retrying.",
        "retry-active-job" => 'An active job exists; use its "Pause" & "Cancel" controls before retrying.',
        "retry-no-reason" => "",
    ];
    function get_option($key, $default = false) { return $GLOBALS["options"][$key] ?? $default; }
    function update_option($key, $value, $autoload = false) { $GLOBALS["options"][$key] = $value; return true; }
    function get_current_user_id() { return 31; }
    function get_locale() { return "ja"; }
    function determine_locale() { return $GLOBALS["admin_locale"]; }
    function get_translations_for_domain($domain) { return new class { public function translate($text) { return $text; } }; }
    function set_transient($key, $value, $ttl) { $GLOBALS["transients"][$key] = $value; return true; }
    function delete_transient($key) { unset($GLOBALS["transients"][$key]); return true; }
    function current_user_can($capability) { return true; }
    function check_admin_referer($nonce) {}
    function check_ajax_referer($nonce, $field) {}
    function wp_send_json_error($payload, $status = 200) { echo json_encode(["ajax_error" => $payload, "status" => $status]); exit(0); }
    function admin_url($path) { return "https://example.invalid/wp-admin/" . $path; }
    function add_query_arg($args, $url) { return $url . "?" . http_build_query($args); }
    function wp_safe_redirect($url) { echo json_encode(["url" => $url, "retry_id" => $GLOBALS["retry_id"] ?? null]); exit(0); }
    function wp_remote_post($url, $args) {
        operation_failure("preflight");
        return ["status" => 200, "body" => json_encode(["embeddings" => [$GLOBALS["mode"] === "preflight-invalid" ? [1, 2] : [1, 2, 3]]])];
    }
    function is_wp_error($value) { return false; }
    function wp_remote_retrieve_response_code($response) { return $response["status"]; }
    function wp_remote_retrieve_body($response) { return $response["body"]; }
    function wp_parse_url($url) { return parse_url($url); }
    function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8"); }
    function esc_attr($value) { return esc_html($value); }
    function wp_unslash($value) { return stripslashes($value); }
    function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', "", strtolower($value)); }
    function sanitize_text_field($value) { return trim(strip_tags($value)); }
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, "RiTriever\\")) {
            require_once __DIR__ . "/../includes/" . str_replace("\\", "/", substr($class, 10)) . ".php";
        }
    });

    use RiTriever\Admin\SettingsPage;
    use RiTriever\Settings;

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function operation_failure(string $operation): void {
        if (($GLOBALS["failure_operation"] ?? "") !== $operation) {
            return;
        }
        if ($GLOBALS["failure_kind"] === "runtime") {
            throw new RuntimeException("Simulated operational failure.");
        }
        throw new TypeError("Simulated implementation defect.");
    }
    $options[RITRIEVER_OPTION_KEY] = array_replace(Settings::DEFAULTS, [
        "embedding_provider" => "custom_http",
        "custom_embedding_endpoint" => "https://example.invalid/embeddings",
        "embedding_dimensions" => 3,
        "kill_switch_global" => $mode === "global-stop",
    ]);
    $failure_handlers = [
        "initialize" => "handle_initialize",
        "ajax" => "handle_ajax_backfill_run",
        "preflight" => "handle_test_embedding",
        "database" => "handle_test_db",
        "retry" => "handle_retry_failed",
    ];
    if (str_starts_with($mode, "failure-")) {
        [, $failure_operation, $failure_kind] = explode("-", $mode);
        try {
            SettingsPage::{$failure_handlers[$failure_operation]}();
        } catch (TypeError $error) {
            echo json_encode(["uncaught_error" => get_class($error)]);
            exit(0);
        }
        throw new LogicException("Expected handler failure");
    }
    if (str_starts_with($mode, "preflight-") || $mode === "global-stop") {
        SettingsPage::handle_test_embedding();
    }
    if (str_starts_with($mode, "retry-")) {
        $_POST = $mode === "retry-one" ? ["post_id" => 7] : [];
        SettingsPage::handle_retry_failed();
    }

    foreach ([
        "preflight-valid" => "embedding_test_ok",
        "preflight-invalid" => "embedding_test_failed",
        "global-stop" => "globally_stopped",
        "retry-all" => "retry_queued",
        "retry-one" => "retry_queued",
        "retry-sync-disabled" => "retry_not_queued",
        "retry-global-stop" => "retry_not_queued",
        "retry-active-job" => "retry_not_queued",
        "retry-no-reason" => "retry_not_queued",
    ] as $case => $expected) {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " " . escapeshellarg($case), $output, $status);
        check($status === 0, "Admin child failed: " . $case);
        $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        parse_str((string) parse_url($result["url"], PHP_URL_QUERY), $args);
        check($args["ritriever_status"] === $expected, "Actual admin handler result: " . $case);
        if ($case === "preflight-invalid") {
            check(str_contains($args["error"], "dimensions do not match"), "safe diagnostic is readable, not double URL encoded");
        }
        if ($case === "retry-all") {
            check($args["total"] === "201" && $result["retry_id"] === null, "retry all uses complete queue snapshot, no capped sync");
        }
        if ($case === "retry-one") {
            check($args["total"] === "1" && $result["retry_id"] === 7, "individual retry queues requested ID");
        }
        if ($case === "retry-all" || $case === "retry-one") {
            $_GET = $args;
            foreach ([
                "en_US" => "Progress below tracks the current retry snapshot.",
                "ja" => "以下の進捗は今回の再試行スナップショットを示します。",
            ] as $locale => $expected_copy) {
                $admin_locale = $locale;
                ob_start();
                (new ReflectionMethod(SettingsPage::class, "render_notice"))->invoke(null);
                $notice = ob_get_clean();
                check(str_contains($notice, $expected_copy), "actual successful retry notice labels snapshot progress in " . $locale);
                check(!str_contains($notice, "includes existing work") && !str_contains($notice, "既存の処理も含みます"), "retry notice does not describe obsolete aggregate progress");
            }
            $admin_locale = "en_US";
        }
        if (array_key_exists($case, $blocked_retry_reasons)) {
            $reason = $blocked_retry_reasons[$case];
            check($args["total"] === "0" && $args["skipped"] === "201", "blocked retry reports no queued work");
            check($args["stop_reason"] === $reason, "actual retry handler preserves the operation-specific stop reason");
            $_GET = $args;
            ob_start();
            (new ReflectionMethod(SettingsPage::class, "render_notice"))->invoke(null);
            $notice = ob_get_clean();
            check(str_contains($notice, "notice-error") && str_contains($notice, "No work was queued."), "blocked retry renders a standard failure notice");
            if ($reason !== "") {
                check(str_contains($notice, esc_html($reason)), "redirected stop reason appears escaped in the actual notice");
            }
            if ($case === "retry-active-job") {
                check(str_contains($notice, "&quot;Pause&quot; &amp; &quot;Cancel&quot;"), "stop reason is HTML escaped");
            }
        }
        foreach ($failure_handlers as $operation => $handler) {
            foreach (["runtime", "type"] as $kind) {
                $output = [];
                exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(__FILE__) . " " . escapeshellarg("failure-$operation-$kind"), $output, $status);
                check($status === 0, "Failure boundary child completed: " . $operation);
                $result = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
                if ($kind === "type") {
                    check(($result["uncaught_error"] ?? "") === "TypeError", "$handler must not swallow programming Errors");
                } elseif ($operation === "ajax") {
                    check(isset($result["ajax_error"]["message"]) && $result["status"] === 500, "AJAX RuntimeException remains a structured failure");
                } else {
                    parse_str((string) parse_url($result["url"], PHP_URL_QUERY), $args);
                    $expected = ["preflight" => "embedding_test_failed", "database" => "db_test_failed"][$operation] ?? "init_failed";
                    check($args["ritriever_status"] === $expected, "$handler retains operational error notice");
                }
            }
        }
    }
    $text = new ReflectionMethod(SettingsPage::class, "text");
    check($text->invoke(null, "save_changes") === "Save Changes", "Japanese site does not force Japanese admin fallback");
    $admin_locale = "ja";
    check($text->invoke(null, "save_changes") === "変更を保存", "Japanese admin gets Japanese fallback");

    $registry = SettingsPage::LIVE_QUERY_REGISTRY_OPTION;
    check($registry === "ritriever_live_query_keys", "admin registry agrees with shared cache contract");
    $store = new ReflectionMethod(SettingsPage::class, "store_live_query_result");
    $store->invoke(null, ["query" => "fixture"]);
    check($live_store_args === ["ritriever_live_query_31", ["query" => "fixture"], 600], "live result uses shared bounded, serialized registry publisher");
    \RiTriever\SearchInterceptor::$store_success = false;
    try {
        $store->invoke(null, ["query" => "fixture"]);
        throw new LogicException("Expected live storage failure");
    } catch (RuntimeException $error) {
        check($error->getMessage() === "Could not store the live query result. Please retry.", "live storage failure is surfaced safely");
    }
    $admin_locale = "en_US";
    ob_start();
    (new ReflectionMethod(SettingsPage::class, "render_index_diagnostics"))->invoke(null);
    $diagnostics = ob_get_clean();
    check(str_contains($diagnostics, "Database &lt;unavailable&gt;; coverage is unknown."), "diagnostic errors are visible and escaped");
    check(str_contains($diagnostics, "Not ready") && str_contains($diagnostics, "Unknown"), "failed diagnostics never report normal readiness or coverage");
    check(!str_contains($diagnostics, "No failed indexing records found."), "unavailable diagnostics do not imply no failures");
    echo "Actual admin preflight/retry/locale/transient checks passed\n";
}
