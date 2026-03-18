<?php

declare(strict_types=1);

namespace App\Enums;

enum BotEnvironment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Staging => 'Staging',
            self::Development => 'Development',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Production => 'danger',
            self::Staging => 'warning',
            self::Development => 'gray',
        };
    }
}
