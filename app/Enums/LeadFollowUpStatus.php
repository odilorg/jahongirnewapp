<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadFollowUpStatus: string
{
    case Open      = 'open';
    case Done      = 'done';
    case Cancelled = 'cancelled';
}
