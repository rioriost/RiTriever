<?php

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1" || !\RiTriever\IndexState::is_ready()) {
    throw new RuntimeException("Isolated initialized integration installation required.");
}
function ritriever_wait_for_changes(): void
{
    for ($attempt = 0; $attempt < 65; ++$attempt) {
        $state = \RiTriever\BackfillRunner::process_batch();
        if ($state["status"] === "complete" && $state["errors"] === 0) {
            return;
        }
        if (!in_array($state["status"], ["queued", "running"], true)) {
            throw new RuntimeException("Change queue failed: " . $state["status"]);
        }
        sleep(1);
    }
    throw new RuntimeException("Change queue did not complete.");
}
global $wpdb;
$id = (int) get_option("ritriever_fixture_ids")[1];
$table = $wpdb->prefix . "ritriever_chunks";
$old_hash = get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true);
update_post_meta($id, "ritriever_fixture", "metadata-only-" . time());
ritriever_wait_for_changes();
if (get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true) === $old_hash) {
    throw new RuntimeException("Metadata-only change did not reach the index.");
}
$term = wp_insert_term("Synthetic category " . $id, "category");
if (is_wp_error($term)) {
    throw new RuntimeException($term->get_error_message());
}
wp_set_object_terms($id, [(int) $term["term_id"]], "category");
ritriever_wait_for_changes();
$old_hash = get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true);
wp_update_term((int) $term["term_id"], "category", ["name" => "Renamed synthetic category " . $id]);
ritriever_wait_for_changes();
if (get_post_meta($id, RITRIEVER_POSTMETA_CONTENT_HASH, true) === $old_hash) {
    throw new RuntimeException("Term rename did not reach the index.");
}
wp_update_post(["ID" => $id, "post_status" => "draft"]);
ritriever_wait_for_changes();
if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_id = %d", $table, $id)) !== 0) {
    throw new RuntimeException("Unpublished post retained indexed chunks.");
}
wp_update_post(["ID" => $id, "post_status" => "publish"]);
ritriever_wait_for_changes();
if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_id = %d", $table, $id)) === 0) {
    throw new RuntimeException("Republished content was skipped by stale hash.");
}
$wpdb->query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d", $table, $id));
\RiTriever\BackfillRunner::retry_failed([$id]);
ritriever_wait_for_changes();
if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE post_id = %d", $table, $id)) === 0) {
    throw new RuntimeException("Explicit retry did not repair missing chunks.");
}
WP_CLI::success("Metadata, taxonomy rename, republication and missing-row repair verified");
