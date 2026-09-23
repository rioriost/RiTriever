<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

final class EmbeddingResponseValidator
{
    /** @return array<int, float[]> */
    public static function validate(
        array $vectors,
        int $expected_count,
        int $dimensions,
        string $distance = "cosine",
    ): array {
        if (!array_is_list($vectors) || count($vectors) !== $expected_count || $dimensions < 1) {
            throw new EmbeddingProviderException("Embedding response count or dimensions are invalid.");
        }
        $out = [];
        foreach ($vectors as $vector) {
            if (!is_array($vector) || !array_is_list($vector) || count($vector) !== $dimensions) {
                throw new EmbeddingProviderException("Embedding response dimensions do not match the configuration.");
            }
            $values = [];
            $nonzero = false;
            foreach ($vector as $value) {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 3.4028234663852886e38) {
                    throw new EmbeddingProviderException("Embedding response contains an invalid finite FLOAT value.");
                }
                // MariaDB VECTOR stores single precision values, including underflow to zero.
                $stored = unpack("f", pack("f", (float) $value))[1];
                $nonzero = $nonzero || $stored != 0.0;
                $values[] = (float) $value;
            }
            if ($distance === "cosine" && !$nonzero) {
                throw new EmbeddingProviderException("A zero vector is not valid for cosine distance.");
            }
            $out[] = $values;
        }
        return $out;
    }

    /** Accept indexed OpenAI rows and the existing positional custom formats. */
    public static function from_payload(
        array $payload,
        int $expected_count,
        int $dimensions,
        string $distance = "cosine",
        bool $require_indices = false,
    ): array {
        if (isset($payload["data"]) && is_array($payload["data"])) {
            $rows = $payload["data"];
            if (!array_is_list($rows) || count($rows) !== $expected_count) {
                throw new EmbeddingProviderException("Embedding response count does not match the request.");
            }
            $indexed = $require_indices;
            foreach ($rows as $row) {
                $indexed = $indexed || (is_array($row) && array_key_exists("index", $row));
            }
            $vectors = [];
            foreach ($rows as $position => $row) {
                if (!is_array($row) || !isset($row["embedding"]) || !is_array($row["embedding"])) {
                    throw new EmbeddingProviderException("Embedding response has an invalid data row.");
                }
                $index = $indexed ? ($row["index"] ?? null) : $position;
                if (!is_int($index) || $index < 0 || $index >= $expected_count || array_key_exists($index, $vectors)) {
                    throw new EmbeddingProviderException("Embedding response indices are missing, duplicated or out of range.");
                }
                $vectors[$index] = $row["embedding"];
            }
            ksort($vectors);
            return self::validate(array_values($vectors), $expected_count, $dimensions, $distance);
        }
        if ($require_indices) {
            throw new EmbeddingProviderException("Embedding response has no indexed data.");
        }
        $vectors = $payload["embeddings"]["float"] ?? $payload["embeddings"] ?? null;
        if ($vectors === null && isset($payload["embedding"])) {
            $vectors = [$payload["embedding"]];
        }
        if (!is_array($vectors)) {
            throw new EmbeddingProviderException("Embedding response has no embeddings.");
        }
        return self::validate($vectors, $expected_count, $dimensions, $distance);
    }

    public static function encode_request(array $body, array $texts): string
    {
        foreach ($texts as $text) {
            if (!is_string($text) || preg_match("//u", $text) !== 1) {
                throw new EmbeddingProviderException("Embedding input must contain valid UTF-8 strings.");
            }
        }
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new EmbeddingProviderException("Embedding request could not be encoded as JSON.");
        }
    }

    public static function decode_response($response): array
    {
        if (is_wp_error($response)) {
            // Transport messages can contain credential-bearing URLs; do not forward them.
            throw new EmbeddingProviderException("Embedding transport request failed.", 0, true, 0, "transport_error");
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $retryable = in_array($status, [408, 425, 429], true) || $status >= 500;
            $header = function_exists("wp_remote_retrieve_header")
                ? (string) wp_remote_retrieve_header($response, "retry-after")
                : "";
            $retry_after = ctype_digit($header)
                ? min(86400, (int) $header)
                : max(0, min(86400, (int) strtotime($header) - time()));
            $code = match ($status) {
                401 => "authentication_failed",
                403 => "access_denied",
                404 => "endpoint_not_found",
                408 => "request_timeout",
                429 => "rate_limited",
                default => $status >= 500 ? "service_unavailable" : "request_rejected",
            };
            // Error envelopes may echo keys or post text. Only expose status-derived codes.
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status-derived diagnostic metadata; rendered notices escape at the output boundary.
            throw new EmbeddingProviderException(
                "Embedding HTTP " . $status . " (" . $code . ").",
                $status,
                $retryable,
                $retry_after,
                $code,
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        try {
            $payload = json_decode((string) wp_remote_retrieve_body($response), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EmbeddingProviderException("Embedding endpoint returned invalid JSON.");
        }
        if (!is_array($payload) || array_key_exists("error", $payload)) {
            throw new EmbeddingProviderException("Embedding endpoint returned an invalid or error payload.");
        }
        return $payload;
    }
}
