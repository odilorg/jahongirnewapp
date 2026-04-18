<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Event taxonomy for ledger_entries.
 *
 * Replaces the inconsistent TransactionCategory string on cash_transactions.
 * Every money event must map to exactly one of these types.
 */
enum LedgerEntryType: string
{
    // Revenue
    case AccommodationPaymentIn = 'accommodation_payment_in';
    case TourPaymentIn          = 'tour_payment_in';
    case OtherRevenueIn         = 'other_revenue_in';

    // Refunds
    case AccommodationRefund    = 'accommodation_refund';
    case TourRefund             = 'tour_refund';
    case OtherRefund            = 'other_refund';

    // Payouts
    case SupplierPaymentOut     = 'supplier_payment_out';   // accommodation / driver / guide / vendor
    case AgentCommissionOut     = 'agent_commission_out';
    case StaffPayoutOut         = 'staff_payout_out';

    // Cash-drawer operational
    case CashDrawerOpen         = 'cash_drawer_open';       // beginning saldo
    case CashDrawerClose        = 'cash_drawer_close';      // end saldo
    case CashDeposit            = 'cash_deposit';           // manual admin top-up
    case CashWithdrawal         = 'cash_withdrawal';

    // Expenses
    case OperationalExpense     = 'operational_expense';

    // FX
    case CurrencyExchangeLeg    = 'currency_exchange_leg';  // one side of exchange pair

    // Adjustments
    case ReconciliationAdjust   = 'reconciliation_adjust';  // force-match external truth
    case ManualAdjustment       = 'manual_adjustment';
    case ShiftHandoverAdjust    = 'shift_handover_adjust';

    /**
     * Canonical direction for this entry type when unambiguous.
     *
     * For CurrencyExchangeLeg and *Adjust types the direction depends on
     * caller intent (in vs out), so the caller must specify explicitly.
     *
     * Used by the L-004 RecordLedgerEntry action to validate that the
     * caller's direction matches the entry type.
     */
    public function defaultDirection(): ?LedgerEntryDirection
    {
        return match ($this) {
            self::AccommodationPaymentIn,
            self::TourPaymentIn,
            self::OtherRevenueIn,
            self::CashDeposit,
            self::CashDrawerOpen       => LedgerEntryDirection::In,

            self::AccommodationRefund,
            self::TourRefund,
            self::OtherRefund,
            self::SupplierPaymentOut,
            self::AgentCommissionOut,
            self::StaffPayoutOut,
            self::OperationalExpense,
            self::CashWithdrawal,
            self::CashDrawerClose      => LedgerEntryDirection::Out,

            // Explicit direction required at call site
            self::CurrencyExchangeLeg,
            self::ReconciliationAdjust,
            self::ManualAdjustment,
            self::ShiftHandoverAdjust  => null,
        };
    }
}
