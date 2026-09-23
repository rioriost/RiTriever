<?php
declare(strict_types=1);

const RITRIEVER_VERSION = "test";
const RITRIEVER_OPTION_KEY = "ritriever_settings";
const ABSPATH = __DIR__ . "/";

$options = [];
$blog = 1;
$translations = [];
$site_locale = "en_US";
$requests = [];
$response = [];
$cache_deletes = [];
function get_current_blog_id(): int { return $GLOBALS["blog"]; }
function get_option($key, $default = false) { return $GLOBALS["options"][$GLOBALS["blog"]][$key] ?? $default; }
function update_option($key, $value, $autoload = false): bool { $GLOBALS["options"][$GLOBALS["blog"]][$key] = $value; return true; }
function get_locale(): string { return $GLOBALS["site_locale"]; }
function wp_get_available_translations(): array { return $GLOBALS["translations"]; }
function __($text, $domain): string { return $text; }
function wp_remote_post($url, $args) { $GLOBALS["requests"][] = [$url, $args]; return $GLOBALS["response"]; }
function is_wp_error($value): bool { return $value instanceof RuntimeException; }
function wp_remote_retrieve_response_code($response): int { return $response["status"]; }
function wp_remote_retrieve_body($response): string { return $response["body"]; }
function wp_remote_retrieve_header($response, $name): string { return $response["headers"][$name] ?? ""; }
function wp_parse_url($url) { return parse_url($url); }
function wp_cache_delete($key, $group): bool { $GLOBALS["cache_deletes"][] = [$key, $group]; return true; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, "RiTriever\\")) {
        require_once __DIR__ . "/../includes/" . str_replace("\\", "/", substr($class, 10)) . ".php";
    }
});

use RiTriever\Settings;
use RiTriever\LanguageOptions;
use RiTriever\Embedding\EmbeddingProviderFactory;
use RiTriever\Embedding\EmbeddingProviderException;
use RiTriever\Embedding\EmbeddingResponseValidator as Validator;

