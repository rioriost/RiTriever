<?php
if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !defined("WP_CLI")) {
    throw new RuntimeException("Isolated WP-CLI integration runner required.");
}
global $wpdb;
function ritriever_drain_fixture_queue(bool $stop_on_error = false): array
{
    for ($attempt = 0; $attempt < 65; ++$attempt) {
        $state = \RiTriever\BackfillRunner::process_batch();
        if (!in_array($state["status"], ["queued", "running"], true) ||
            ($stop_on_error && $state["last_error"] !== "")) {
            return $state;
        }
        sleep(1);
    }
    throw new RuntimeException("Synthetic queue failed to reach a terminal state.");
}
$id = (int) (get_option("ritriever_fixture_ids", [])[0] ?? 0);
if (!$id || !\RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("Initialize the synthetic fixture before fault injection.");
}
$table = $wpdb->prefix . "ritriever_chunks";
$trigger = $wpdb->prefix . "ritriever_test_insert_failure";
$before = $wpdb->get_results($wpdb->prepare(
    "SELECT chunk_index, chunk_text, content_hash FROM %i WHERE post_id = %d ORDER BY chunk_index",
    $table, $id,
), ARRAY_A);
$hash = get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true);
if ($wpdb->query($wpdb->prepare(
    "CREATE TRIGGER %i BEFORE INSERT ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected integration insert failure'",
    $trigger, $table,
)) === false) {
    throw new RuntimeException("Could not install the isolated failure trigger.");
}
try {
    wp_update_post(["ID" => $id, "post_content" => "Changed synthetic content after successful indexing"]);
    $failed_state = ritriever_drain_fixture_queue(true);
    $after = $wpdb->get_results($wpdb->prepare(
        "SELECT chunk_index, chunk_text, content_hash FROM %i WHERE post_id = %d ORDER BY chunk_index",
        $table, $id,
    ), ARRAY_A);
    if ($before !== $after || get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true) !== $hash) {
        throw new RuntimeException(sprintf(
            "Failed INSERT integrity check: post=%d chunks=%d->%d rows_equal=%s hash=%s->%s error=%s",
            $id, count($before), count($after), $before === $after ? "yes" : "no",
            $hash, get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true),
            get_post_meta($id, RITRIEVER_POSTMETA_LAST_ERROR, true),
        ));
    }
    if (get_post_meta($id, RITRIEVER_POSTMETA_LAST_ERROR, true) === "" && $failed_state["last_error"] === "") {
        throw new RuntimeException("SQL failure did not surface an indexing error.");
    }
} finally {
    if ($wpdb->query($wpdb->prepare("DROP TRIGGER IF EXISTS %i", $trigger)) === false) {
        throw new RuntimeException("Could not remove the isolated failure trigger.");
    }
}
$retry = \RiTriever\BackfillRunner::retry_failed([$id]);
if ($retry["pending"] === 0 &&
    !in_array($retry["state"]["status"], ["queued", "running"], true)) {
    throw new RuntimeException("Failure was neither queued for retry nor retained by the watchdog.");
}
ritriever_drain_fixture_queue();
if (get_post_meta($id, RITRIEVER_POSTMETA_LAST_ERROR, true) !== "" ||
    get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true) === $hash) {
    throw new RuntimeException("Retry did not recover the failed replacement.");
}
WP_CLI::success("Real INSERT failure preserved old chunks and metadata; retry recovered");
