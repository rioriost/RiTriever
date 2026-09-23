<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

use RiTriever\Settings;

final class OpenAiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        return (string) Settings::get("openai_embedding_model");
    }

    public function embed(string $text): array
    {
        $many = $this->embed_many([$text]);
        return $many[0] ?? [];
    }

    public function embed_many(array $texts): array
    {
        if ((bool) Settings::get("kill_switch_global")) {
            throw new EmbeddingProviderException("RiTriever is globally stopped. No embedding request was sent.");
        }
        if ($texts === []) {
            return [];
        }
        $key = (string) Settings::get("openai_api_key");
        if ($key === "") {
            throw new \RuntimeException("OpenAI API key is empty.");
        }
        $body = [
            "model" => $this->model(),
            "input" => array_values($texts),
        ];
        $dimensions = (int) Settings::get("embedding_dimensions");
        if ($dimensions > 0) {
            $body["dimensions"] = $dimensions;
        }

        $response = wp_remote_post("https://api.openai.com/v1/embeddings", [
            "timeout" => 30,
            "headers" => [
                "Authorization" => "Bearer " . $key,
                "Content-Type" => "application/json",
            ],
            "body" => EmbeddingResponseValidator::encode_request($body, $texts),
        ]);
        return EmbeddingResponseValidator::from_payload(
            EmbeddingResponseValidator::decode_response($response),
            count($texts),
            $dimensions,
            (string) Settings::get("vector_distance"),
            true,
        );
    }
}
