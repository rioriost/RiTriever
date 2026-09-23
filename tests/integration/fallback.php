<?php

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !defined("WP_CLI")) {
    throw new RuntimeException("Isolated WP-CLI integration runner required.");
}
$capability = \RiTriever\Database\VectorCapabilities::detect();
if ($capability["family"] !== "mysql" || $capability["native_vector"]) {
    throw new RuntimeException("This fixture requires the default unsupported MySQL vector backend.");
}
$settings = \RiTriever\Settings::all();
$settings["search_mode"] = "full";
$settings["sync_enabled"] = false;
update_option(RITRIEVER_OPTION_KEY, $settings, false);
$http_guard = static function ($preempt, $args, $url) {
    if (($args["method"] ?? "") === "POST" && str_contains($url, "/embeddings")) {
        throw new RuntimeException("Unsupported DB must not request query embeddings.");
    }
    return $preempt;
};
add_filter("pre_http_request", $http_guard, 20, 3);
global $wp_query, $wp_the_query, $wpdb;
$query = new WP_Query();
$wp_query = $wp_the_query = $query;
$query->query(["s" => "hello"]);
if ($query->get("orderby") === "post__in" || $query->found_posts < 1 || \RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("MySQL fallback did not preserve the native search.");
}
$table = $wpdb->prefix . "ritriever_chunks";
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))) !== null) {
    throw new RuntimeException("Default MySQL backend must not create vector storage.");
}
remove_filter("pre_http_request", $http_guard, 20);
WP_CLI::success("MySQL native-vector-disabled fallback preserves search without embedding calls");
