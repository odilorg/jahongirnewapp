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

    /**
     * Map a Laravel APP_ENV string to a BotEnvironment enum value.
     *
     * Canonical mapping (single source of truth):
     *   'production'                    → Production
     *   'staging'                       → Staging
     *   'local', 'testing', any other   → Development
     *
     * Used by BotResolver (environment enforcement) and
     * LegacyConfigBotAdapter (environment inference).
     */
    public static function fromAppEnvironment(string $appEnv): self
    {
        return match ($appEnv) {
            'production' => self::Production,
            'staging' => self::Staging,
            default => self::Development,
        };
    }
}
