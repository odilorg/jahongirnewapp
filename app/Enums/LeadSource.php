<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadSource: string
{
    case Website    = 'website';
    case WhatsAppIn = 'wa_inbound';
    case TelegramIn = 'tg_inbound';
    case EmailIn    = 'email_inbound';
    case Referral   = 'referral';
    case WalkIn     = 'walk_in';
    case Other      = 'other';
}
