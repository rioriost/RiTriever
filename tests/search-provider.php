<?php
/** Run: php tests/search-provider.php */
declare(strict_types=1);

namespace RiTriever {
    final class Settings
    {
        public static array $values = [
            "embedding_dimensions" => 2, "vector_distance" => "cosine",
            "top_k" => 3, "min_score" => 0.25,
        ];
        public static function get(string $key) { return self::$values[$key] ?? null; }
    }
    final class IndexState
    {
        public static function generation(): string { return "current-index"; }
    }
    final class LanguageOptions
    {
        public static function with_embedding_context(string $text): string { return $text; }
    }
}

namespace RiTriever\Database {
    final class VectorSchema
    {
        public static function table_name(): string { return "wp_ritriever_vectors"; }
    }
}

namespace RiTriever\Embedding {
    final class EmbeddingProviderFactory
    {
        public static function make(): object
        {
            return new class {
                public function embed(string $text): array
                {
                    if ($GLOBALS["api_failure"]) {
                        throw new EmbeddingProviderException("Embedding HTTP 503 (service_unavailable).");
                    }
                    return [1.0, 0.5];
                }
                public function model(): string { return "test-model"; }
            };
        }
    }
}

namespace {
    const ARRAY_A = "ARRAY_A";
    function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    final class ProviderTestDatabase
    {
        public string $last_error = "";
        public bool $fail = false;
        public array $rows = [];
        public array $arguments = [];
        public function prepare(string $sql, ...$args): string
        {
            $this->arguments = $args;
            return $sql;
        }
        public function get_results(string $sql, string $format): ?array
        {
            $this->last_error = $this->fail ? "injected vector SQL failure" : "";
            // wpdb can return [] on failed SELECTs; last_error is authoritative.
            return $this->fail ? [] : $this->rows;
        }
    }
    foreach ([
        "Database/Sql", "Database/LocalVectorRepository",
        "Embedding/EmbeddingProviderException", "Embedding/EmbeddingResponseValidator",
        "TextNormalizer", "Provider/ResultHit", "Provider/RetrieveResult",
        "Provider/LocalVectorProvider",
    ] as $file) {
        require_once dirname(__DIR__) . "/includes/" . $file . ".php";
    }
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only assertion.
            throw new \RuntimeException($message);
        }
    }
    $GLOBALS["api_failure"] = false;
    $wpdb = new ProviderTestDatabase();
    $provider = new \RiTriever\Provider\LocalVectorProvider();
    $empty = $provider->retrieve("apple");
    check($empty->ok && $empty->hits === [], "successful empty vector SQL became failure");
    $wpdb->fail = true;
    $failed = $provider->retrieve("apple");
    check(!$failed->ok && $failed->hits === [] && $failed->error !== null, "failed vector SQL became successful empty result");
    $wpdb->fail = false;
    $GLOBALS["api_failure"] = true;
    check(!$provider->retrieve("apple")->ok, "API failure was hidden");
    $GLOBALS["api_failure"] = false;
    $wpdb->rows = [
        ["post_id" => "2", "distance" => "0.4", "chunk_text" => "first"],
        ["post_id" => "2", "distance" => "0.1", "chunk_text" => "best"],
        ["post_id" => "3", "distance" => "0.2", "chunk_text" => "second"],
        ["post_id" => "4", "distance" => "0.3", "chunk_text" => "third"],
        ["post_id" => "5", "distance" => "0.5", "chunk_text" => "fourth"],
    ];
    foreach (["cosine", "euclidean"] as $distance) {
        \RiTriever\Settings::$values["vector_distance"] = $distance;
        $result = $provider->retrieve("apple");
        check($result->ok && $result->post_ids() === [2, 3, 4], "healthy result ranking or per-post bound changed");
        check($result->hits[0]->snippet === "best", "best chunk was not selected");
        check($wpdb->arguments[3] === "current-index", "vector SQL did not constrain current index generation");
    }
    echo "Search provider/database regressions passed.\n";
}
