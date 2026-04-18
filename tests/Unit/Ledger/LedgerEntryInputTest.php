<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\DTOs\Ledger\LedgerEntryInput;
use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\OverrideTier;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Exceptions\Ledger\InvalidLedgerEntryException;
use Tests\TestCase;

/**
 * Synchronous validation rules on LedgerEntryInput.
 * No DB required — pure DTO behavior.
 */
class LedgerEntryInputTest extends TestCase
{
    public function test_valid_input_passes_validation(): void
    {
        $this->makeInput()->validate();
        $this->assertTrue(true);
    }

    public function test_amount_must_be_positive(): void
    {
        $input = $this->makeInput(amount: 0.0);

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_negative_amount_rejected(): void
    {
        $input = $this->makeInput(amount: -10.0);

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_direction_auto_derives_from_entry_type(): void
    {
        $input = $this->makeInput(entryType: LedgerEntryType::AccommodationPaymentIn, direction: null);
        $this->assertSame(LedgerEntryDirection::In, $input->resolvedDirection());

        $input = $this->makeInput(entryType: LedgerEntryType::SupplierPaymentOut, direction: null);
        $this->assertSame(LedgerEntryDirection::Out, $input->resolvedDirection());
    }

    public function test_explicit_direction_overrides_default(): void
    {
        $input = $this->makeInput(
            entryType: LedgerEntryType::AccommodationPaymentIn,
            direction: LedgerEntryDirection::Out,   // explicit, unusual but allowed
        );

        $this->assertSame(LedgerEntryDirection::Out, $input->resolvedDirection());
    }

    public function test_ambiguous_entry_type_without_direction_throws(): void
    {
        $input = $this->makeInput(
            entryType: LedgerEntryType::CurrencyExchangeLeg,
            direction: null,   // ambiguous — must be explicit
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_ambiguous_type_with_explicit_direction_passes(): void
    {
        $input = $this->makeInput(
            entryType: LedgerEntryType::CurrencyExchangeLeg,
            direction: LedgerEntryDirection::Out,
        );

        $input->validate();
        $this->assertSame(LedgerEntryDirection::Out, $input->resolvedDirection());
    }

    public function test_internal_counterparty_rejects_id(): void
    {
        $input = $this->makeInput(
            counterpartyType: CounterpartyType::Internal,
            counterpartyId:   42,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_external_counterparty_rejects_id(): void
    {
        $input = $this->makeInput(
            counterpartyType: CounterpartyType::External,
            counterpartyId:   42,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_override_tier_without_variance_pct_throws(): void
    {
        $input = $this->makeInput(
            overrideTier: OverrideTier::Manager,
            variancePct:  null,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_override_tier_none_allows_null_variance(): void
    {
        $input = $this->makeInput(
            overrideTier: OverrideTier::None,
            variancePct:  null,
        );

        $input->validate();
        $this->assertTrue(true);
    }

    public function test_authoritative_source_requires_external_reference(): void
    {
        $input = $this->makeInput(
            source:            SourceTrigger::Beds24Webhook,
            externalReference: null,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_operator_source_without_external_reference_is_ok(): void
    {
        $input = $this->makeInput(
            source:            SourceTrigger::CashierBot,
            externalReference: null,
            createdByBotSlug:  'cashier',
        );

        $input->validate();
        $this->assertTrue(true);
    }

    public function test_reversal_and_parent_are_mutually_exclusive(): void
    {
        $input = $this->makeInput(
            reversesEntryId: 1,
            parentEntryId:   2,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_operator_source_requires_authorship(): void
    {
        $input = $this->makeInput(
            source:           SourceTrigger::CashierBot,
            createdByUserId:  null,
            createdByBotSlug: null,
        );

        $this->expectException(InvalidLedgerEntryException::class);
        $input->validate();
    }

    public function test_system_sources_do_not_require_authorship(): void
    {
        $input = $this->makeInput(
            source:           SourceTrigger::SystemBackfill,
            createdByUserId:  null,
            createdByBotSlug: null,
        );

        $input->validate();
        $this->assertTrue(true);
    }

    public function test_idempotency_fingerprint_excludes_non_essential_fields(): void
    {
        $a = $this->makeInput(notes: 'original notes');
        $b = $this->makeInput(notes: 'different notes');

        $this->assertSame($a->idempotencyFingerprint(), $b->idempotencyFingerprint());
    }

    public function test_idempotency_fingerprint_changes_on_amount_change(): void
    {
        $a = $this->makeInput(amount: 100.0);
        $b = $this->makeInput(amount: 200.0);

        $this->assertNotSame($a->idempotencyFingerprint(), $b->idempotencyFingerprint());
    }

    /**
     * Factory helper — construct a valid DTO with per-test overrides.
     */
    private function makeInput(
        ?LedgerEntryType    $entryType = null,
        ?SourceTrigger      $source = null,
        ?float              $amount = null,
        ?Currency           $currency = null,
        ?CounterpartyType   $counterpartyType = null,
        ?PaymentMethod      $paymentMethod = null,
        ?LedgerEntryDirection $direction = null,
        ?string             $idempotencyKey = null,
        ?int                $counterpartyId = null,
        ?int                $bookingInquiryId = null,
        ?string             $beds24BookingId = null,
        ?int                $cashierShiftId = null,
        ?string             $externalReference = 'ext_ref_default',
        OverrideTier        $overrideTier = OverrideTier::None,
        ?float              $variancePct = null,
        ?int                $reversesEntryId = null,
        ?int                $parentEntryId = null,
        ?int                $createdByUserId = null,
        ?string             $createdByBotSlug = 'test',
        ?string             $notes = null,
    ): LedgerEntryInput {
        return new LedgerEntryInput(
            entryType:         $entryType         ?? LedgerEntryType::AccommodationPaymentIn,
            source:            $source            ?? SourceTrigger::CashierBot,
            amount:            $amount            ?? 100.0,
            currency:          $currency          ?? Currency::USD,
            counterpartyType:  $counterpartyType  ?? CounterpartyType::Guest,
            paymentMethod:     $paymentMethod     ?? PaymentMethod::Cash,
            direction:         $direction,
            idempotencyKey:    $idempotencyKey,
            counterpartyId:    $counterpartyId,
            bookingInquiryId:  $bookingInquiryId,
            beds24BookingId:   $beds24BookingId,
            cashierShiftId:    $cashierShiftId,
            overrideTier:      $overrideTier,
            variancePct:       $variancePct,
            parentEntryId:     $parentEntryId,
            reversesEntryId:   $reversesEntryId,
            externalReference: $externalReference,
            createdByUserId:   $createdByUserId,
            createdByBotSlug:  $createdByBotSlug,
            notes:             $notes,
        );
    }
}
