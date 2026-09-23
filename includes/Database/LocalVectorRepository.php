<?php
/**
 * Database access for native vector chunks.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever\Database;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Native VECTOR inserts/searches need explicit SQL for VEC_FromText and distance functions with vetted identifiers.

use RiTriever\Settings;
use RiTriever\IndexState;
use RiTriever\Embedding\EmbeddingResponseValidator;

final class LocalVectorRepository
{
    /**
     * Replace chunks and their receipt in one checked transaction.
     *
     * @param int                  $post_id
     * @param string               $model
     * @param string               $content_hash
     * @param array<int, string>   $chunks
     * @param array<int, float[]>  $embeddings
     */
    public function replace_post_embeddings(
        int $post_id,
        string $model,
        string $content_hash,
        array $chunks,
        array $embeddings,
        ?string $generation = null,
        ?callable $guard = null,
    ): void {
        $this->replace_many_post_embeddings([[
            "post_id" => $post_id, "model" => $model, "content_hash" => $content_hash,
            "chunks" => $chunks, "embeddings" => $embeddings,
        ]], $generation, $guard);
    }

    /**
     * @param array<int,array{post_id:int,model:string,content_hash:string,chunks:array<int,string>,embeddings:array<int,array<int,float>>}> $items
     */
    public function replace_many_post_embeddings(array $items, ?string $generation = null, ?callable $guard = null): void
    {
        global $wpdb;
        if ($items === []) {
            return;
        }
        $generation = $generation ?? IndexState::generation();
        foreach ($items as $key => $item) {
            $chunks = $item["chunks"] ?? [];
            $embeddings = $item["embeddings"] ?? [];
            if ((int) ($item["post_id"] ?? 0) <= 0 || (string) ($item["model"] ?? "") === "" || count($chunks) !== count($embeddings)) {
                throw new \RuntimeException("Invalid post or embedding count.");
            }
            $items[$key]["embeddings"] = EmbeddingResponseValidator::validate(
                $embeddings, count($chunks), (int) Settings::get("embedding_dimensions"), (string) Settings::get("vector_distance"),
            );
        }
        usort($items, static fn(array $a, array $b): int => (int) $a["post_id"] <=> (int) $b["post_id"]);
        DatabaseLock::with("lifecycle", static function (DatabaseLock $lock) use ($items, $generation, $guard, $wpdb): void {
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                try {
                    Sql::transaction(static function () use ($items, $generation, $guard, $lock, $wpdb): void {
                        $lock->assert_owned();
                        // Lock every source before the first consistent table read.
                        // Otherwise REPEATABLE READ could keep a pre-lock snapshot.
                        foreach ($items as $item) {
                            $post_id = (int) $item["post_id"];
                            if (Sql::rows($wpdb->prepare("SELECT ID FROM %i WHERE ID = %d FOR UPDATE", $wpdb->posts, $post_id)) === []) {
                                throw new \RuntimeException("Post no longer exists.");
                            }
                            Sql::rows($wpdb->prepare("SELECT meta_id FROM %i WHERE post_id = %d FOR UPDATE", $wpdb->postmeta, $post_id));
                            Sql::rows($wpdb->prepare("SELECT tr.object_id FROM %i tr INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN %i t ON tt.term_id = t.term_id WHERE tr.object_id = %d FOR UPDATE", $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $post_id));
                        }
                        Sql::rows($wpdb->prepare("SELECT option_name FROM %i WHERE option_name IN (%s,%s) FOR UPDATE", $wpdb->options, RITRIEVER_OPTION_KEY, IndexState::OPTION_KEY));
                        if ($generation === "" || IndexState::generation() !== $generation || !IndexState::is_writable()) {
                            throw new \RuntimeException("Index generation changed before commit.");
                        }
                        foreach ($items as $item) {
                            $post_id = (int) $item["post_id"];
                            $model = (string) $item["model"];
                            if ($guard !== null) {
                                $guard($post_id);
                            }
                            Sql::query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d", VectorSchema::table_name(), $post_id));
                            foreach ($item["chunks"] as $i => $chunk) {
                                Sql::query($wpdb->prepare(
                                    "INSERT INTO %i (post_id, chunk_index, chunk_text, content_hash, index_generation, embedding_model, embedding, updated_at) VALUES (%d, %d, %s, %s, %s, %s, VEC_FromText(%s), UTC_TIMESTAMP())",
                                    VectorSchema::table_name(), $post_id, $i, self::clean_text((string) $chunk),
                                    (string) $item["content_hash"], $generation, self::clean_text($model), self::vector_text($item["embeddings"][$i]),
                                ));
                            }
                            Sql::query($wpdb->prepare(
                                "INSERT INTO %i (post_id, index_generation, content_hash, embedding_model, chunk_count) VALUES (%d, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE index_generation = VALUES(index_generation), content_hash = VALUES(content_hash), embedding_model = VALUES(embedding_model), chunk_count = VALUES(chunk_count)",
                                VectorSchema::state_table(), $post_id, $generation, (string) $item["content_hash"], $model, count($item["chunks"]),
                            ));
                            self::write_success_meta($post_id, (string) $item["content_hash"], $generation);
                        }
                        $lock->assert_owned();
                    });
                    foreach ($items as $item) {
                        wp_cache_delete((int) $item["post_id"], "post_meta");
                    }
                    return;
                } catch (\RuntimeException $e) {
                    foreach ($items as $item) {
                        wp_cache_delete((int) $item["post_id"], "post_meta");
                    }
                    if ($attempt < 3 && in_array((int) $e->getCode(), [1205, 1213], true)) {
                        usleep(50000 * $attempt);
                        continue;
                    }
                    throw $e;
                } catch (\Throwable $e) {
                    foreach ($items as $item) {
                        wp_cache_delete((int) $item["post_id"], "post_meta");
                    }
                    throw $e;
                }
            }
        });
    }

    public function delete_post(int $post_id): void
    {
        global $wpdb;
        DatabaseLock::with("lifecycle", static function () use ($wpdb, $post_id): void {
            Sql::transaction(static function () use ($wpdb, $post_id): void {
                Sql::query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d", VectorSchema::table_name(), $post_id));
                Sql::query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d", VectorSchema::state_table(), $post_id));
                self::clear_meta($post_id);
            });
            wp_cache_delete($post_id, "post_meta");
        });
    }

    public function has_current(int $post_id, string $hash, string $generation): bool
    {
        global $wpdb;
        return (int) Sql::value($wpdb->prepare(
            "SELECT COUNT(*) FROM %i r WHERE r.post_id = %d AND r.index_generation = %s AND r.content_hash = %s AND r.chunk_count = (SELECT COUNT(*) FROM %i c WHERE c.post_id = r.post_id) AND r.chunk_count = (SELECT COUNT(*) FROM %i c WHERE c.post_id = r.post_id AND c.index_generation = r.index_generation AND c.content_hash = r.content_hash AND c.embedding_model = r.embedding_model)",
            VectorSchema::state_table(), $post_id, $generation, $hash, VectorSchema::table_name(), VectorSchema::table_name(),
        )) === 1;
    }

    private static function clear_meta(int $post_id): void
    {
        global $wpdb;
        Sql::query($wpdb->prepare("DELETE FROM %i WHERE post_id = %d AND meta_key IN (%s,%s,%s,%s)", $wpdb->postmeta, $post_id, RITRIEVER_POSTMETA_CONTENT_HASH, RITRIEVER_POSTMETA_INDEXED_AT, RITRIEVER_POSTMETA_LAST_ERROR, IndexState::GENERATION_META));
    }

    private static function write_success_meta(int $post_id, string $hash, string $generation): void
    {
        global $wpdb;
        self::clear_meta($post_id);
        foreach ([RITRIEVER_POSTMETA_CONTENT_HASH => $hash, RITRIEVER_POSTMETA_INDEXED_AT => (string) time(), IndexState::GENERATION_META => $generation] as $key => $value) {
            Sql::query($wpdb->prepare("INSERT INTO %i (post_id, meta_key, meta_value) VALUES (%d, %s, %s)", $wpdb->postmeta, $post_id, $key, $value));
        }
    }

    /** @param float[] $query_embedding @return array<int, float> post_id => normalized score */
    public function search(
        array $query_embedding,
        string $model,
        int $top_k,
    ): array {
        $hits = $this->search_with_chunks($query_embedding, $model, $top_k);
        $out = [];
        foreach ($hits as $post_id => $hit) {
            $out[(int) $post_id] = (float) ($hit["score"] ?? 0.0);
        }
        return $out;
    }

    /**
     * @param float[] $query_embedding
     * @return array<int,array{score:float,distance:float,chunk_text:string}> post_id => hit metadata
     */
    public function search_with_chunks(
        array $query_embedding,
        string $model,
        int $top_k,
    ): array {
        global $wpdb;
        $query_embedding = EmbeddingResponseValidator::validate(
            [$query_embedding], 1, (int) Settings::get("embedding_dimensions"), (string) Settings::get("vector_distance"),
        )[0];
        $table = VectorSchema::table_name();
        $chunk_limit = max($top_k, $top_k * 5);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows =
            Settings::get("vector_distance") === "euclidean"
                ? Sql::rows(
                    $wpdb->prepare(
                        "SELECT post_id, chunk_text, VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(%s)) AS distance FROM %i WHERE embedding_model = %s AND index_generation = %s ORDER BY distance ASC LIMIT %d",
                        self::vector_text($query_embedding),
                        $table,
                        $model,
                        IndexState::generation(),
                        $chunk_limit,
                    ),
                )
                : Sql::rows(
                    $wpdb->prepare(
                        "SELECT post_id, chunk_text, VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS distance FROM %i WHERE embedding_model = %s AND index_generation = %s ORDER BY distance ASC LIMIT %d",
                        self::vector_text($query_embedding),
                        $table,
                        $model,
                        IndexState::generation(),
                        $chunk_limit,
                    ),
                );
        $best_by_post = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $raw_id = $row["post_id"] ?? null;
            $raw_distance = $row["distance"] ?? null;
            $post_id = (is_int($raw_id) || is_string($raw_id))
                ? filter_var($raw_id, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])
                : false;
            if ($post_id === false ||
                (!is_int($raw_distance) && !is_float($raw_distance) && !is_string($raw_distance)) ||
                !is_numeric($raw_distance) || !is_finite((float) $raw_distance) ||
                !is_string($row["chunk_text"] ?? null)) {
                throw new \RuntimeException("Vector search returned invalid index data.");
            }
            $distance = (float) $raw_distance;
            if (
                !isset($best_by_post[$post_id]) ||
                $distance < (float) $best_by_post[$post_id]["distance"]
            ) {
                $best_by_post[$post_id] = [
                    "distance" => $distance,
                    "chunk_text" => $row["chunk_text"],
                ];
            }
        }

        uasort(
            $best_by_post,
            static fn(array $a, array $b): int => (float) $a["distance"] <=>
                (float) $b["distance"],
        );
        $out = [];
        foreach (
            array_slice($best_by_post, 0, $top_k, true)
            as $post_id => $hit
        ) {
            $distance = (float) $hit["distance"];
            $out[(int) $post_id] = [
                "score" => 1.0 / (1.0 + max(0.0, $distance)),
                "distance" => $distance,
                "chunk_text" => (string) $hit["chunk_text"],
            ];
        }
        return $out;
    }

    private static function clean_text(string $value): string
    {
        $value = str_replace("\0", "", $value);
        if (function_exists("wp_check_invalid_utf8")) {
            $checked = wp_check_invalid_utf8($value, true);
            $value = is_string($checked) ? $checked : "";
        }

        $cleaned = preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            "",
            $value,
        );
        return is_string($cleaned) ? $cleaned : "";
    }

    /** @param float[] $embedding */
    private static function vector_text(array $embedding): string
    {
        $json = wp_json_encode(array_values($embedding));
        if (!is_string($json)) {
            throw new \RuntimeException("Embedding serialization failed.");
        }
        return $json;
    }
}
