<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadStatus: string
{
    case New             = 'new';
    case Contacted       = 'contacted';
    case Qualified       = 'qualified';
    case Quoted          = 'quoted';
    case WaitingGuest    = 'waiting_guest';
    case WaitingInternal = 'waiting_internal';
    case Tentative       = 'tentative';
    case Converted       = 'converted';
    case Lost            = 'lost';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Converted, self::Lost => true,
            default => false,
        };
    }
}
