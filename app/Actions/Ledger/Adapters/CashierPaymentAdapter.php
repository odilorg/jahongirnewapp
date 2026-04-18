<?php

declare(strict_types=1);

namespace App\Actions\Ledger\Adapters;

use App\Actions\Ledger\RecordLedgerEntry;
use App\DTOs\Ledger\LedgerEntryInput;
use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerDataQuality;
use App\Enums\LedgerEntryType;
use App\Enums\OverrideTier;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Models\LedgerEntry;
use Carbon\Carbon;

/**
 * L-004 scaffold. Translates a cashier-bot payment (from
 * CashierBotController's fx path) into a LedgerEntryInput and records
 * it via RecordLedgerEntry.
 *
 * Activation happens in L-009. At that point the existing canonical
 * App\Services\BotPaymentService (from L-002) will call this adapter
 * inside its DB::transaction — so the ledger row lands in the same
 * atomic boundary as the cash_transactions row during coexistence
 * (shadow mode L-006.5).
 */
final class CashierPaymentAdapter
{
    public function __construct(
        private readonly RecordLedgerEntry $recorder,
    ) {}

    public function record(
        string       $beds24BookingId,
        float        $amount,
        Currency     $currency,
        PaymentMethod $paymentMethod,
        int          $cashierShiftId,
        int          $cashierUserId,
        string       $botSessionId,
        // FX snapshot captured at presentation time (from canonical BotPaymentService)
        ?array       $presentationSnapshot = null,
        ?int         $bookingFxSyncId = null,    // external_item_ref-style linkage
        ?int         $dailyExchangeRateId = null,
        ?int         $exchangeRateId = null,
        ?float       $usdEquivalent = null,
        // Override chain
        OverrideTier $overrideTier = OverrideTier::None,
        ?int         $overrideApprovalId = null,
        ?float       $variancePct = null,
        ?string      $guestName = null,
        ?string      $roomNumber = null,
        ?Carbon      $occurredAt = null,
    ): LedgerEntry {
        return $this->recorder->execute(new LedgerEntryInput(
            entryType:            LedgerEntryType::AccommodationPaymentIn,
            source:               SourceTrigger::CashierBot,
            amount:               $amount,
            currency:             $currency,
            counterpartyType:     CounterpartyType::Guest,
            paymentMethod:        $paymentMethod,
            // Bot session id is a strong idempotency key — Telegram guarantees
            // uniqueness per (chat, message), so a double-tap on "confirm"
            // returns the existing row instead of creating a duplicate.
            idempotencyKey:       "cashier_{$botSessionId}",
            beds24BookingId:      $beds24BookingId,
            cashierShiftId:       $cashierShiftId,
            occurredAt:           $occurredAt,
            dailyExchangeRateId:  $dailyExchangeRateId,
            exchangeRateId:       $exchangeRateId,
            presentationSnapshot: $presentationSnapshot,
            usdEquivalent:        $usdEquivalent,
            overrideTier:         $overrideTier,
            overrideApprovalId:   $overrideApprovalId,
            variancePct:          $variancePct,
            externalReference:    $beds24BookingId,
            externalItemRef:      $bookingFxSyncId !== null ? "fx_sync_{$bookingFxSyncId}" : null,
            createdByUserId:      $cashierUserId,
            createdByBotSlug:     'cashier',
            notes:                $this->buildNotes($guestName, $roomNumber),
            tags:                 $this->buildTags($overrideTier),
            dataQuality:          LedgerDataQuality::Ok,
        ));
    }

    private function buildNotes(?string $guestName, ?string $roomNumber): ?string
    {
        $parts = array_filter([
            $guestName  ? "Guest: {$guestName}"  : null,
            $roomNumber ? "Room: {$roomNumber}"  : null,
        ]);
        return $parts === [] ? null : implode(' | ', $parts);
    }

    private function buildTags(OverrideTier $tier): array
    {
        $tags = ['cashier-bot'];
        if ($tier !== OverrideTier::None) {
            $tags[] = 'override:' . $tier->value;
        }
        return $tags;
    }
}
