<?php

declare(strict_types=1);

namespace App\Actions\Payment\Support;

/**
 * Immutable payment context passed to both email and WhatsApp receipt builders.
 * Computed once in SendReceiptAction so both channels get identical figures.
 */
final class ReceiptContext
{
    public readonly bool $isPartial;

    public function __construct(
        public readonly float $onlinePaidUsd,
        public readonly float $priceQuotedUsd,
        public readonly float $remainingCashUsd,
        public readonly string $uzsAmountRaw,
    ) {
        $this->isPartial = $remainingCashUsd > 0.005;
    }

    public static function fromInquiry(\App\Models\BookingInquiry $inquiry, string $uzsAmountRaw = ''): self
    {
        $online    = (float) ($inquiry->amount_online_usd ?? $inquiry->price_quoted ?? 0);
        $quoted    = (float) ($inquiry->price_quoted ?? 0);
        $remaining = max(0.0, $quoted - $online);

        return new self(
            onlinePaidUsd:    $online,
            priceQuotedUsd:   $quoted,
            remainingCashUsd: $remaining,
            uzsAmountRaw:     $uzsAmountRaw,
        );
    }

    public function paxLine(\App\Models\BookingInquiry $inquiry): string
    {
        $parts = [];
        if ($inquiry->people_adults > 0) {
            $parts[] = $inquiry->people_adults . ' adult' . ($inquiry->people_adults > 1 ? 's' : '');
        }
        if ($inquiry->people_children > 0) {
            $parts[] = $inquiry->people_children . ' child' . ($inquiry->people_children > 1 ? 'ren' : '');
        }
        return $parts ? implode(', ', $parts) : '1 person';
    }
}
