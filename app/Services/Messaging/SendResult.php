<?php

namespace App\Services\Messaging;

class SendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $channel,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public static function ok(string $channel): self
    {
        return new self(success: true, channel: $channel);
    }

    public static function fail(string $channel, string $error, bool $retryable = false): self
    {
        return new self(success: false, channel: $channel, error: $error, retryable: $retryable);
    }
}
