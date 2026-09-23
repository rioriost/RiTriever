<?php

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !\RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("Isolated initialized integration installation required.");
}

// This tests retrieval routing, not a vendor model's Japanese language quality.
$embedding = static function ($preempt, array $args, string $url) {
    if ($url !== "https://ritriever.invalid/embeddings") {
        return $preempt;
    }
    $body = json_decode($args["body"], true, 512, JSON_THROW_ON_ERROR);
    $data = [];
    foreach ((array) $body["input"] as $index => $text) {
        $data[] = ["index" => $index, "embedding" => array_fill(0, 16, 0.5)];
    }
    return [
        "response" => ["code" => 200, "message" => "OK"],
        "body" => wp_json_encode(["data" => $data]),
        "headers" => [], "cookies" => [],
    ];
};
add_filter("pre_http_request", $embedding, 20, 3);
$id = wp_insert_post([
    "post_type" => "post", "post_status" => "publish",
    "post_title" => "ミツバチの飼育",
    "post_content" => "ミツバチを観察するための合成テスト記事。",
], true);
if (is_wp_error($id)) {
    throw new RuntimeException($id->get_error_message());
}
for ($attempt = 0; $attempt < 30; ++$attempt) {
    $state = \RiTriever\BackfillRunner::process_batch();
    if ($state["status"] === "complete" && $state["errors"] === 0) {
        break;
    }
    if (!in_array($state["status"], ["queued", "running"], true)) {
        throw new RuntimeException("Synthetic synonym indexing failed.");
    }
    sleep(1);
}
if (!\RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("Synthetic synonym index did not become ready.");
}
$native = new WP_Query(["s" => "蜜蜂", "post__in" => [$id], "fields" => "ids"]);
if ($native->posts !== []) {
    throw new RuntimeException("Synonym fixture unexpectedly has a literal match.");
}
$exclude = false;
$main_hook_calls = 0;
$theme_hook = static function (WP_Query $query) use ($id, &$exclude, &$main_hook_calls): void {
    if ($query->is_main_query() && $query->is_search()) {
        ++$main_hook_calls;
        $query->set("post__in", [$id]);
        $query->set("posts_per_page", 3);
        if ($exclude) {
            $query->set("post__not_in", [$id]);
        }
    }
};
$chart_hook = static function (WP_Query $query): void {
    if (!$query->is_main_query() && $query->get("post_type") === "visualizer") {
        $query->set("orderby", "title");
    }
};
add_action("pre_get_posts", $chart_hook, 10);
add_action("pre_get_posts", $theme_hook, 100);
try {
    foreach (["蜜蜂", "ミツバチ"] as $term) {
        \RiTriever\SearchInterceptor::reset_runtime_state();
        $query = new WP_Query();
        $GLOBALS["wp_query"] = $GLOBALS["wp_the_query"] = $query;
        $query->query(["s" => $term]);
        if ($query->get("orderby") !== "post__in" ||
            $query->get("posts_per_page") !== 3 ||
            wp_list_pluck($query->posts, "ID") !== [$id]) {
            throw new RuntimeException("Theme/chart hooks suppressed synonym retrieval or lost effective constraints.");
        }
    }
    $exclude = true;
    \RiTriever\SearchInterceptor::reset_runtime_state();
    $query = new WP_Query();
    $GLOBALS["wp_query"] = $GLOBALS["wp_the_query"] = $query;
    $query->query(["s" => "蜜蜂"]);
    if ($query->posts !== [] || $main_hook_calls !== 3) {
        throw new RuntimeException("A main-query-only theme exclusion was not preserved.");
    }
} finally {
    remove_action("pre_get_posts", $chart_hook, 10);
    remove_action("pre_get_posts", $theme_hook, 100);
    remove_filter("pre_http_request", $embedding, 20);
}
WP_CLI::success("Literal ミツバチ and semantic-only 蜜蜂 both work with chart/theme hooks; exclusions remain intact");
