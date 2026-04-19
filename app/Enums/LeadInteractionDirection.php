<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadInteractionDirection: string
{
    case Inbound  = 'inbound';
    case Outbound = 'outbound';
    case Internal = 'internal';
}
