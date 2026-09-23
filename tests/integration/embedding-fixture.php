<?php
declare(strict_types=1);

if (getenv("RITRIEVER_INTEGRATION_TEST") !== "1") {
    throw new RuntimeException("Run only in an explicitly isolated test WordPress installation.");
}

WP_CLI::add_hook("after_wp_load", static function (): void {
    add_filter("pre_http_request", static function ($preempt, array $args, string $url) {
        if ($url !== "https://ritriever.invalid/embeddings") {
            return $preempt;
        }
        $body = json_decode($args["body"], true, 512, JSON_THROW_ON_ERROR);
        $data = [];
        foreach ((array) $body["input"] as $index => $text) {
            if (preg_match("//u", $text) !== 1) {
                throw new RuntimeException("Embedding input is not valid UTF-8.");
            }
            $vector = [];
            for ($i = 0; $i < 16; $i++) {
                $vector[] = (crc32($text . ":" . $i) % 1000 + 1) / 1000;
            }
            $data[] = ["index" => $index, "embedding" => $vector];
        }
        return [
            "response" => ["code" => 200, "message" => "OK"],
            "body" => wp_json_encode(["data" => $data]),
            "headers" => [],
            "cookies" => [],
        ];
    }, 10, 3);
});
