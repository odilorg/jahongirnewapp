<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Unified source taxonomy for ledger_entries.
 *
 * Introduced in L-003. Replaces scattered string literals
 * ('cashier_bot', 'manual_admin', 'beds24_external') and the
 * CashTransactionSource enum for all ledger-layer writes.
 *
 * CashTransactionSource stays alive until legacy cash_transactions
 * is retired (post-backfill, L-019/L-020).
 */
enum SourceTrigger: string
{
    // External — authoritative
    case Beds24Webhook  = 'beds24_webhook';
    case Beds24Repair   = 'beds24_repair';
    case OctoCallback   = 'octo_callback';
    case GygImport      = 'gyg_import';

    // Internal operator — real-time staff input
    case CashierBot     = 'cashier_bot';
    case PosBot         = 'pos_bot';
    case OwnerBot       = 'owner_bot';

    // Internal manual — admin-entered
    case FilamentAdmin  = 'filament_admin';

    // Internal automatic
    case ReconcileJob   = 'reconcile_job';
    case SystemBackfill = 'system_backfill';

    public function trustLevel(): TrustLevel
    {
        return match ($this) {
            self::Beds24Webhook,
            self::Beds24Repair,
            self::OctoCallback,
            self::GygImport      => TrustLevel::Authoritative,

            self::CashierBot,
            self::PosBot,
            self::OwnerBot       => TrustLevel::Operator,

            self::FilamentAdmin,
            self::ReconcileJob,
            self::SystemBackfill => TrustLevel::Manual,
        };
    }
}
