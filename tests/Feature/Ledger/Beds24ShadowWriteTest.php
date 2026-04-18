<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\LedgerEntry;
use App\Models\CashTransaction;
use App\Models\Beds24Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * L-006 shadow-write integration: when
 *   features.ledger.shadow.beds24 = true
 * the Beds24WebhookController's external-bookkeeping path writes
 * BOTH to legacy cash_transactions AND to ledger_entries.
 *
 * Hard invariant: legacy behaviour is byte-for-byte unchanged when
 * the flag is off, AND unchanged even when the flag is on (the shadow
 * write is observer-only — any ledger failure must NOT propagate).
 *
 * The tests invoke the private controller method directly via
 * reflection so they don't need to stand up the full webhook HTTP
 * plumbing. The production code path we care about is the cash_tx
 * insert + shadow block — identical whether the caller is a real
 * webhook or the test.
 */
class Beds24ShadowWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_writes_only_legacy(): void
    {
        config(['features.ledger.shadow.beds24' => false]);

        $booking = $this->makeBooking();
        $this->invokeCreateExternalBookkeepingRow($booking, 100.00, 'cash', 'Test payment', 'Beds24 #B1 item#i1', 'i1');

        $this->assertSame(1, CashTransaction::count());
        $this->assertSame(0, LedgerEntry::count(), 'No ledger row must exist when shadow flag is off');
    }

    public function test_flag_on_dual_writes_both_legacy_and_ledger(): void
    {
        config(['features.ledger.shadow.beds24' => true]);

        $booking = $this->makeBooking();
        $this->invokeCreateExternalBookkeepingRow($booking, 150.00, 'card', 'Card payment', "Beds24 #{$booking->beds24_booking_id} item#i2", 'i2');

        $this->assertSame(1, CashTransaction::count(), 'Legacy cash_transaction must still be written');
        $this->assertSame(1, LedgerEntry::count(), 'Shadow ledger row must be written');

        $ledger = LedgerEntry::first();
        $this->assertSame($booking->beds24_booking_id, $ledger->beds24_booking_id);
        $this->assertSame('150.00',                    (string) $ledger->amount);
        $this->assertSame('USD',                       $ledger->currency);
        $this->assertSame('b24_item_i2',               $ledger->idempotency_key);
        $this->assertSame('b24_item_i2',               $ledger->external_item_ref);
        $this->assertSame('beds24_webhook',            $ledger->source?->value);
    }

    public function test_duplicate_webhook_retry_is_idempotent_on_ledger_side(): void
    {
        config(['features.ledger.shadow.beds24' => true]);

        $booking = $this->makeBooking();

        $this->invokeCreateExternalBookkeepingRow($booking, 75.00, 'cash', 'Retry test', 'Beds24 #B3 item#i3', 'i3');

        // A second call would have been deduplicated by legacy at dedup 1
        // (stable item id); the ledger has its own UNIQUE constraint to
        // match. We simulate a case where legacy dedup missed it (e.g.
        // it was the first call) and only ledger holds the line.
        try {
            // Force a second call by bypassing legacy dedup — we re-invoke
            // the bookkeeping method with the same item id on a fresh
            // booking context. Legacy will skip its insert (duplicate),
            // but the shadow block should also no-op gracefully.
            $this->invokeCreateExternalBookkeepingRow($booking, 75.00, 'cash', 'Retry test', 'Beds24 #B3 item#i3', 'i3');
        } catch (\Throwable $e) {
            $this->fail('Shadow write must never propagate exceptions. Got: ' . $e->getMessage());
        }

        // Exactly one ledger row; second insert was blocked by
        // UNIQUE(source, idempotency_key).
        $this->assertSame(1, LedgerEntry::count());
        // Legacy also deduped by item id.
        $this->assertSame(1, CashTransaction::count());
    }

    public function test_shadow_failure_does_not_break_legacy(): void
    {
        config(['features.ledger.shadow.beds24' => true]);

        // Register a listener that throws so the ledger write fails
        // mid-transaction. The shadow try/catch MUST swallow it.
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\Ledger\LedgerEntryRecorded::class,
            function (): void { throw new \RuntimeException('projection downstream sabotage'); }
        );

        $booking = $this->makeBooking();

        try {
            $this->invokeCreateExternalBookkeepingRow($booking, 30.00, 'cash', 'With saboteur', 'Beds24 #B4 item#i4', 'i4');
        } catch (\Throwable $e) {
            $this->fail('Legacy path must not be affected by ledger failure. Got: ' . $e->getMessage());
        }

        // Legacy row exists despite ledger failure
        $this->assertSame(1, CashTransaction::count());
        // No ledger row — the shadow write failed, was caught, logged, swallowed
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_null_item_id_with_reference_uses_reference_as_idempotency_key(): void
    {
        config(['features.ledger.shadow.beds24' => true]);

        $booking = $this->makeBooking();
        // No item id, but a reference is present — idempotency falls back to ref
        $this->invokeCreateExternalBookkeepingRow($booking, 40.00, 'cash', 'No item id', 'Beds24 #B5 manual', null);

        $ledger = LedgerEntry::first();
        $this->assertNotNull($ledger, 'Ledger row must still be written');
        $this->assertSame('b24_ref_Beds24 #B5 manual', $ledger->idempotency_key);
    }

    // ---------------------------------------------------------------------

    private function makeBooking(): Beds24Booking
    {
        return Beds24Booking::create([
            'beds24_booking_id' => (string) random_int(100000, 999999),
            'property_id'       => 1,
            'room_id'           => 1,
            'room_name'         => 'Test Room',
            'arrival_date'      => now()->addDay(),
            'departure_date'    => now()->addDays(2),
            'guest_name'        => 'Test Guest',
            'total_amount'      => 200.00,
            'invoice_balance'   => 0,
            'currency'          => 'USD',
            'booking_status'    => 'confirmed',
        ]);
    }

    /**
     * Invoke the controller's private createExternalBookkeepingRow via
     * reflection. This is the exact method path a real webhook takes
     * for external payments, so the test exercises the production code
     * verbatim — only the triggering mechanism is swapped.
     */
    private function invokeCreateExternalBookkeepingRow(
        Beds24Booking $booking,
        float         $amount,
        string        $paymentMethod,
        string        $description,
        ?string       $reference,
        ?string       $beds24ItemId,
    ): void {
        $controller = app(\App\Http\Controllers\Beds24WebhookController::class);
        $reflection = new \ReflectionClass($controller);
        $refMethod  = $reflection->getMethod('createExternalBookkeepingRow');
        $refMethod->setAccessible(true);
        $refMethod->invoke($controller, $booking, $amount, $paymentMethod, $description, $reference, $beds24ItemId);
    }
}
