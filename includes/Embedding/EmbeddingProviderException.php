<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

final class EmbeddingProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $http_status = 0,
        public readonly bool $retryable = false,
        public readonly int $retry_after = 0,
        public readonly string $error_code = "",
    ) {
        parent::__construct($message, $http_status);
    }

    public function is_retryable(): bool
    {
        return $this->retryable;
    }

    public function retry_after(): int
    {
        return $this->retry_after;
    }
}
