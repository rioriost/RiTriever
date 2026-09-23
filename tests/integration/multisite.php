<?php
if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !is_multisite()) {
    throw new RuntimeException("Isolated multisite integration installation required.");
}

$first = get_current_blog_id();
$before = \RiTriever\Settings::all();
$second = wpmu_create_blog("ritriever.invalid", "/second/", "Synthetic second site", 1);
if (is_wp_error($second)) {
    throw new RuntimeException($second->get_error_message());
}
switch_to_blog((int) $second);
try {
    $settings = \RiTriever\Settings::all();
    $settings["search_mode"] = "off";
    $settings["custom_embedding_endpoint"] = "https://second.invalid/embeddings";
    update_option(RITRIEVER_OPTION_KEY, $settings, false);
    if (\RiTriever\Settings::get("custom_embedding_endpoint") !== "https://second.invalid/embeddings") {
        throw new RuntimeException("Site two settings were not isolated.");
    }
    global $wpdb;
    $jobs_table = $wpdb->prefix . "ritriever_backfill_jobs";
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($jobs_table))) !== $jobs_table) {
        throw new RuntimeException("Network-active plugin did not initialize the new site.");
    }
} finally {
    restore_current_blog();
}
if (get_current_blog_id() !== $first || \RiTriever\Settings::all() !== $before) {
    throw new RuntimeException("Original site settings did not survive switch/restore.");
}
WP_CLI::success("Multisite settings isolation and new-site initialization verified");
