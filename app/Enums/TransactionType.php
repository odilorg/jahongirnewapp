<?php

namespace App\Enums;

enum TransactionType: string
{
    case IN = 'in';
    case OUT = 'out';
    case IN_OUT = 'in_out';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Cash In',
            self::OUT => 'Cash Out',
            self::IN_OUT => 'Cash In-Out (Complex)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IN => 'success',
            self::OUT => 'danger',
            self::IN_OUT => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::IN => 'heroicon-o-arrow-down',
            self::OUT => 'heroicon-o-arrow-up',
            self::IN_OUT => 'heroicon-o-arrows-right-left',
        };
    }

    public function multiplier(): int
    {
        return match ($this) {
            self::IN => 1,
            self::OUT => -1,
            self::IN_OUT => 0, // Complex transactions are handled separately
        };
    }
}


