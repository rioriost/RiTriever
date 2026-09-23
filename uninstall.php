<?php
/**
 * Uninstall cleanup for RiTriever.
 *
 * @package RiTriever
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall cleanup must remove plugin-owned options, metadata, transients, and custom tables across sites without caching.

if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}

/**
 * Remove all RiTriever data for the current site.
 */
function ritriever_uninstall_site(): void
{
    global $wpdb;

    ritriever_unschedule_site_cron();

    ritriever_delete_registered_transients("ritriever_query_cache_keys", "ritriever_q_");
    ritriever_delete_registered_transients("ritriever_query_cache_keys", "ritriever_live_query_");
    ritriever_delete_registered_transients("ritriever_live_query_keys", "ritriever_live_query_");
    ritriever_delete_registered_transients("ritriever_live_query_transient_keys", "ritriever_live_query_");

    ritriever_delete_transients("ritriever_q_");
    ritriever_delete_transients("ritriever_live_query_");

    foreach ([
        "ritriever_settings",
        "ritriever_backfill_queue",
        "ritriever_query_cache_keys",
        "ritriever_live_query_keys",
        "ritriever_live_query_transient_keys",
        "ritriever_query_cache_generation",
        "ritriever_index_state",
        "ritriever_setup_error",
        "ritriever_log",
    ] as $option) {
        delete_option($option);
    }

    $postmeta_keys = [
        "_ritriever_content_hash",
        "_ritriever_indexed_at",
        "_ritriever_last_error",
        "_ritriever_index_generation",
    ];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM %i WHERE meta_key IN (%s, %s, %s, %s)",
            $wpdb->postmeta,
            $postmeta_keys[0],
            $postmeta_keys[1],
            $postmeta_keys[2],
            $postmeta_keys[3],
        ),
    );

    $table = $wpdb->prefix . "ritriever_chunks";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));

    $queue_items_table = $wpdb->prefix . "ritriever_backfill_items";
    $queue_jobs_table = $wpdb->prefix . "ritriever_backfill_jobs";
    $receipts_table = $wpdb->prefix . "ritriever_indexed_posts";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $queue_items_table));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $queue_jobs_table));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $receipts_table));
}

function ritriever_delete_registered_transients(string $option, string $prefix): void
{
    $registered = get_option($option, []);
    foreach (is_array($registered) ? $registered : [] as $key => $value) {
        $name = is_string($key) ? $key : $value;
        if (is_string($name) && str_starts_with($name, $prefix)) {
            delete_transient($name);
        }
    }
}

/**
 * Remove RiTriever transients for the current site.
 */
function ritriever_delete_transients(string $prefix): void
{
    global $wpdb;

    $transient_like = $wpdb->esc_like("_transient_" . $prefix) . "%";
    $timeout_like = $wpdb->esc_like("_transient_timeout_" . $prefix) . "%";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $option_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->options,
            $transient_like,
            $timeout_like,
        ),
    );

    $keys = [];
    foreach (is_array($option_names) ? $option_names : [] as $option_name) {
        $option_name = (string) $option_name;
        if (str_starts_with($option_name, "_transient_timeout_")) {
            $keys[] = substr($option_name, strlen("_transient_timeout_"));
        } elseif (str_starts_with($option_name, "_transient_")) {
            $keys[] = substr($option_name, strlen("_transient_"));
        }
    }

    foreach (array_unique($keys) as $key) {
        if (str_starts_with((string) $key, $prefix)) {
            delete_transient((string) $key);
        }
    }
}

/**
 * Remove pending RiTriever cron events for the current site.
 */
function ritriever_unschedule_site_cron(): void
{
    foreach (["ritriever_process_backfill_queue", "ritriever_sync_post", "ritriever_resync_term"] as $hook) {
        wp_unschedule_hook($hook);
    }
}

if (is_multisite()) {
    $offset = 0;
    do {
        $site_ids = get_sites(["fields" => "ids", "number" => 100, "offset" => $offset]);
        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                ritriever_uninstall_site();
            } finally {
                restore_current_blog();
            }
        }
        $offset += count($site_ids);
    } while (count($site_ids) === 100);
} else {
    ritriever_uninstall_site();
}
