<?php

namespace App\DTO;

use App\Models\FxManagerApproval;

/**
 * Validated input for BotPaymentService::recordPayment().
 *
 * Carries the frozen PaymentPresentation alongside cashier input.
 * Never contains raw exchange rates or calculated amounts.
 */
readonly class RecordPaymentData
{
    public function __construct(
        public PaymentPresentation  $presentation,   // frozen DTO from preparePayment()
        public int                  $shiftId,
        public int                  $cashierId,
        public string               $currencyPaid,   // ISO 4217: UZS, EUR, RUB, USD
        public float                $amountPaid,
        public string               $paymentMethod,  // cash | card | transfer
        public ?string              $overrideReason,
        public ?FxManagerApproval   $managerApproval, // non-null when override_tier = manager
        // Journal Entry Foundation — set when this row is part of a larger
        // logical transaction (split payments, exchange pairs, future
        // reversals). NULL for single-instrument standalone payments.
        public ?string              $journalEntryId    = null,  // 40-char UUID-like
        public string               $paymentGroupType  = 'single', // single | split | exchange | reversal | refund

        // Phase 1.5.1 — Mixed-currency split fields. Set ONLY when this
        // leg belongs to a mixed-currency journal (one leg in UZS, other
        // in USD/EUR, etc.). NULL for same-currency splits, standalone
        // payments, etc.
        public ?string              $baseCurrencyForSplit = null,  // ISO 4217
        public string               $journalStatus        = 'complete', // complete | pending_second_leg | voided | failed_sumlock
    ) {}
}
