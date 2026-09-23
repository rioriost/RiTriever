<?php
/**
 * Post indexing hooks.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Database\LocalVectorRepository;

final class PostSync
{
    private function __construct() {}

    public static function register(): void
    {
        add_action("save_post", [self::class, "on_save_post"], 20, 2);
        add_action("wp_after_insert_post", [self::class, "on_save_post"], 100, 2);
        foreach (["added_post_meta", "updated_post_meta", "deleted_post_meta"] as $hook) {
            add_action($hook, [self::class, "on_meta_change"], 20, 4);
        }
        add_action("set_object_terms", [self::class, "on_set_terms"], 20, 4);
        add_action("deleted_term_relationships", [self::class, "on_deleted_relationships"], 20, 3);
        add_action("edited_term", [self::class, "on_edited_term"], 20, 3);
        add_action("delete_term", [self::class, "on_deleted_term"], 20, 5);
        add_action("ritriever_resync_term", [self::class, "resync_term"], 10, 3);
        add_action(
            "before_delete_post",
            [self::class, "on_delete_post"],
            10,
            1,
        );
    }

    public static function on_save_post(int $post_id, $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (
            !(bool) Settings::get("sync_enabled") ||
            (bool) Settings::get("kill_switch_global")
        ) {
            return;
        }
        try {
            BackfillRunner::enqueue_posts([$post_id]);
            if (IndexState::is_writable() && !PostFilter::is_eligible($post_id)) {
                (new LocalVectorRepository())->delete_post($post_id);
            }
            SearchInterceptor::purge_query_cache();
        } catch (\RuntimeException $e) {
            update_post_meta(
                $post_id,
                RITRIEVER_POSTMETA_LAST_ERROR,
                $e->getMessage(),
            );
            Logger::error("sync", "post embedding failed", [
                "post_id" => $post_id,
                "error" => $e->getMessage(),
            ]);
        }
    }

    public static function on_delete_post(int $post_id): void
    {
        try {
            BackfillRunner::enqueue_posts([$post_id]);
            if (IndexState::is_writable()) {
                (new LocalVectorRepository())->delete_post($post_id);
            }
            SearchInterceptor::purge_query_cache();
        } catch (\RuntimeException $e) {
            Logger::error("sync", "Post removal will be retried.", ["post_id" => $post_id]);
        }
    }

    public static function on_meta_change($meta_id, int $post_id, string $key, $value): void
    {
        if (!str_starts_with($key, "_ritriever_") && in_array($key, (array) Settings::get("indexed_custom_fields"), true)) {
            self::on_save_post($post_id, get_post($post_id));
        }
    }

    public static function on_set_terms(int $post_id, $terms, $tt_ids, string $taxonomy): void
    {
        self::on_deleted_relationships($post_id, $tt_ids, $taxonomy);
    }

    public static function on_deleted_relationships(int $post_id, $tt_ids, string $taxonomy): void
    {
        if (in_array($taxonomy, (array) Settings::get("indexed_taxonomies"), true)) {
            self::on_save_post($post_id, get_post($post_id));
        }
    }

    public static function on_edited_term(int $term_id, int $tt_id, string $taxonomy): void
    {
        if (in_array($taxonomy, (array) Settings::get("indexed_taxonomies"), true)) {
            self::resync_term($tt_id, $taxonomy, 0);
        }
    }

    public static function on_deleted_term(int $term_id, int $tt_id, string $taxonomy, $deleted_term, array $object_ids): void
    {
        if (in_array($taxonomy, (array) Settings::get("indexed_taxonomies"), true) && Settings::get("sync_enabled") && !Settings::get("kill_switch_global")) {
            foreach (array_chunk($object_ids, 100) as $ids) {
                BackfillRunner::enqueue_posts($ids);
            }
            SearchInterceptor::purge_query_cache();
        }
    }

    public static function resync_term(int $tt_id, string $taxonomy, int $after = 0): void
    {
        if (!Settings::get("sync_enabled") || Settings::get("kill_switch_global") || !in_array($taxonomy, (array) Settings::get("indexed_taxonomies"), true) || !IndexState::is_writable()) {
            return;
        }
        $args = [$tt_id, $taxonomy, $after];
        if (!wp_next_scheduled("ritriever_resync_term", $args)) {
            $scheduled = wp_schedule_single_event(time() + 60, "ritriever_resync_term", $args, true);
            if ($scheduled === false || is_wp_error($scheduled)) {
                throw new \RuntimeException("Could not schedule taxonomy synchronization.");
            }
        }
        global $wpdb;
        $rows = \RiTriever\Database\Sql::rows($wpdb->prepare("SELECT object_id FROM %i WHERE term_taxonomy_id = %d AND object_id > %d ORDER BY object_id LIMIT 100", $wpdb->term_relationships, $tt_id, $after));
        $ids = array_map("intval", array_column($rows, "object_id"));
        BackfillRunner::enqueue_posts($ids);
        SearchInterceptor::purge_query_cache();
        if (count($ids) === 100) {
            $scheduled = wp_schedule_single_event(time() + 5, "ritriever_resync_term", [$tt_id, $taxonomy, max($ids)], true);
            if ($scheduled === false || is_wp_error($scheduled)) {
                throw new \RuntimeException("Could not schedule the next taxonomy synchronization batch.");
            }
        }
        wp_clear_scheduled_hook("ritriever_resync_term", $args);
    }

    public static function content_hash(int $post_id): string
    {
        return self::hash_text(self::post_text($post_id));
    }

    public static function hash_text(string $text): string
    {
        return hash("sha256", $text . "|" . IndexState::fingerprint());
    }

    public static function refresh_post(int $post_id): void
    {
        global $wpdb;
        wp_cache_delete($post_id, "posts");
        wp_cache_delete($post_id, "post_meta");
        foreach ((array) Settings::get("indexed_taxonomies") as $taxonomy) {
            wp_cache_delete($post_id, (string) $taxonomy . "_relationships");
        }
        if ((array) Settings::get("indexed_taxonomies") !== []) {
            $rows = \RiTriever\Database\Sql::rows($wpdb->prepare("SELECT tt.term_id FROM %i tt INNER JOIN %i tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d", $wpdb->term_taxonomy, $wpdb->term_relationships, $post_id));
            foreach ($rows as $row) {
                wp_cache_delete((int) $row["term_id"], "terms");
            }
        }
    }

    public static function post_text(int $post_id): string
    {
        global $wpdb;
        // Commit guards must not reuse a term/meta query cache primed before HTTP.
        $rows = \RiTriever\Database\Sql::rows($wpdb->prepare("SELECT post_title, post_excerpt, post_content FROM %i WHERE ID = %d", $wpdb->posts, $post_id));
        if ($rows === []) {
            return "";
        }
        $post = $rows[0];
        $content = wp_strip_all_tags(
            strip_shortcodes((string) $post["post_content"]),
        );
        $parts = [
            (string) $post["post_title"],
            (string) $post["post_excerpt"],
            $content,
        ];

        $fields = (array) Settings::get("indexed_custom_fields");
        sort($fields);
        foreach ($fields as $field_key) {
            $field_key = trim((string) $field_key);
            if ($field_key === "" || str_starts_with($field_key, "_ritriever_")) {
                continue;
            }
            $values = \RiTriever\Database\Sql::rows($wpdb->prepare("SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id", $wpdb->postmeta, $post_id, $field_key));
            foreach ($values as $row) {
                $value = maybe_unserialize($row["meta_value"]);
                $text = self::value_to_text($value);
                if ($text !== "") {
                    $parts[] = $field_key . ": " . $text;
                }
            }
        }

        $taxonomies = (array) Settings::get("indexed_taxonomies");
        sort($taxonomies);
        foreach ($taxonomies as $taxonomy) {
            $taxonomy = trim((string) $taxonomy);
            if ($taxonomy === "" || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = \RiTriever\Database\Sql::rows($wpdb->prepare("SELECT t.name FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id INNER JOIN %i tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s", $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $post_id, $taxonomy));
            $names = array_map("strval", array_column($terms, "name"));
            if ($names !== []) {
                sort($names);
                $parts[] = $taxonomy . ": " . implode(", ", $names);
            }
        }

        return trim(
            implode(
                "\n\n",
                array_filter(
                    $parts,
                    static fn(string $part): bool => trim($part) !== "",
                ),
            ),
        );
    }

    private static function value_to_text($value): string
    {
        if (is_scalar($value)) {
            return trim(wp_strip_all_tags((string) $value));
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = self::value_to_text($item);
                if ($text !== "") {
                    $parts[] = $text;
                }
            }
            return implode(" ", $parts);
        }
        return "";
    }

    /** @return string[] */
    public static function chunk_text(string $text): array
    {
        $max = max(500, (int) Settings::get("chunk_max_chars"));
        $overlap = min(
            max(0, (int) Settings::get("chunk_overlap_chars")),
            (int) floor($max / 3),
        );
        $normalized = preg_replace("/\s+/u", " ", trim($text));
        if ($normalized === null) {
            throw new \RuntimeException("Index input is not valid UTF-8.");
        }
        $characters = preg_split("//u", $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            throw new \RuntimeException("Unable to split UTF-8 index input.");
        }
        $chunks = [];
        $offset = 0;
        $len = count($characters);
        while ($offset < $len) {
            $chunks[] = implode("", array_slice($characters, $offset, $max));
            if ($offset + $max >= $len) {
                break;
            }
            $offset += max(1, $max - $overlap);
        }
        return array_values(
            array_filter(
                $chunks,
                static fn(string $chunk): bool => trim($chunk) !== "",
            ),
        );
    }

    /** @param string[] $chunks @return string[] */
    public static function embedding_texts_for_chunks(array $chunks): array
    {
        return array_map(
            static fn(string $chunk): string => LanguageOptions::with_embedding_context(
                $chunk,
            ),
            $chunks,
        );
    }
}
