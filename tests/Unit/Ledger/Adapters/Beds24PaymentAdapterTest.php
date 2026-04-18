<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger\Adapters;

use App\Actions\Ledger\Adapters\Beds24PaymentAdapter;
use App\Enums\Currency;
use App\Enums\LedgerEntryDirection;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SourceTrigger;
use App\Enums\TrustLevel;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for Beds24PaymentAdapter → RecordLedgerEntry → ledger row.
 * Proves the end-to-end adapter path without touching Beds24WebhookController
 * (which is wired in L-007).
 */
class Beds24PaymentAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_entry_from_webhook_payload(): void
    {
        $adapter = app(Beds24PaymentAdapter::class);

        $entry = $adapter->record(
            beds24BookingId:      '12345',
            beds24ItemId:         '67890',
            amount:               120.00,
            currency:             Currency::USD,
            beds24PaymentMethod:  'card',
            guestName:            'John Doe',
            roomNumber:           '101',
        );

        $this->assertInstanceOf(LedgerEntry::class, $entry);
        $this->assertSame(LedgerEntryType::AccommodationPaymentIn, $entry->entry_type);
        $this->assertSame(SourceTrigger::Beds24Webhook,             $entry->source);
        $this->assertSame(TrustLevel::Authoritative,                $entry->trust_level);
        $this->assertSame(LedgerEntryDirection::In,                 $entry->direction);
        $this->assertSame(PaymentMethod::Card,                      $entry->payment_method);
        $this->assertSame('12345',                                  $entry->beds24_booking_id);
        $this->assertSame('12345',                                  $entry->external_reference);
        $this->assertSame('b24_item_67890',                         $entry->external_item_ref);
        $this->assertSame('b24_item_67890',                         $entry->idempotency_key);
    }

    public function test_duplicate_webhook_retry_is_idempotent(): void
    {
        $adapter = app(Beds24PaymentAdapter::class);

        $a = $adapter->record(
            beds24BookingId: '55555', beds24ItemId: '99999',
            amount: 50.0, currency: Currency::USD, beds24PaymentMethod: 'cash',
        );
        $b = $adapter->record(
            beds24BookingId: '55555', beds24ItemId: '99999',
            amount: 50.0, currency: Currency::USD, beds24PaymentMethod: 'cash',
        );

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_multilingual_cash_methods_normalize(): void
    {
        $adapter = app(Beds24PaymentAdapter::class);

        $a = $adapter->record(
            beds24BookingId: 'A', beds24ItemId: '1',
            amount: 10.0, currency: Currency::USD, beds24PaymentMethod: 'cash',
        );
        $b = $adapter->record(
            beds24BookingId: 'B', beds24ItemId: '2',
            amount: 10.0, currency: Currency::USD, beds24PaymentMethod: 'naqd',
        );
        $c = $adapter->record(
            beds24BookingId: 'C', beds24ItemId: '3',
            amount: 10.0, currency: Currency::USD, beds24PaymentMethod: 'наличные',
        );

        $this->assertSame(PaymentMethod::Cash, $a->payment_method);
        $this->assertSame(PaymentMethod::Cash, $b->payment_method);
        $this->assertSame(PaymentMethod::Cash, $c->payment_method);
    }

    public function test_unknown_method_falls_back_to_beds24_external(): void
    {
        $adapter = app(Beds24PaymentAdapter::class);

        $entry = $adapter->record(
            beds24BookingId: 'X', beds24ItemId: 'Y',
            amount: 10.0, currency: Currency::USD, beds24PaymentMethod: 'crypto',
        );

        $this->assertSame(PaymentMethod::Beds24External, $entry->payment_method);
    }
}
