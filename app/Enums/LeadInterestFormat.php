<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadInterestFormat: string
{
    case Group   = 'group';
    case Private = 'private';
    case Unknown = 'unknown';
}
