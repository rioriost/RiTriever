<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

use RiTriever\Settings;

final class CustomHttpEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        $model = trim((string) Settings::get("custom_embedding_model"));
        return $model !== ""
            ? $model
            : "custom-http-" . (int) Settings::get("embedding_dimensions");
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
        $url = (string) Settings::get("custom_embedding_endpoint");
        if ($url === "") {
            throw new \RuntimeException("Custom embedding endpoint is empty.");
        }
        $parts = wp_parse_url($url);
        if (
            !is_array($parts) ||
            !in_array(strtolower((string) ($parts["scheme"] ?? "")), ["http", "https"], true) ||
            trim((string) ($parts["host"] ?? "")) === "" ||
            preg_match('/\s/', (string) $parts["host"]) === 1
        ) {
            throw new EmbeddingProviderException("Custom embedding endpoint must be an absolute HTTP(S) URL with a host.");
        }
        $headers = ["Content-Type" => "application/json"];
        $key = (string) Settings::get("custom_embedding_api_key");
        $model = trim((string) Settings::get("custom_embedding_model"));
        $format = (string) Settings::get("custom_embedding_format");
        if (
            ($format === "azure_openai" || Settings::get("embedding_provider") === "azure_openai") &&
            preg_match('/YOUR[-_]?(?:RESOURCE|DEPLOYMENT)/i', rawurldecode($url)) === 1
        ) {
            throw new EmbeddingProviderException("Replace the Azure resource and deployment placeholders before connecting.");
        }
        if ($key !== "") {
            if ($format === "azure_openai") {
                $headers["api-key"] = $key;
            } else {
                $headers["Authorization"] = "Bearer " . $key;
            }
        }
        $body = $this->request_body($format, $model, array_values($texts));
        $response = wp_remote_post($url, [
            "timeout" => 30,
            "headers" => $headers,
            "body" => EmbeddingResponseValidator::encode_request($body, $texts),
        ]);
        return EmbeddingResponseValidator::from_payload(
            EmbeddingResponseValidator::decode_response($response),
            count($texts),
            (int) Settings::get("embedding_dimensions"),
            (string) Settings::get("vector_distance"),
        );
    }

    /** @param string[] $texts @return array<string,mixed> */
    private function request_body(
        string $format,
        string $model,
        array $texts,
    ): array {
        if ($format === "azure_openai") {
            $body = ["input" => $texts];
            $dimensions = (int) Settings::get("embedding_dimensions");
            if ($dimensions > 0) {
                $body["dimensions"] = $dimensions;
            }
            return $body;
        }
        return [
            "model" => $model,
            "input" => $texts,
        ];
    }
}
