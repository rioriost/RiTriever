<?php

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !defined("WP_CLI")) {
    throw new RuntimeException("Isolated WP-CLI integration runner required.");
}
if (!\RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("Initialize the index before testing retrieval.");
}
global $wp_query, $wp_the_query;
function ritriever_fixture_search(array $vars): WP_Query
{
    global $wp_query, $wp_the_query;
    \RiTriever\SearchInterceptor::reset_runtime_state();
    $query = new WP_Query();
    $wp_query = $wp_the_query = $query;
    $query->query(array_replace(["s" => "Synthetic", "posts_per_page" => 1], $vars));
    return $query;
}

$id = (int) get_option("ritriever_fixture_ids")[2];
$query = ritriever_fixture_search(["post__in" => [$id]]);
if (wp_list_pluck($query->posts, "ID") !== [$id]) {
    throw new RuntimeException("Hybrid search failed to preserve the original inclusion constraint.");
}
if ($query->get("orderby") !== "post__in") {
    throw new RuntimeException("Healthy fixture did not exercise the hybrid query rewrite.");
}
\RiTriever\SearchInterceptor::purge_query_cache();
$fault = static fn() => new WP_Error("fixture_outage", "Injected embedding outage");
add_filter("pre_http_request", $fault, 20);
$fallback = ritriever_fixture_search([]);
remove_filter("pre_http_request", $fault, 20);
$settings = \RiTriever\Settings::all();
$settings["search_mode"] = "off";
update_option(RITRIEVER_OPTION_KEY, $settings, false);
$native = ritriever_fixture_search([]);
if (wp_list_pluck($fallback->posts, "ID") !== wp_list_pluck($native->posts, "ID") ||
    $fallback->found_posts !== $native->found_posts ||
    $fallback->max_num_pages !== $native->max_num_pages) {
    throw new RuntimeException("Embedding failure changed native membership or pagination.");
}
$settings["search_mode"] = "full";
update_option(RITRIEVER_OPTION_KEY, $settings, false);
WP_CLI::success("Real query inclusion and outage fallback pagination verified");
