<?php
/**
 * Shared, generation-fenced indexing for initial builds and live updates.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

use RiTriever\Database\DatabaseLock;
use RiTriever\Database\LocalVectorRepository;
use RiTriever\Embedding\EmbeddingProviderFactory;

final class BulkBackfillIndexer
{
    private const EMBEDDING_CHUNK_BATCH_SIZE = 96;

    /**
     * @param int[] $post_ids
     * @return array{errors:int,failed_ids:int[],failures:array<int,\Throwable>,stale_ids:int[]}
     */
    public static function process_posts(array $post_ids, ?callable $job_guard = null): array
    {
        Settings::refresh();
        $post_ids = array_values(array_unique(array_filter(array_map("intval", $post_ids), static fn(int $id): bool => $id > 0)));
        $repository = new LocalVectorRepository();
        $generation = IndexState::generation();
        if (!IndexState::is_writable() || !IndexState::can_sync()) {
            throw new StaleIndexWork("Index requires explicit initialization or has been stopped.");
        }
        $fingerprint = IndexState::fingerprint();
        $items = [];
        $jobs = [];
        $failures = [];
        $stale = [];
        $embedder = null;

        foreach ($post_ids as $post_id) {
            try {
                PostSync::refresh_post($post_id);
                if (!PostFilter::is_eligible($post_id)) {
                    DatabaseLock::with("lifecycle", static function () use ($repository, $post_id, $generation, $job_guard): void {
                        self::guard($post_id, $generation, null, $job_guard);
                        $repository->delete_post($post_id);
                    });
                    continue;
                }
                $text = PostSync::post_text($post_id);
                $hash = PostSync::hash_text($text);
                if ($repository->has_current($post_id, $hash, $generation)) {
                    DatabaseLock::with("lifecycle", static function () use ($post_id, $generation, $hash, $job_guard): void {
                        self::guard($post_id, $generation, $hash, $job_guard);
                    });
                    continue;
                }
                $chunks = PostSync::chunk_text($text);
                $embedder = $embedder ?? EmbeddingProviderFactory::make();
                $items[$post_id] = [
                    "post_id" => $post_id, "model" => $embedder->model(),
                    "content_hash" => $hash, "chunks" => $chunks, "embeddings" => [],
                ];
                foreach ($chunks as $index => $chunk) {
                    $jobs[] = ["post_id" => $post_id, "index" => $index, "text" => $chunk];
                }
            } catch (StaleIndexWork $e) {
                $stale[] = $post_id;
            } catch (\RuntimeException $e) {
                $failures[$post_id] = $e;
            }
        }

        foreach (array_chunk($jobs, self::EMBEDDING_CHUNK_BATCH_SIZE) as $batch) {
            try {
                if (IndexState::generation() !== $generation || !IndexState::is_writable() || !IndexState::can_sync()) {
                    throw new StaleIndexWork("Index changed before provider request.");
                }
                $vectors = self::embed_batch($embedder, $batch);
                foreach ($batch as $offset => $job) {
                    $items[$job["post_id"]]["embeddings"][$job["index"]] = $vectors[$offset];
                }
            } catch (StaleIndexWork $e) {
                foreach ($batch as $job) {
                    $stale[] = $job["post_id"];
                }
            } catch (\RuntimeException $e) {
                foreach ($batch as $job) {
                    $failures[$job["post_id"]] = $e;
                }
                if (BackfillRunner::is_retryable($e) || in_array((int) $e->getCode(), [401, 403], true)) {
                    foreach ($jobs as $job) {
                        $failures[$job["post_id"]] = $e;
                    }
                    break;
                }
            }
        }

        foreach ($items as $post_id => $item) {
            if (isset($failures[$post_id]) || in_array($post_id, $stale, true)) {
                continue;
            }
            try {
                ksort($item["embeddings"]);
                $repository->replace_post_embeddings(
                    $post_id, $item["model"], $item["content_hash"], $item["chunks"],
                    array_values($item["embeddings"]), $generation,
                    static function (int $id) use ($generation, $fingerprint, $item, $job_guard): void {
                        if (IndexState::fingerprint() !== $fingerprint) {
                            throw new StaleIndexWork("Embedding settings changed.");
                        }
                        self::guard($id, $generation, $item["content_hash"], $job_guard);
                    },
                );
                // An edit whose hooks run just after COMMIT is queued independently.
                // Reconcile a change already visible before this worker acknowledges.
                PostSync::refresh_post($post_id);
                if (!PostFilter::is_eligible($post_id) || PostSync::content_hash($post_id) !== $item["content_hash"]) {
                    BackfillRunner::enqueue_posts([$post_id]);
                    $stale[] = $post_id;
                }
                SearchInterceptor::purge_query_cache();
            } catch (StaleIndexWork $e) {
                $stale[] = $post_id;
            } catch (\RuntimeException $e) {
                $failures[$post_id] = $e;
            }
        }

        return [
            "errors" => count($failures), "failed_ids" => array_map("intval", array_keys($failures)),
            "failures" => $failures, "stale_ids" => array_values(array_unique($stale)),
        ];
    }

    private static function embed_batch(object $embedder, array $jobs): array
    {
        try {
            $vectors = $embedder->embed_many(PostSync::embedding_texts_for_chunks(array_column($jobs, "text")));
            if (count($vectors) !== count($jobs)) {
                throw new \RuntimeException("Embedding count does not match the requested chunks.");
            }
            return $vectors;
        } catch (\RuntimeException $e) {
            if (count($jobs) > 1 && !BackfillRunner::is_retryable($e) &&
                !in_array((int) $e->getCode(), [401, 403], true) &&
                preg_match('/count|too large|maximum|context|token|payload|input|empty/i', $e->getMessage()) === 1) {
                $vectors = [];
                foreach (array_chunk($jobs, (int) ceil(count($jobs) / 2)) as $half) {
                    $vectors = array_merge($vectors, self::embed_batch($embedder, $half));
                }
                return $vectors;
            }
            throw $e;
        }
    }

    private static function guard(int $post_id, string $generation, ?string $hash, ?callable $job_guard): void
    {
        if (IndexState::generation() !== $generation || !IndexState::is_writable() || !IndexState::can_sync()) {
            throw new StaleIndexWork("Index generation changed.");
        }
        if ($job_guard !== null) {
            $job_guard($post_id);
        }
        PostSync::refresh_post($post_id);
        if ($hash === null) {
            if (PostFilter::is_eligible($post_id)) {
                throw new StaleIndexWork("Post was republished during indexing.");
            }
        } elseif (!PostFilter::is_eligible($post_id) || PostSync::content_hash($post_id) !== $hash) {
            throw new StaleIndexWork("Post changed during embedding; current content will be retried.");
        }
    }
}
