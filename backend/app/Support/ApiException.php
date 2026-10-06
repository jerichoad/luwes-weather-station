<?php

namespace App\Support;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $details
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Resource tidak ditemukan.'): self
    {
        return new self(404, ErrorCode::NOT_FOUND, $message);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self(409, $code, $message, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, $details);
    }
}
