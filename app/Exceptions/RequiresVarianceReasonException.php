<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by BotPaymentService::recordMixedCurrencySplitPayment when
 * the legs total in base currency deviates from booking total beyond
 * the silent tolerance band, but no MixedCurrencyVarianceContext was
 * supplied. The exception payload describes the variance so the
 * caller (Filament form) can re-render with the reason picker.
 *
 * Distinguished from generic InvalidArgumentException so callers can
 * present a constructive next step ("pick a reason") rather than a
 * hard error.
 */
class RequiresVarianceReasonException extends \RuntimeException
{
    public function __construct(
        public readonly float  $expectedInBase,
        public readonly float  $actualInBase,
        public readonly float  $varianceInBase,
        public readonly float  $variancePct,
        public readonly string $baseCurrency,
        public readonly bool   $requiresManagerApproval,
        public readonly float  $impliedRate,    // operator-implied rate (legs / expected ratio)
        public readonly float  $frozenRate,     // booking's frozen presentation rate
        string $message = 'Mixed-currency split requires a variance reason.',
    ) {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return [
            'expected_in_base'             => $this->expectedInBase,
            'actual_in_base'               => $this->actualInBase,
            'variance_in_base'             => $this->varianceInBase,
            'variance_pct'                 => $this->variancePct,
            'base_currency'                => $this->baseCurrency,
            'requires_manager_approval'    => $this->requiresManagerApproval,
            'implied_rate'                 => $this->impliedRate,
            'frozen_rate'                  => $this->frozenRate,
        ];
    }
}
