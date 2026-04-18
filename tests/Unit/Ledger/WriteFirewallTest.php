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
use App\Exceptions\Ledger\LedgerWriteForbiddenException;
use App\Models\LedgerEntry;
use App\Support\Ledger\LedgerWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L-018 — runtime write firewall behaviour across the three modes.
 *
 * Default config value is 'off' so existing tests keep passing. These
 * tests explicitly flip the mode to 'warn' / 'enforce' via
 * config()->set() and assert the correct observable behaviour.
 */
class WriteFirewallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure a clean container state between tests.
        app()->forgetInstance(LedgerWriteContext::class);
    }

    // ---------------------------------------------------------------------
    // OFF mode — no interference
    // ---------------------------------------------------------------------

    public function test_mode_off_allows_direct_writes_without_logging(): void
    {
        config(['features.ledger.firewall.mode' => 'off']);
        Log::spy();

        $this->createDirectly();

        $this->assertSame(1, LedgerEntry::count());
        Log::shouldNotHaveReceived('warning');
    }

    // ---------------------------------------------------------------------
    // WARN mode
    // ---------------------------------------------------------------------

    public function test_mode_warn_allows_direct_writes_but_logs_warning(): void
    {
        config(['features.ledger.firewall.mode' => 'warn']);
        Log::spy();

        $this->createDirectly();

        $this->assertSame(1, LedgerEntry::count(), 'Warn mode must not block writes');
        Log::shouldHaveReceived('warning')->with('ledger.write.firewall.unbound_write', \Mockery::on(
            fn ($payload) => ($payload['mode'] ?? null) === 'warn'
        ));
    }

    public function test_mode_warn_with_active_context_does_not_log(): void
    {
        config(['features.ledger.firewall.mode' => 'warn']);
        Log::spy();

        app(RecordLedgerEntry::class)->execute($this->validInput());

        $this->assertSame(1, LedgerEntry::count());
        Log::shouldNotHaveReceived('warning', ['ledger.write.firewall.unbound_write']);
    }

    // ---------------------------------------------------------------------
    // ENFORCE mode
    // ---------------------------------------------------------------------

    public function test_mode_enforce_blocks_direct_writes(): void
    {
        config(['features.ledger.firewall.mode' => 'enforce']);
        Log::spy();

        $threw = false;
        try {
            $this->createDirectly();
        } catch (LedgerWriteForbiddenException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Enforce mode must throw LedgerWriteForbiddenException');
        $this->assertSame(0, LedgerEntry::count(), 'No row may be persisted');
        Log::shouldHaveReceived('warning')->with('ledger.write.firewall.blocked', \Mockery::any());
    }

    public function test_mode_enforce_allows_writes_through_action(): void
    {
        config(['features.ledger.firewall.mode' => 'enforce']);

        $entry = app(RecordLedgerEntry::class)->execute($this->validInput());

        $this->assertNotNull($entry->id);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_mode_enforce_unbinds_context_after_action(): void
    {
        config(['features.ledger.firewall.mode' => 'enforce']);

        app(RecordLedgerEntry::class)->execute($this->validInput());

        $this->assertFalse(
            app()->bound(LedgerWriteContext::class),
            'Context must be released after the action returns'
        );
    }

    public function test_mode_enforce_unbinds_context_on_exception(): void
    {
        config(['features.ledger.firewall.mode' => 'enforce']);

        try {
            app(RecordLedgerEntry::class)->execute(new LedgerEntryInput(
                entryType:         LedgerEntryType::AccommodationPaymentIn,
                source:            SourceTrigger::CashierBot,
                amount:            0.0,   // invalid — will throw
                currency:          Currency::USD,
                counterpartyType:  CounterpartyType::Guest,
                paymentMethod:     PaymentMethod::Cash,
                createdByBotSlug:  'cashier',
                externalReference: 'x',
            ));
        } catch (\Throwable) {
            // expected
        }

        $this->assertFalse(
            app()->bound(LedgerWriteContext::class),
            'Context must be released even when the action throws'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createDirectly(): void
    {
        LedgerEntry::create([
            'ulid'              => (string) Str::ulid(),
            'occurred_at'       => now(),
            'recorded_at'       => now(),
            'entry_type'        => LedgerEntryType::AccommodationPaymentIn->value,
            'source'            => SourceTrigger::CashierBot->value,
            'trust_level'       => 'operator',
            'direction'         => LedgerEntryDirection::In->value,
            'amount'            => 100.0,
            'currency'          => 'USD',
            'counterparty_type' => CounterpartyType::Guest->value,
            'payment_method'    => PaymentMethod::Cash->value,
            'data_quality'      => 'ok',
            'created_at'        => now(),
        ]);
    }

    private function validInput(): LedgerEntryInput
    {
        return new LedgerEntryInput(
            entryType:         LedgerEntryType::AccommodationPaymentIn,
            source:            SourceTrigger::CashierBot,
            amount:            100.0,
            currency:          Currency::USD,
            counterpartyType:  CounterpartyType::Guest,
            paymentMethod:     PaymentMethod::Cash,
            externalReference: 'test_ref_' . uniqid(),
            createdByBotSlug:  'cashier',
        );
    }
}
