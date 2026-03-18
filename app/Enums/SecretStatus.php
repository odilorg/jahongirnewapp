<?php

declare(strict_types=1);

namespace App\Enums;

enum SecretStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Pending => 'Pending',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Pending => 'warning',
            self::Revoked => 'danger',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
