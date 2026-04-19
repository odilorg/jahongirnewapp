<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadFollowUpType: string
{
    case Call      = 'call';
    case Message   = 'message';
    case SendQuote = 'send_quote';
    case CheckIn   = 'check_in';
    case Other     = 'other';
}
