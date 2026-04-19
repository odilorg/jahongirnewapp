<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadContactChannel: string
{
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';
    case Email    = 'email';
    case Phone    = 'phone';
}
