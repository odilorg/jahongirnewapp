<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment instrument for a ledger entry.
 *
 * Stored as enum code — never display text. Display/translation is
 * UI-layer concern.
 *
 * Replaces scattered string literals like 'cash', 'card', 'transfer',
 * 'octo', 'naqd', 'наличные' from the legacy cash_transactions path.
 */
enum PaymentMethod: string
{
    case Cash            = 'cash';
    case Card            = 'card';
    case BankTransfer    = 'bank_transfer';
    case OctoOnline      = 'octo_online';
    case GygPrePaid      = 'gyg_pre_paid';
    case Beds24External  = 'beds24_external';   // arrived via Beds24 outside our bots
    case Internal        = 'internal';          // drawer open/close, adjustment, exchange leg
}
