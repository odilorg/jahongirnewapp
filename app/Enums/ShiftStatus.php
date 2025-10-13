<?php

namespace App\Enums;

enum ShiftStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case UNDER_REVIEW = 'under_review';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
            self::UNDER_REVIEW => 'Under Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'success',
            self::CLOSED => 'gray',
            self::UNDER_REVIEW => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OPEN => 'heroicon-o-play',
            self::CLOSED => 'heroicon-o-stop',
            self::UNDER_REVIEW => 'heroicon-o-exclamation-triangle',
        };
    }
}


