<?php

declare(strict_types=1);

namespace App\Exceptions\Telegram;

use App\Enums\BotEnvironment;
use RuntimeException;

final class BotEnvironmentMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
        public readonly BotEnvironment $botEnvironment,
        public readonly string $appEnvironment,
    ) {
        parent::__construct(
            "Telegram bot [{$slug}] is registered for [{$botEnvironment->value}] "
            . "but app is running in [{$appEnvironment}]."
        );
    }
}