$checks = 0;
function check(bool $condition, string $message): void {
    $GLOBALS["checks"]++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejected(callable $test, string $message): EmbeddingProviderException {
    try { $test(); } catch (EmbeddingProviderException $error) {
        check(true, $message);
        return $error;
    }
    throw new RuntimeException("Expected rejection: " . $message);
}
function configure(array $values): void {
    update_option(RITRIEVER_OPTION_KEY, array_replace(Settings::DEFAULTS, $values));
    Settings::clear_cache();
}
function reply(array $payload, int $status = 200, array $headers = []): void {
    $GLOBALS["response"] = ["status" => $status, "body" => json_encode($payload), "headers" => $headers];
}

foreach (["azure_openai", "ollama", "infinity", "tei", "lmstudio", "custom_http"] as $provider) {
    configure([
        "embedding_provider" => $provider,
        "custom_embedding_preset" => Settings::default_custom_preset_for_provider($provider),
        "custom_embedding_endpoint" => "https://example.invalid/actual-deployment",
        "custom_embedding_model" => "my-edited-model",
        "custom_embedding_format" => $provider === "azure_openai" ? "azure_openai" : "openai_compatible",
        "embedding_dimensions" => 3,
        "custom_embedding_api_key" => "never-echo-this-secret",
        "openai_api_key" => "keep-openai-key",
    ]);
    foreach ([Settings::all(), Settings::sanitize(["top_k" => 12, "custom_embedding_api_key" => ""])] as $value) {
        check($value["custom_embedding_endpoint"] === "https://example.invalid/actual-deployment", "$provider endpoint persists");
        check($value["custom_embedding_model"] === "my-edited-model" && $value["embedding_dimensions"] === 3, "$provider customization persists");
        check($value["custom_embedding_api_key"] === "never-echo-this-secret" && $value["openai_api_key"] === "keep-openai-key", "partial form preserves secrets");
    }
    Settings::install_or_upgrade();
    check(Settings::get("custom_embedding_endpoint") === "https://example.invalid/actual-deployment", "upgrade preserves endpoint");
    reply(["embeddings" => [[1, 2, 3]]]);
    EmbeddingProviderFactory::make()->embed("hello");
    check($requests[array_key_last($requests)][0] === "https://example.invalid/actual-deployment", "factory uses saved endpoint");
}
configure([]);
$seed = Settings::sanitize(["embedding_provider" => "ollama"]);
check($seed["custom_embedding_model"] === "nomic-embed-text" && $seed["embedding_dimensions"] === 768, "new provider seeds missing fields");
configure(["embedding_provider" => "azure_openai", "custom_embedding_preset" => "azure_openai_3_small"]);
$seed = Settings::sanitize(["embedding_provider" => "ollama"]);
check($seed["custom_embedding_preset"] === "ollama_nomic" && $seed["custom_embedding_format"] === "ollama", "provider change selects matching default");
update_option(RITRIEVER_OPTION_KEY, ["embedding_provider" => "tei"]);
Settings::clear_cache();
check(Settings::get("custom_embedding_model") === "BAAI/bge-m3" && Settings::get("embedding_dimensions") === 1024, "first-use missing fields seeded");
configure(["embedding_provider" => "openai", "openai_embedding_model" => "text-embedding-3-large", "embedding_dimensions" => 3]);
check(Settings::get("embedding_dimensions") === 3072, "OpenAI model dimensions remain authoritative");

configure(["target_locale" => "ja", "search_mode" => "full"]);
$translations = ["ja" => ["native_name" => "日本語", "english_name" => "Japanese"]];
$prefix = LanguageOptions::with_embedding_context("hello");
$translations = [];
check(LanguageOptions::with_embedding_context("hello") === $prefix, "translation outage cannot change embedding bytes");
check(isset(LanguageOptions::options()["ja"]), "saved locale remains selectable during outage");
$translations = ["ja" => ["native_name" => "changed", "english_name" => "changed"]];
check(LanguageOptions::with_embedding_context("hello") === $prefix, "label change cannot change embedding bytes");
$blog = 2;
configure(["search_mode" => "off", "target_locale" => "site"]);
check(Settings::get("search_mode") === "off", "second blog settings isolated");
$site_locale = "fr_FR";
check(LanguageOptions::selected_locale() === "fr_FR", "site locale uses effective site language");
$blog = 1;
check(Settings::get("search_mode") === "full", "restored blog settings isolated");
$fresh = get_option(RITRIEVER_OPTION_KEY);
$fresh["top_k"] = 17;
update_option(RITRIEVER_OPTION_KEY, $fresh);
Settings::reset_cache();
check(Settings::get("top_k") === 17, "commit guard reset reloads persisted settings");
check(array_slice($cache_deletes, -3) === [[RITRIEVER_OPTION_KEY, "options"], ["alloptions", "options"], ["notoptions", "options"]], "commit guard evicts option and aggregate caches");
$fresh["top_k"] = 19;
update_option(RITRIEVER_OPTION_KEY, $fresh);
Settings::refresh();
check(Settings::get("top_k") === 19, "refresh eagerly reloads persisted configuration");
$site_locale = "en_US";

$a = [1, 2, 3]; $b = [4, 5, 6];
$ordered = Validator::from_payload(["data" => [
    ["index" => 1, "embedding" => $b], ["index" => 0, "embedding" => $a],
]], 2, 3, "cosine", true);
check($ordered === [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], "indexed batch restores input order");
foreach ([
    [["index" => 0, "embedding" => $a]],
    [["index" => 0, "embedding" => $a], ["index" => 0, "embedding" => $b]],
    [["index" => 2, "embedding" => $a], ["index" => 1, "embedding" => $b]],
    [["embedding" => $a], ["index" => 1, "embedding" => $b]],
    [["index" => "0", "embedding" => $a], ["index" => 1, "embedding" => $b]],
] as $rows) {
    rejected(fn() => Validator::from_payload(["data" => $rows], 2, 3), "bad batch indices/count");
}
foreach ([[1, 2], [1, "2", 3], [1, null, 3], [1, NAN, 3], [1, INF, 3], [1, true, 3], [1, 1e100, 3], [0, 0, 0], [1e-100, 0, 0]] as $bad) {
    rejected(fn() => Validator::validate([$bad], 1, 3), "invalid vector");
}
check(Validator::validate([[0, 0, 0]], 1, 3, "euclidean") === [[0.0, 0.0, 0.0]], "euclidean permits zero vector");
foreach ([
    ["embeddings" => [$a]], ["embeddings" => ["float" => [$a]]],
    ["embedding" => $a], ["data" => [["embedding" => $a]]],
] as $payload) {
    check(Validator::from_payload($payload, 1, 3) === [[1.0, 2.0, 3.0]], "legacy positional format");
}

configure(["embedding_provider" => "custom_http", "custom_embedding_endpoint" => "https://example.invalid/embedding", "embedding_dimensions" => 3]);
$provider = EmbeddingProviderFactory::make();
foreach ([401, 429, 503] as $status) {
    foreach (["secret request content", ["message" => "secret request content", "code" => "secret-key"]] as $envelope) {
        reply(["embeddings" => [$a], "error" => $envelope], $status, ["retry-after" => "7"]);
        $error = rejected(fn() => $provider->embed("hello"), "HTTP failure rejected before vector parsing");
        check($error->http_status === $status && $error->retryable === ($status !== 401) && $error->retry_after === 7, "safe retry metadata");
        check($error->is_retryable() === ($status !== 401) && $error->retry_after() === 7, "queue retry classification methods");
        check(!str_contains($error->getMessage(), "secret"), "error envelope not leaked");
    }
}
reply(["embeddings" => [$a]], 429, ["retry-after" => gmdate("D, d M Y H:i:s", time() + 60) . " GMT"]);
$error = rejected(fn() => $provider->embed("hello"), "date Retry-After");
check($error->retry_after > 0 && $error->retry_after <= 60, "date Retry-After parsed");
$response = new RuntimeException("private https://secret-key.invalid");
$error = rejected(fn() => $provider->embed("hello"), "transport failure");
check($error->retryable && !str_contains($error->getMessage(), "secret"), "transport detail not leaked");
$response = ["status" => 200, "body" => "{broken"];
rejected(fn() => $provider->embed("hello"), "malformed JSON");
$count = count($requests);
rejected(fn() => $provider->embed("\xFF"), "invalid input UTF-8");
check(count($requests) === $count, "invalid input not sent");
reply(["embeddings" => [[1, 2]]]);
rejected(fn() => $provider->embed("hello"), "configured dimensions enforced");
reply(["embeddings" => [$a]]);
rejected(fn() => $provider->embed_many(["one", "two"]), "short positional batch rejected");

configure(["embedding_provider" => "azure_openai", "custom_embedding_endpoint" => Settings::custom_embedding_presets()["azure_openai_3_small"]["endpoint"], "custom_embedding_format" => "azure_openai"]);
$count = count($requests);
rejected(fn() => EmbeddingProviderFactory::make()->embed("hello"), "Azure placeholder");
check(count($requests) === $count, "placeholder never sent");
foreach (["https:///embeddings", "/embeddings", "file:///embeddings", "https://bad host/embeddings"] as $endpoint) {
    configure(["embedding_provider" => "custom_http", "custom_embedding_endpoint" => $endpoint, "embedding_dimensions" => 3]);
    rejected(fn() => EmbeddingProviderFactory::make()->embed("hello"), "endpoint requires an HTTP host");
}
check(count($requests) === $count, "invalid endpoint never sent");
configure(["kill_switch_global" => true, "openai_api_key" => "fake"]);
rejected(fn() => EmbeddingProviderFactory::make()->embed("hello"), "global stop");
check(count($requests) === $count, "global stop never sends HTTP");

configure(["openai_api_key" => "fake"]);
$vector = array_fill(0, 1536, 0.25);
reply(["data" => [["index" => 1, "embedding" => $vector], ["index" => 0, "embedding" => array_fill(0, 1536, 0.5)]]]);
$vectors = EmbeddingProviderFactory::make()->embed_many(["first", "second"]);
check($vectors[0][0] === 0.5 && $vectors[1][0] === 0.25, "real OpenAI adapter applies indexed validator");
reply(["data" => [["embedding" => $vector]]]);
rejected(fn() => EmbeddingProviderFactory::make()->embed("hello"), "OpenAI requires index");

echo "Provider/settings/locale contracts: $checks checks passed\n";
