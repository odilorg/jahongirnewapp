<?php

namespace App\DTOs\Fx;

/**
 * Result of BotPaymentService::preparePayment().
 * Carries the presentation and remaining balance before the cashier confirms.
 */
final class PreparedPayment
{
    public function __construct(
        public readonly PaymentPresentation $presentation,
        public readonly RemainingBalance    $remainingBalance,
    ) {}
}
