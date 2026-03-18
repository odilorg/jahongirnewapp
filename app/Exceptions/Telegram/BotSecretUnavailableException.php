<?php

declare(strict_types=1);

namespace App\Exceptions\Telegram;

use RuntimeException;

final class BotSecretUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
    ) {
        parent::__construct(
            "No active secret found for Telegram bot [{$slug}]. "
            . "Ensure a secret with status 'active' exists."
        );
    }
}
