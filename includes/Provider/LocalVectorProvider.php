<?php
declare(strict_types=1);

namespace RiTriever\Provider;

use RiTriever\Database\LocalVectorRepository;
use RiTriever\Embedding\EmbeddingProviderFactory;
use RiTriever\Embedding\EmbeddingResponseValidator;
use RiTriever\LanguageOptions;
use RiTriever\Settings;
use RiTriever\TextNormalizer;

final class LocalVectorProvider
{
    public function retrieve(string $query): RetrieveResult
    {
        try {
            $embedder = EmbeddingProviderFactory::make();
            $embedding = $embedder->embed(
                LanguageOptions::with_embedding_context(
                    TextNormalizer::vector_query($query),
                ),
            );
            $embedding = EmbeddingResponseValidator::validate(
                [$embedding],
                1,
                (int) Settings::get("embedding_dimensions"),
                (string) Settings::get("vector_distance"),
            )[0];
            $results = (new LocalVectorRepository())->search_with_chunks(
                $embedding,
                $embedder->model(),
                (int) Settings::get("top_k"),
            );
            $min = (float) Settings::get("min_score");
            $hits = [];
            foreach ($results as $post_id => $result) {
                if (
                    !is_array($result) ||
                    !isset($result["score"]) ||
                    (!is_int($result["score"]) && !is_float($result["score"])) ||
                    !is_finite((float) $result["score"]) ||
                    !is_int($post_id) ||
                    $post_id <= 0 ||
                    !is_string($result["chunk_text"] ?? null)
                ) {
                    throw new \UnexpectedValueException("Vector search returned an invalid hit.");
                }
                $score = (float) $result["score"];
                if ($score >= $min) {
                    $hits[] = new ResultHit(
                        (int) $post_id,
                        $score,
                        "rag",
                        (string) ($result["chunk_text"] ?? ""),
                    );
                }
            }
            return RetrieveResult::success($hits);
        } catch (\RuntimeException $e) {
            return RetrieveResult::failure($e->getMessage());
        }
    }
}
