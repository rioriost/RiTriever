<?php
declare(strict_types=1);

namespace RiTriever {
    final class Settings
    {
        public static function clear_cache(): void { $GLOBALS["events"][] = "flush"; }
        public static function install_or_upgrade(): void { self::clear_cache(); }
        public static function clear_initial_backfill_state(string $reason): void { $GLOBALS["events"][] = "reset"; }
    }
    final class IndexState
    {
        public static function fingerprint(?array $settings = null): string
        {
            unset($settings["openai_api_key"]);
            return hash("sha256", json_encode($settings));
        }
        public static function invalidate(string $reason): void { $GLOBALS["events"][] = "invalidate"; }
        public static function ensure_current(): void { $GLOBALS["events"][] = "ensure"; }
    }
    final class BackfillRunner
    {
        public static function clear_queue(): void { $GLOBALS["events"][] = "clear_queue"; }
    }
    final class SearchInterceptor
    {
        public static function purge_query_cache(): void { $GLOBALS["events"][] = "purge"; }
    }
    final class Logger
    {
        public static function error(string $channel, string $message): void { $GLOBALS["events"][] = "error"; }
    }
}
namespace RiTriever\Database {
    final class VectorSchema
    {
        public static function install_or_upgrade(): void
        {
            if ($GLOBALS["fail_schema"]) {
                throw new \RuntimeException("Injected schema failure");
            }
            $GLOBALS["events"][] = "schema:" . $GLOBALS["site"];
        }
    }
    final class BackfillQueueSchema
    {
        public static function install_or_upgrade(): void {}
    }
}
namespace {
    const RITRIEVER_ADMIN_CAPABILITY = "manage_options";
    const RITRIEVER_PLUGIN_FILE = "ritriever.php";
    $events = [];
    $site = 1;
    $stack = [];
    $options = [];
    $fail_schema = false;
    function is_multisite(): bool { return true; }
    function get_sites(array $args): array { return array_slice(range(1, 102), $args["offset"], $args["number"]); }
    function switch_to_blog(int $id): void
    {
        $GLOBALS["stack"][] = $GLOBALS["site"];
        $GLOBALS["site"] = $id;
        \RiTriever\Plugin::on_switch_blog();
    }
    function restore_current_blog(): void
    {
        $GLOBALS["site"] = array_pop($GLOBALS["stack"]);
        \RiTriever\Plugin::on_switch_blog();
    }
    function update_option(string $key, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS["options"][$GLOBALS["site"]][$key] = $value;
        return true;
    }
    function delete_option(string $key): bool { unset($GLOBALS["options"][$GLOBALS["site"]][$key]); return true; }
    function get_option(string $key, mixed $default = false): mixed { return $GLOBALS["options"][$GLOBALS["site"]][$key] ?? $default; }
    function expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    require dirname(__DIR__) . "/includes/Plugin.php";

    foreach (["custom_embedding_endpoint", "chunk_max_chars", "post_types", "vector_distance"] as $key) {
        $events = [];
        \RiTriever\Plugin::on_settings_updated([$key => "old"], [$key => "new"]);
        expect($events === ["flush", "invalidate"], "$key must use the atomic invalidation boundary without DDL");
    }
    $events = [];
    \RiTriever\Plugin::on_settings_updated(["openai_api_key" => "old"], ["openai_api_key" => "new"]);
    expect($events === ["flush"], "Credential rotation must not rebuild");
    $events = [];
    \RiTriever\Plugin::activate(true);
    expect(count(array_filter($events, static fn($event) => str_starts_with($event, "schema:"))) === 102, "Paginated network activation must initialize every site");
    expect($site === 1 && $stack === [], "Network operation must restore the original blog");
    $fail_schema = true;
    \RiTriever\Plugin::install_current_site();
    expect(get_option("ritriever_setup_error") === "Injected schema failure", "Schema failure must be surfaced");
    expect(end($events) === "error", "Schema failure must be logged");
    $fail_schema = false;
    \RiTriever\Plugin::install_current_site();
    expect(get_option("ritriever_setup_error") === false, "Successful retry must clear setup error");
    echo "Lifecycle regression tests passed\n";
}
