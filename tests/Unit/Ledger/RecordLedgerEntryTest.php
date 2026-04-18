<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Actions\Ledger\RecordLedgerEntry;
use App\DTOs\Ledger\LedgerEntryInput;
use App\Enums\CounterpartyType;
use App\Enums\Currency;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Enums\TrustLevel;
use App\Events\Ledger\LedgerEntryRecorded;
use App\Exceptions\Ledger\InvalidLedgerEntryException;
use App\Exceptions\Ledger\LedgerIdempotencyConflictException;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecordLedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_happy_path_creates_row(): void
    {
        Event::fake();
        $action = app(RecordLedgerEntry::class);

        $entry = $action->execute($this->input());

        $this->assertNotNull($entry->id);
        $this->assertNotNull($entry->ulid);
        $this->assertSame(1, LedgerEntry::count());
        Event::assertDispatched(LedgerEntryRecorded::class, fn (LedgerEntryRecorded $e) => $e->entry->id === $entry->id);
    }

    public function test_trust_level_is_derived_from_source(): void
    {
        $authoritative = app(RecordLedgerEntry::class)->execute($this->input(
            source: SourceTrigger::OctoCallback,
            idempotencyKey: 'octo_tx_1',
            externalReference: 'octo_tx_1',
            createdByBotSlug: 'octo_callback',
        ));
        $operator = app(RecordLedgerEntry::class)->execute($this->input(
            source: SourceTrigger::CashierBot,
            externalReference: 'b24_abc',
            createdByBotSlug: 'cashier',
        ));

        $this->assertSame(TrustLevel::Authoritative, $authoritative->trust_level);
        $this->assertSame(TrustLevel::Operator,      $operator->trust_level);
    }

    public function test_direction_derives_from_entry_type(): void
    {
        $entry = app(RecordLedgerEntry::class)->execute($this->input(
            entryType: LedgerEntryType::SupplierPaymentOut,
            counterpartyType: CounterpartyType::Supplier,
            // direction omitted — expect auto-derivation to Out
        ));

        $this->assertSame(LedgerEntryDirection::Out, $entry->direction);
    }

    public function test_ulid_auto_generated_and_unique(): void
    {
        $a = app(RecordLedgerEntry::class)->execute($this->input(idempotencyKey: null));
        $b = app(RecordLedgerEntry::class)->execute($this->input(idempotencyKey: null));

        $this->assertNotEmpty($a->ulid);
        $this->assertNotEmpty($b->ulid);
        $this->assertNotSame($a->ulid, $b->ulid);
    }

    public function test_idempotent_replay_returns_existing_row(): void
    {
        $action = app(RecordLedgerEntry::class);
        $input  = $this->input(idempotencyKey: 'octo_tx_42', source: SourceTrigger::OctoCallback, externalReference: 'octo_tx_42', createdByBotSlug: 'octo_callback');

        $first  = $action->execute($input);
        $second = $action->execute($input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_idempotent_conflict_with_different_payload_throws(): void
    {
        $action = app(RecordLedgerEntry::class);
        $first  = $action->execute($this->input(
            idempotencyKey: 'octo_tx_99',
            source: SourceTrigger::OctoCallback,
            externalReference: 'octo_tx_99',
            createdByBotSlug: 'octo_callback',
            amount: 100.0,
        ));

        $this->expectException(LedgerIdempotencyConflictException::class);
        $action->execute($this->input(
            idempotencyKey: 'octo_tx_99',
            source: SourceTrigger::OctoCallback,
            externalReference: 'octo_tx_99',
            createdByBotSlug: 'octo_callback',
            amount: 200.0,   // different payload
        ));

        // Original stays; no second row created.
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_same_idempotency_key_different_source_is_not_a_conflict(): void
    {
        $action = app(RecordLedgerEntry::class);
        $a = $action->execute($this->input(
            idempotencyKey: 'shared_key',
            source: SourceTrigger::OctoCallback,
            externalReference: 'shared_key',
            createdByBotSlug: 'octo_callback',
        ));
        $b = $action->execute($this->input(
            idempotencyKey: 'shared_key',
            source: SourceTrigger::GygImport,
            externalReference: 'shared_key',
            createdByBotSlug: 'gyg_import',
        ));

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_invalid_input_throws_and_writes_nothing(): void
    {
        try {
            app(RecordLedgerEntry::class)->execute($this->input(amount: 0.0));
            $this->fail('Expected InvalidLedgerEntryException');
        } catch (InvalidLedgerEntryException) {
            $this->assertSame(0, LedgerEntry::count());
        }
    }

    public function test_reversal_with_nonexistent_target_throws(): void
    {
        $this->expectException(InvalidLedgerEntryException::class);
        app(RecordLedgerEntry::class)->execute($this->input(
            entryType: LedgerEntryType::AccommodationRefund,
            reversesEntryId: 999999,
        ));
    }

    public function test_reversal_currency_mismatch_throws(): void
    {
        $original = app(RecordLedgerEntry::class)->execute($this->input(currency: Currency::USD));

        $this->expectException(InvalidLedgerEntryException::class);
        app(RecordLedgerEntry::class)->execute($this->input(
            entryType: LedgerEntryType::AccommodationRefund,
            currency: Currency::UZS,
            reversesEntryId: $original->id,
        ));
    }

    public function test_reversal_of_reversal_is_blocked(): void
    {
        $original = app(RecordLedgerEntry::class)->execute($this->input());
        $firstReversal = app(RecordLedgerEntry::class)->execute($this->input(
            entryType: LedgerEntryType::AccommodationRefund,
            reversesEntryId: $original->id,
        ));

        $this->expectException(InvalidLedgerEntryException::class);
        app(RecordLedgerEntry::class)->execute($this->input(
            entryType: LedgerEntryType::AccommodationRefund,
            reversesEntryId: $firstReversal->id,
        ));
    }

    public function test_shift_open_check_rejects_unknown_shift(): void
    {
        // Any cashierShiftId that does not exist (or exists but is not open)
        // fails the DB check. Using a non-existent id avoids depending on
        // cash_drawers/cashier_shifts column defaults, which vary by migration
        // history. Production prod-schema equivalence is covered in L-009 tests
        // once the shift lifecycle is wired through this adapter chain.
        $unknownShiftId = 999_999;

        $this->expectException(InvalidLedgerEntryException::class);
        app(RecordLedgerEntry::class)->execute($this->input(cashierShiftId: $unknownShiftId));
    }

    public function test_transaction_rolls_back_if_listener_throws(): void
    {
        Event::listen(LedgerEntryRecorded::class, function (): void {
            throw new \RuntimeException('listener exploded');
        });

        try {
            app(RecordLedgerEntry::class)->execute($this->input());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            $this->assertSame(0, LedgerEntry::count());
        }
    }

    // ---------------------------------------------------------------------

    private function input(
        ?LedgerEntryType    $entryType = null,
        ?SourceTrigger      $source = null,
        ?float              $amount = null,
        ?Currency           $currency = null,
        ?CounterpartyType   $counterpartyType = null,
        ?PaymentMethod      $paymentMethod = null,
        ?string             $idempotencyKey = null,
        ?int                $cashierShiftId = null,
        ?int                $reversesEntryId = null,
        ?string             $externalReference = 'b24_default_ref',
        ?string             $createdByBotSlug = 'cashier',
    ): LedgerEntryInput {
        return new LedgerEntryInput(
            entryType:         $entryType         ?? LedgerEntryType::AccommodationPaymentIn,
            source:            $source            ?? SourceTrigger::CashierBot,
            amount:            $amount            ?? 100.0,
            currency:          $currency          ?? Currency::USD,
            counterpartyType:  $counterpartyType  ?? CounterpartyType::Guest,
            paymentMethod:     $paymentMethod     ?? PaymentMethod::Cash,
            idempotencyKey:    $idempotencyKey,
            cashierShiftId:    $cashierShiftId,
            reversesEntryId:   $reversesEntryId,
            externalReference: $externalReference,
            createdByBotSlug:  $createdByBotSlug,
        );
    }

}
