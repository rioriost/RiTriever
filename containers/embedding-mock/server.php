<?php
/**
 * Deterministic embedding mock for local Apple Container smoke tests.
 *
 * Accepts POST JSON: {"input": "text"} or {"input": ["text", ...]}
 * Returns: {"embeddings": [[...]], "model": "ritriever-mock-N"}
 */

declare(strict_types=1);

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
if ($_SERVER["REQUEST_METHOD"] === "GET" && $path === "/health") {
    json_response(["ok" => true, "dimensions" => dimensions()]);
}

if (
    $_SERVER["REQUEST_METHOD"] !== "POST" ||
    !in_array($path, ["/", "/embed", "/embeddings"], true)
) {
    json_response(["error" => "not_found"], 404);
}

$payload = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($payload) || !array_key_exists("input", $payload)) {
    json_response(["error" => "expected JSON body with input"], 400);
}

$input = $payload["input"];
$texts = is_array($input) ? array_values($input) : [$input];
$dim = dimensions();
$embeddings = [];
foreach ($texts as $text) {
    $embeddings[] = embedding((string) $text, $dim);
}

json_response([
    "model" => "ritriever-mock-" . $dim,
    "embeddings" => $embeddings,
]);

function dimensions(): int
{
    $raw = getenv("EMBEDDING_DIMENSIONS");
    $dim = is_string($raw) && $raw !== "" ? (int) $raw : 16;
    return max(1, min(4096, $dim));
}

/** @return float[] */
function embedding(string $text, int $dim): array
{
    $vector = [];
    $sumSquares = 0.0;
    for ($i = 0; $i < $dim; $i++) {
        $hash = crc32($text . "|" . $i);
        $value = (($hash % 20001) - 10000) / 10000.0;
        $vector[] = $value;
        $sumSquares += $value * $value;
    }

    $norm = sqrt(max($sumSquares, 1.0e-12));
    foreach ($vector as $i => $value) {
        $vector[$i] = round($value / $norm, 8);
    }
    return $vector;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) ?:
        "{}";
    exit();
}
