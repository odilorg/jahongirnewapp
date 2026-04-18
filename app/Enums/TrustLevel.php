<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Trust level of a ledger entry's source.
 *
 * Determines how reports and reconciliation treat the row:
 *  - authoritative: external source of record (Beds24, Octo, GYG)
 *  - operator:      real-time staff input via a bot
 *  - manual:        admin-entered adjustment, backfill, or reconcile job
 */
enum TrustLevel: string
{
    case Authoritative = 'authoritative';
    case Operator      = 'operator';
    case Manual        = 'manual';
}
