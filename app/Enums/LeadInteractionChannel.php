<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadInteractionChannel: string
{
    case WhatsApp     = 'whatsapp';
    case Telegram     = 'telegram';
    case Email        = 'email';
    case Phone        = 'phone';
    case InPerson     = 'in_person';
    case InternalNote = 'internal_note';
}
