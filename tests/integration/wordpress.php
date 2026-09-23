<?php
if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !defined("WP_CLI")) {
    throw new RuntimeException("Isolated WP-CLI integration runner required.");
}

function ritriever_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$phase = $args[0] ?? "verify";
global $wpdb;
if ($phase === "prepare") {
    $settings = array_replace(\RiTriever\Settings::all(), [
        "embedding_provider" => "custom_http",
        "custom_embedding_preset" => "custom",
        "custom_embedding_endpoint" => "https://ritriever.invalid/embeddings",
        "custom_embedding_model" => "fixture-16",
        "custom_embedding_format" => "openai_compatible",
        "embedding_dimensions" => 16,
        "openai_api_key" => "",
        "custom_embedding_api_key" => "",
        "sync_enabled" => true,
        "search_mode" => "full",
        "post_types" => ["post"],
        "post_statuses" => ["publish"],
        "indexed_custom_fields" => ["ritriever_fixture"],
        "indexed_taxonomies" => ["category"],
    ]);
    update_option(RITRIEVER_OPTION_KEY, $settings, false);
    $ids = [];
    foreach (["日本語の検索", "apple without excluded fruit", "Synthetic vector article"] as $title) {
        $id = wp_insert_post([
            "post_type" => "post",
            "post_status" => "publish",
            "post_title" => $title,
            "post_content" => str_repeat("日本語とemoji 😀 test content. ", 180),
        ], true);
        ritriever_test_expect(!is_wp_error($id), "Fixture post creation failed");
        $ids[] = $id;
    }
    update_option("ritriever_fixture_ids", $ids, false);
    WP_CLI::success("Prepared synthetic integration posts");
    return;
}

$ids = get_option("ritriever_fixture_ids", []);
ritriever_test_expect(count($ids) === 3, "Fixture posts missing");
$table = $wpdb->prefix . "ritriever_chunks";
foreach ($ids as $id) {
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_id = %d", $table, $id));
    ritriever_test_expect($count > 0, "Missing vector rows for post " . $id);
    ritriever_test_expect(get_post_meta($id, RITRIEVER_POSTMETA_LAST_ERROR, true) === "", "Unexpected post indexing error");
}
ritriever_test_expect(\RiTriever\IndexState::is_ready(), "Index should be ready");
$before = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table));
$settings = \RiTriever\Settings::all();
$settings["chunk_max_chars"] = (int) $settings["chunk_max_chars"] + 1;
update_option(RITRIEVER_OPTION_KEY, $settings, false);
ritriever_test_expect(!\RiTriever\IndexState::is_ready(), "Input setting change must invalidate readiness");
ritriever_test_expect($before === $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table)), "Settings save must not destroy vector rows");
WP_CLI::success("Real MariaDB rows, readiness and nondestructive invalidation verified");
