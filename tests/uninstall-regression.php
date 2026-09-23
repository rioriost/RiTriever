<?php
declare(strict_types=1);

const WP_UNINSTALL_PLUGIN = true;
$options = [
    "ritriever_query_cache_keys" => ["ritriever_q_registered" => time(), "ritriever_live_query_shared" => time(), "unrelated" => time()],
    "ritriever_live_query_keys" => ["ritriever_live_query_registered" => time()],
    "ritriever_live_query_transient_keys" => ["ritriever_live_query_new" => time()],
    "ritriever_query_cache_generation" => "old",
    "ritriever_index_state" => ["ready" => true],
];
$cache = [
    "ritriever_q_registered" => "cached query",
    "ritriever_live_query_registered" => "live query",
    "ritriever_live_query_shared" => "shared registry",
    "ritriever_live_query_new" => "new registry",
    "unrelated" => "must survive",
];
$unscheduled = [];
function is_multisite(): bool { return false; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS["options"][$key] ?? $default; }
function delete_option(string $key): bool { unset($GLOBALS["options"][$key]); return true; }
function delete_transient(string $key): bool { unset($GLOBALS["cache"][$key]); return true; }
function wp_unschedule_hook(string $hook): int { $GLOBALS["unscheduled"][] = $hook; return 1; }
$wpdb = new class {
    public string $prefix = "wp_";
    public string $postmeta = "wp_postmeta";
    public string $options = "wp_options";
    public function query(string $sql): int { return 1; }
    public function prepare(string $sql, mixed ...$args): string { return $sql; }
    public function get_col(string $sql): array { return []; }
    public function esc_like(string $text): string { return $text; }
};
require dirname(__DIR__) . "/uninstall.php";
if ($cache !== ["unrelated" => "must survive"] || $options !== []) {
    throw new RuntimeException("Uninstall must delete tracked external cache before its registry, without flushing unrelated data");
}
if (!in_array("ritriever_sync_post", $unscheduled, true)) {
    throw new RuntimeException("Uninstall must remove post-specific cron hooks");
}
echo "Uninstall regression tests passed\n";
