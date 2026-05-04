<?php

declare(strict_types=1);

namespace App\DTO;

use App\Models\FxManagerApproval;

/**
 * Operator-supplied context for accepting an FX variance on a
 * mixed-currency split. Required when the legs total in base currency
 * deviates from booking total beyond the silent tolerance band.
 *
 * See docs/architecture/PHASE_1_5_PLAN.md for the variance bands.
 */
readonly class MixedCurrencyVarianceContext
{
    public const REASON_AGREED_SHOP_RATE   = 'agreed_shop_rate';
    public const REASON_BILL_DENOMINATION  = 'bill_denomination';
    public const REASON_GUEST_OVERPAY      = 'guest_overpay';
    public const REASON_GUEST_UNDERPAY     = 'guest_underpay';
    public const REASON_RATE_DRIFT         = 'rate_drift';
    public const REASON_OTHER              = 'other';

    public const ALL_REASONS = [
        self::REASON_AGREED_SHOP_RATE,
        self::REASON_BILL_DENOMINATION,
        self::REASON_GUEST_OVERPAY,
        self::REASON_GUEST_UNDERPAY,
        self::REASON_RATE_DRIFT,
        self::REASON_OTHER,
    ];

    public function __construct(
        public string             $reason,                 // one of REASON_*
        public ?string            $freeTextNote   = null,  // mandatory when reason='other', free-form for any reason
        public ?FxManagerApproval $managerApproval = null, // required when variance > 3% of booking total in base
    ) {
        if (! in_array($reason, self::ALL_REASONS, true)) {
            throw new \InvalidArgumentException("Unknown FX variance reason: {$reason}");
        }
        if ($reason === self::REASON_OTHER && empty($freeTextNote)) {
            throw new \InvalidArgumentException('Free-text note is required when reason="other".');
        }
    }
}
