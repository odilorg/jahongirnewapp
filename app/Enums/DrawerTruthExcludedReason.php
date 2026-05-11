<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a beds24_external CashTransaction row was NOT auto-flagged
 * `counts_as_drawer_truth = true` at webhook-write time.
 *
 * Stored as the string value in `cash_transactions.drawer_truth_excluded_reason`.
 * Phase 1 (2026-05-11) introduces 5 reasons matching the 5 guards in
 * `Beds24WebhookController::createExternalBookkeepingRow`.
 *
 * Used by:
 *  - Webhook handler — sets reason on every excluded row for audit
 *  - Filament reconciliation page — filters/groups rows by reason
 *  - Tests — asserts the correct guard fired for each fixture
 *
 * Null on the column means the row IS drawer truth (no exclusion
 * reason exists). When a manager flips the flag manually via the
 * Filament page, the reason is preserved on the row so the audit
 * trail shows "originally excluded due to X, manager overrode".
 */
enum DrawerTruthExcludedReason: string
{
    /** Guard 1: payment_method not in `cashier.beds24_external_cash_methods` allow-list. */
    case NonCashMethod = 'non_cash_method';

    /** Guard 2: occurred_at is before `cashier.beds24_admin_cash_drawer_truth_from`. */
    case BeforeCutoff = 'before_cutoff';

    /** Guard 3: a cashier_bot row already exists for the same booking/amount/window. */
    case MatchingCashierBotRow = 'matching_cashier_bot_row';

    /** Guard 4: no open CashierShift at the time the webhook arrived. */
    case NoOpenShift = 'no_open_shift';

    /** Guard 5: `beds24_booking_id` is NULL — not a real Beds24 webhook row. */
    case MissingBookingId = 'missing_booking_id';

    public function humanLabel(): string
    {
        return match ($this) {
            self::NonCashMethod => 'Метод не наличные',
            self::BeforeCutoff => 'До даты включения функции',
            self::MatchingCashierBotRow => 'Дубликат записи бота',
            self::NoOpenShift => 'Смена кассира не открыта',
            self::MissingBookingId => 'Нет ID брони',
        };
    }
}
