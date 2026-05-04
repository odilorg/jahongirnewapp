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
    ) {}
}
