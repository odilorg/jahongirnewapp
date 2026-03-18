<?php

declare(strict_types=1);

namespace App\Exceptions\Telegram;

use RuntimeException;

final class BotNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
    ) {
        parent::__construct("Telegram bot not found: [{$slug}].");
    }
}
