<?php

declare(strict_types=1);

namespace App\DTOs\Ledger;

use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerDataQuality;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\OverrideTier;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Exceptions\Ledger\InvalidLedgerEntryException;
use Carbon\Carbon;

/**
 * Typed, validated input contract for RecordLedgerEntry.
 *
 * Every caller that wants a row in ledger_entries constructs one of
 * these. No other input shape is accepted. Source-specific adapters
 * translate their own payloads into this DTO.
 *
 * Validation is split:
 *   - DTO::validate()              : synchronous local rules (amount
 *                                    positivity, direction/type
 *                                    compatibility, trust-level
 *                                    expectations). No DB access.
 *   - RecordLedgerEntry::execute() : DB-touching checks (shift open,
 *                                    reversal target exists, parent
 *                                    entry exists in same currency
 *                                    with opposite direction).
 */
final class LedgerEntryInput
{
    public function __construct(
        // --- Required taxonomy + value ------------------------------------
        public readonly LedgerEntryType    $entryType,
        public readonly SourceTrigger      $source,
        public readonly float              $amount,
        public readonly Currency           $currency,
        public readonly CounterpartyType   $counterpartyType,
        public readonly PaymentMethod      $paymentMethod,

        // --- Direction: explicit OR derived from entryType ----------------
        public readonly ?LedgerEntryDirection $direction = null,

        // --- Idempotency --------------------------------------------------
        public readonly ?string $idempotencyKey = null,

        // --- Counterparty -------------------------------------------------
        public readonly ?int $counterpartyId = null,

        // --- Domain context (nullable FKs) --------------------------------
        public readonly ?int    $bookingInquiryId = null,
        public readonly ?string $beds24BookingId  = null,
        public readonly ?int    $cashierShiftId   = null,
        public readonly ?int    $cashDrawerId     = null,

        // --- Temporal -----------------------------------------------------
        public readonly ?Carbon $occurredAt = null,

        // --- FX snapshot (optional, frozen at write time) -----------------
        public readonly ?float  $fxRate              = null,
        public readonly ?Carbon $fxRateDate          = null,
        public readonly ?int    $dailyExchangeRateId = null,
        public readonly ?int    $exchangeRateId      = null,
        public readonly ?array  $presentationSnapshot = null,
        public readonly ?float  $usdEquivalent       = null,

        // --- Override / approval chain ------------------------------------
        public readonly OverrideTier $overrideTier       = OverrideTier::None,
        public readonly ?int         $overrideApprovalId = null,
        public readonly ?float       $variancePct        = null,

        // --- Linkage ------------------------------------------------------
        public readonly ?int $parentEntryId    = null,
        public readonly ?int $reversesEntryId  = null,

        // --- External references ------------------------------------------
        public readonly ?string $externalReference = null,
        public readonly ?string $externalItemRef   = null,

        // --- Authorship ---------------------------------------------------
        public readonly ?int    $createdByUserId   = null,
        public readonly ?string $createdByBotSlug  = null,

        // --- Audit metadata -----------------------------------------------
        public readonly ?string            $notes        = null,
        public readonly ?array             $tags         = null,
        public readonly LedgerDataQuality  $dataQuality  = LedgerDataQuality::Ok,
    ) {}

    /**
     * Resolve direction — explicit if provided, otherwise
     * derived from the entry type's default.
     *
     * @throws InvalidLedgerEntryException when direction is ambiguous
     */
    public function resolvedDirection(): LedgerEntryDirection
    {
        if ($this->direction !== null) {
            return $this->direction;
        }

        $default = $this->entryType->defaultDirection();
        if ($default === null) {
            throw new InvalidLedgerEntryException(
                "Entry type '{$this->entryType->value}' has no default direction. "
                . 'Callers must pass direction explicitly for exchange legs and adjustments.'
            );
        }

        return $default;
    }

    public function resolvedOccurredAt(): Carbon
    {
        return $this->occurredAt ?? Carbon::now();
    }

    /**
     * Synchronous local validation. No DB access.
     *
     * @throws InvalidLedgerEntryException
     */
    public function validate(): void
    {
        // 1. Amount positivity — direction gives the sign; amount is magnitude.
        if ($this->amount <= 0.0) {
            throw new InvalidLedgerEntryException(
                "Ledger amount must be positive; got {$this->amount}. "
                . 'Use direction=out for payouts, not negative amounts.'
            );
        }

        // 2. Direction resolves cleanly (throws if ambiguous).
        $this->resolvedDirection();

        // 3. Counterparty pairing — if counterpartyId is provided, type must
        //    be one that references a concrete record (guest/supplier/etc.).
        //    Internal and external counterparties accept null ids.
        if ($this->counterpartyId !== null) {
            if (in_array($this->counterpartyType, [CounterpartyType::Internal, CounterpartyType::External], true)) {
                throw new InvalidLedgerEntryException(
                    "counterpartyType '{$this->counterpartyType->value}' does not take a counterpartyId."
                );
            }
        }

        // 4. Override variance — if tier != none, variance_pct should be set.
        if ($this->overrideTier !== OverrideTier::None && $this->variancePct === null) {
            throw new InvalidLedgerEntryException(
                'override_tier is non-none but variance_pct is missing.'
            );
        }

        // 5. Authoritative sources must carry an external_reference so the
        //    row can be deduped / traced to the external system.
        if ($this->source->trustLevel()->value === 'authoritative'
            && ($this->externalReference === null || $this->externalReference === '')
        ) {
            throw new InvalidLedgerEntryException(
                "Authoritative source '{$this->source->value}' requires external_reference."
            );
        }

        // 6. Linkage sanity: a row cannot be both a reversal and a child leg.
        if ($this->reversesEntryId !== null && $this->parentEntryId !== null) {
            throw new InvalidLedgerEntryException(
                'reverses_entry_id and parent_entry_id are mutually exclusive.'
            );
        }

        // 7. Authorship: at least one of user/bot must be present unless
        //    the source is an automatic job.
        $automaticSources = [SourceTrigger::ReconcileJob, SourceTrigger::SystemBackfill];
        if (! in_array($this->source, $automaticSources, true)
            && $this->createdByUserId === null
            && $this->createdByBotSlug === null
        ) {
            throw new InvalidLedgerEntryException(
                "Source '{$this->source->value}' requires created_by_user_id or created_by_bot_slug."
            );
        }
    }

    /**
     * Fields checked when deciding whether an idempotent replay is
     * identical to the previously-stored row. Differences in any of
     * these fields turn a replay into an idempotency conflict.
     */
    public function idempotencyFingerprint(): array
    {
        return [
            'entry_type'        => $this->entryType->value,
            'direction'         => $this->resolvedDirection()->value,
            'amount'            => number_format($this->amount, 2, '.', ''),
            'currency'          => $this->currency->value,
            'counterparty_type' => $this->counterpartyType->value,
            'counterparty_id'   => $this->counterpartyId,
            'payment_method'    => $this->paymentMethod->value,
            'external_reference' => $this->externalReference,
            'external_item_ref' => $this->externalItemRef,
        ];
    }
}
