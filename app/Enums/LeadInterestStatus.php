<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadInterestStatus: string
{
    case Exploring = 'exploring';
    case Quoted    = 'quoted';
    case Tentative = 'tentative';
    case Accepted  = 'accepted';
    case Declined  = 'declined';
    case Expired   = 'expired';
}
