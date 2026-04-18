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
 * L-004 scaffold. Translates an Octobank callback payload into a
 * LedgerEntryInput and records it via RecordLedgerEntry.
 *
 * Activation happens in L-008, which also kills the legacy
 * OctoCallbackController::handleBookingCallback path and fixes the
 * amount-drift issue (recording `$paidSum` instead of `price_quoted`).
 *
 * Amount semantics: the caller passes the ACTUAL sum reported by Octo,
 * NOT the inquiry's price_quoted. This is a key L-004 invariant that
 * the Phase 3.5 deep-dive called out as a silent data-loss bug in the
 * current OctoCallbackController code.
 *
 * Idempotency key: Octo transaction_id. Authoritative source requires
 * an external_reference, so we also pass it.
 */
final class OctoPaymentAdapter
{
    public function __construct(
        private readonly RecordLedgerEntry $recorder,
    ) {}

    public function record(
        string   $octoTransactionId,
        float    $actualPaidAmount,
        Currency $currency,
        int      $bookingInquiryId,
        ?string  $guestName = null,
        ?Carbon  $occurredAt = null,
    ): LedgerEntry {
        return $this->recorder->execute(new LedgerEntryInput(
            entryType:         LedgerEntryType::AccommodationPaymentIn,
            source:            SourceTrigger::OctoCallback,
            amount:            $actualPaidAmount,
            currency:          $currency,
            counterpartyType:  CounterpartyType::Guest,
            paymentMethod:     PaymentMethod::OctoOnline,
            idempotencyKey:    $octoTransactionId,
            bookingInquiryId:  $bookingInquiryId,
            occurredAt:        $occurredAt,
            externalReference: $octoTransactionId,
            createdByBotSlug:  'octo_callback',
            notes:             $guestName ? "Octo online payment: {$guestName}" : 'Octo online payment',
            tags:              ['octo', 'online-card'],
        ));
    }
}
