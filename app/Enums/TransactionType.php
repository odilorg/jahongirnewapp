<?php

namespace App\Enums;

enum TransactionType: string
{
    case IN = 'in';
    case OUT = 'out';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Cash In',
            self::OUT => 'Cash Out',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IN => 'success',
            self::OUT => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::IN => 'heroicon-o-arrow-down',
            self::OUT => 'heroicon-o-arrow-up',
        };
    }

    public function multiplier(): int
    {
        return match ($this) {
            self::IN => 1,
            self::OUT => -1,
        };
    }
}


