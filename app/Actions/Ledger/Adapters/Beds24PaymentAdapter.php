<?php

declare(strict_types=1);

namespace App\Actions\Ledger\Adapters;

use App\Actions\Ledger\RecordLedgerEntry;
use App\DTOs\Ledger\LedgerEntryInput;
use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Models\LedgerEntry;
use Carbon\Carbon;

/**
 * L-004 scaffold. Translates a Beds24 payment-webhook payload into a
 * LedgerEntryInput and records it via RecordLedgerEntry.
 *
 * Not yet wired to Beds24WebhookController — activation happens in L-007.
 * Writing tests today against this class proves the end-to-end path
 * from "external webhook" → "canonical ledger row".
 *
 * Idempotency key format: "b24_item_{beds24_item_id}". Identical to
 * the legacy Beds24 webhook dedup key so the future backfill can match
 * by external_reference + external_item_ref without re-deriving.
 */
final class Beds24PaymentAdapter
{
    public function __construct(
        private readonly RecordLedgerEntry $recorder,
    ) {}

    public function record(
        string   $beds24BookingId,
        string   $beds24ItemId,
        float    $amount,
        Currency $currency,
        string   $beds24PaymentMethod,
        ?string  $guestName = null,
        ?string  $roomNumber = null,
        ?int     $cashierShiftId = null,
        ?Carbon  $occurredAt = null,
    ): LedgerEntry {
        return $this->recorder->execute(new LedgerEntryInput(
            entryType:         LedgerEntryType::AccommodationPaymentIn,
            source:            SourceTrigger::Beds24Webhook,
            amount:            $amount,
            currency:          $currency,
            counterpartyType:  CounterpartyType::Guest,
            paymentMethod:     $this->mapPaymentMethod($beds24PaymentMethod),
            idempotencyKey:    "b24_item_{$beds24ItemId}",
            beds24BookingId:   $beds24BookingId,
            cashierShiftId:    $cashierShiftId,
            occurredAt:        $occurredAt,
            externalReference: $beds24BookingId,
            externalItemRef:   "b24_item_{$beds24ItemId}",
            createdByBotSlug:  'beds24_webhook',
            notes:             $this->buildNotes($guestName, $roomNumber),
            tags:              ['beds24', 'external-webhook'],
        ));
    }

    private function mapPaymentMethod(string $beds24Method): PaymentMethod
    {
        return match (mb_strtolower(trim($beds24Method))) {
            'cash', 'naqd', 'наличные' => PaymentMethod::Cash,
            'card', 'карта'            => PaymentMethod::Card,
            'transfer', 'bank', 'перевод' => PaymentMethod::BankTransfer,
            default                    => PaymentMethod::Beds24External,
        };
    }

    private function buildNotes(?string $guestName, ?string $roomNumber): ?string
    {
        $parts = array_filter([
            $guestName  ? "Guest: {$guestName}"  : null,
            $roomNumber ? "Room: {$roomNumber}"  : null,
        ]);
        return $parts === [] ? null : implode(' | ', $parts);
    }
}
