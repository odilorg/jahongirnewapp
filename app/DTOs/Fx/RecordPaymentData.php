<?php

namespace App\DTOs\Fx;

use App\Enums\Currency;

/**
 * Validated input data for recording a single payment.
 * Assembled by the bot controller and passed to BotPaymentService::recordPayment().
 */
final class RecordPaymentData
{
    public function __construct(
        public readonly string              $beds24BookingId,
        public readonly Currency            $paidCurrency,
        public readonly float|int           $paidAmount,
        public readonly string              $paymentMethod,      // e.g. 'cash', 'card', 'transfer'
        public readonly string              $botSessionId,
        public readonly int                 $cashierShiftId,
        public readonly int                 $createdBy,          // Telegram user → staff user ID

        // The presentation shown to the cashier — amounts are frozen here
        public readonly PaymentPresentation $presentation,

        // Set when this is an override requiring manager sign-off
        public readonly ?int                $overrideApprovalId = null,
        public readonly ?string             $overrideReason = null,
    ) {}
}
