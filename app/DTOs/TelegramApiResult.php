<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Sanitized result from a Telegram Bot API call.
 *
 * Contains only the API response data — never the token, URL, or
 * request headers. Safe to log, return from methods, or pass around.
 */
final readonly class TelegramApiResult
{
    /**
     * @param bool   $ok          Telegram's "ok" field
     * @param mixed  $result      Telegram's "result" field (type varies by method)
     * @param int    $httpStatus  HTTP status code
     * @param string|null $description Telegram's "description" field (error message on failure)
     * @param int|null $errorCode   Telegram's "error_code" field
     */
    public function __construct(
        public bool $ok,
        public mixed $result,
        public int $httpStatus,
        public ?string $description = null,
        public ?int $errorCode = null,
    ) {}

    /**
     * Whether the API call succeeded (ok=true AND HTTP 2xx).
     */
    public function succeeded(): bool
    {
        return $this->ok && $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Whether this is a rate-limit response (HTTP 429).
     */
    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    /**
     * Telegram's suggested retry-after seconds (from 429 responses).
     */
    public function retryAfterSeconds(): ?int
    {
        if (! $this->isRateLimited()) {
            return null;
        }

        $params = $this->result['parameters']['retry_after'] ?? null;

        return is_int($params) ? $params : null;
    }

    /**
     * Whether this is a permanent error that should not be retried.
     * 400 (bad request) and 403 (forbidden/blocked) are permanent.
     */
    public function isPermanentError(): bool
    {
        return in_array($this->httpStatus, [400, 403, 404], true);
    }
}
