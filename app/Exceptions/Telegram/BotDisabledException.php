<?php

declare(strict_types=1);

namespace App\Exceptions\Telegram;

use App\Enums\BotStatus;
use RuntimeException;

final class BotDisabledException extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
        public readonly BotStatus $status,
    ) {
        parent::__construct(
            "Telegram bot [{$slug}] is not usable (status: {$status->value})."
        );
    }
}
