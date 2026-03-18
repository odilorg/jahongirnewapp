<?php

declare(strict_types=1);

namespace App\Enums;

enum BotStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Disabled => 'warning',
            self::Revoked => 'danger',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
