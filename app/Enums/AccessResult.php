<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessResult: string
{
    case Success = 'success';
    case Denied = 'denied';
    case NotFound = 'not_found';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Denied => 'Denied',
            self::NotFound => 'Not Found',
            self::Error => 'Error',
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Success;
    }
}
