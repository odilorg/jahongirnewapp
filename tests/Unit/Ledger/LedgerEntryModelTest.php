<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Enums\CounterpartyType;
use App\Enums\LedgerDataQuality;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Enums\TrustLevel;
use App\Exceptions\Ledger\LedgerImmutableException;
use App\Models\LedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L-003 model invariants.
 *
 *  - Enum casts round-trip correctly
 *  - Updating a ledger row throws LedgerImmutableException
 *  - Deleting a ledger row throws LedgerImmutableException
 *  - UNIQUE(source, idempotency_key) prevents duplicate keyed rows
 *  - Nullable idempotency_key allows multiple NULL rows
 *  - SourceTrigger → TrustLevel mapping is correct
 *  - LedgerEntryDirection::sign() returns +1 / -1 as expected
 */
class LedgerEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_and_casts_enums(): void
    {
        $entry = $this->makeEntry([
            'entry_type'        => LedgerEntryType::AccommodationPaymentIn->value,
            'source'            => SourceTrigger::CashierBot->value,
            'trust_level'       => TrustLevel::Operator->value,
            'direction'         => LedgerEntryDirection::In->value,
            'counterparty_type' => CounterpartyType::Guest->value,
            'payment_method'    => PaymentMethod::Cash->value,
            'data_quality'      => LedgerDataQuality::Ok->value,
        ]);

        $entry->refresh();

        $this->assertInstanceOf(LedgerEntryType::class, $entry->entry_type);
        $this->assertSame(LedgerEntryType::AccommodationPaymentIn, $entry->entry_type);
        $this->assertInstanceOf(SourceTrigger::class, $entry->source);
        $this->assertSame(SourceTrigger::CashierBot, $entry->source);
        $this->assertInstanceOf(TrustLevel::class, $entry->trust_level);
        $this->assertInstanceOf(LedgerEntryDirection::class, $entry->direction);
        $this->assertInstanceOf(CounterpartyType::class, $entry->counterparty_type);
        $this->assertInstanceOf(PaymentMethod::class, $entry->payment_method);
        $this->assertInstanceOf(LedgerDataQuality::class, $entry->data_quality);
    }

    public function test_update_is_blocked(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(LedgerImmutableException::class);
        $entry->update(['notes' => 'altered']);
    }

    public function test_delete_is_blocked(): void
    {
        $entry = $this->makeEntry();

        $this->expectException(LedgerImmutableException::class);
        $entry->delete();
    }

    public function test_save_on_dirty_attribute_is_blocked(): void
    {
        $entry = $this->makeEntry();
        $entry->notes = 'tampering';

        $this->expectException(LedgerImmutableException::class);
        $entry->save();
    }

    public function test_unique_source_plus_idempotency_key_prevents_duplicate(): void
    {
        $key = 'octo_txn_abc';
        $this->makeEntry([
            'source'          => SourceTrigger::OctoCallback->value,
            'idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);
        $this->makeEntry([
            'source'          => SourceTrigger::OctoCallback->value,
            'idempotency_key' => $key,
        ]);
    }

    public function test_null_idempotency_key_allows_multiple_rows(): void
    {
        $a = $this->makeEntry(['idempotency_key' => null]);
        $b = $this->makeEntry(['idempotency_key' => null]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_same_key_different_source_is_allowed(): void
    {
        $this->makeEntry([
            'source'          => SourceTrigger::OctoCallback->value,
            'idempotency_key' => 'shared_key',
        ]);
        $this->makeEntry([
            'source'          => SourceTrigger::GygImport->value,
            'idempotency_key' => 'shared_key',
        ]);

        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_source_trigger_trust_level_mapping(): void
    {
        $this->assertSame(TrustLevel::Authoritative, SourceTrigger::Beds24Webhook->trustLevel());
        $this->assertSame(TrustLevel::Authoritative, SourceTrigger::OctoCallback->trustLevel());
        $this->assertSame(TrustLevel::Authoritative, SourceTrigger::GygImport->trustLevel());
        $this->assertSame(TrustLevel::Operator,      SourceTrigger::CashierBot->trustLevel());
        $this->assertSame(TrustLevel::Operator,      SourceTrigger::PosBot->trustLevel());
        $this->assertSame(TrustLevel::Manual,        SourceTrigger::FilamentAdmin->trustLevel());
        $this->assertSame(TrustLevel::Manual,        SourceTrigger::SystemBackfill->trustLevel());
    }

    public function test_direction_sign_semantics(): void
    {
        $this->assertSame(1,  LedgerEntryDirection::In->sign());
        $this->assertSame(-1, LedgerEntryDirection::Out->sign());

        $inEntry = $this->makeEntry(['direction' => LedgerEntryDirection::In->value, 'amount' => 100.0]);
        $outEntry = $this->makeEntry(['direction' => LedgerEntryDirection::Out->value, 'amount' => 100.0]);

        $this->assertSame(100.0,  $inEntry->signedAmount());
        $this->assertSame(-100.0, $outEntry->signedAmount());
    }

    public function test_reversal_is_a_new_row_not_an_update(): void
    {
        $original = $this->makeEntry([
            'entry_type' => LedgerEntryType::OperationalExpense->value,
            'direction'  => LedgerEntryDirection::Out->value,
            'amount'     => 50.0,
        ]);

        $reversal = $this->makeEntry([
            'entry_type'        => LedgerEntryType::OperationalExpense->value,
            'direction'         => LedgerEntryDirection::In->value,
            'amount'            => 50.0,
            'reverses_entry_id' => $original->id,
        ]);

        $this->assertSame($original->id, $reversal->reversesEntry->id);
        $this->assertSame($reversal->id, $original->reversals->first()->id);
        $this->assertSame(0.0, $original->signedAmount() + $reversal->signedAmount());
    }

    /**
     * Build a valid ledger entry row with per-test overrides.
     * Writing directly here is allowed at L-003 — the runtime
     * firewall (L-018) will tighten this to "actions only" later.
     */
    private function makeEntry(array $overrides = []): LedgerEntry
    {
        return LedgerEntry::create(array_merge([
            'ulid'              => (string) Str::ulid(),
            'occurred_at'       => now(),
            'recorded_at'       => now(),
            'entry_type'        => LedgerEntryType::AccommodationPaymentIn->value,
            'source'            => SourceTrigger::CashierBot->value,
            'trust_level'       => TrustLevel::Operator->value,
            'direction'         => LedgerEntryDirection::In->value,
            'amount'            => 100.0,
            'currency'          => 'USD',
            'counterparty_type' => CounterpartyType::Guest->value,
            'payment_method'    => PaymentMethod::Cash->value,
            'data_quality'      => LedgerDataQuality::Ok->value,
            'created_at'        => now(),
        ], $overrides));
    }
}
