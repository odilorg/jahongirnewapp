<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'success',
            self::CLOSED => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OPEN => 'heroicon-o-play',
            self::CLOSED => 'heroicon-o-stop',
        };
    }
}

