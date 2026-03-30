<?php

namespace Tests\Unit;

use App\Models\Beds24PaymentSync;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\CashierPaymentService;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashierPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashierPaymentService(new Beds24PaymentSyncService());
    }

    private function createOpenShift(): CashierShift
    {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user = \App\Models\User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function test_records_payment_on_open_shift(): void
    {
        $shift = $this->createOpenShift();

        $tx = $this->service->recordPayment($shift->id, [
            'amount' => 500000,
            'currency' => 'UZS',
            'method' => 'cash',
            'guest_name' => 'John Doe',
            'room' => '101',
            'booking_id' => null,
        ], $shift->user_id);

        $this->assertInstanceOf(CashTransaction::class, $tx);
        $this->assertEquals(500000, $tx->amount);
        $this->assertEquals('UZS', $tx->currency instanceof \BackedEnum ? $tx->currency->value : $tx->currency);
        $this->assertEquals('sale', $tx->category instanceof \BackedEnum ? $tx->category->value : $tx->category);
        $this->assertEquals('Комната 101', $tx->reference);
    }

    public function test_records_payment_with_beds24_booking_id(): void
    {
        $shift = $this->createOpenShift();

        $tx = $this->service->recordPayment($shift->id, [
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'card',
            'guest_name' => 'Jane',
            'room' => '202',
            'booking_id' => '12345',
        ], $shift->user_id);

        $this->assertEquals('Beds24 #12345', $tx->reference);
    }

    public function test_rejects_payment_on_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift closed during confirmation');

        $this->service->recordPayment($shift->id, [
            'amount' => 100, 'currency' => 'UZS', 'method' => 'cash',
            'guest_name' => 'Test', 'room' => '1',
        ], $shift->user_id);
    }

    public function test_no_transaction_written_when_shift_closed(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        try {
            $this->service->recordPayment($shift->id, [
                'amount' => 100, 'currency' => 'UZS', 'method' => 'cash',
                'guest_name' => 'Test', 'room' => '1',
            ], $shift->user_id);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertEquals(0, CashTransaction::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_succeeds_callback_inside_transaction(): void
    {
        $shift = $this->createOpenShift();
        $callbackId = 'cb_pay_' . uniqid();

        // Pre-claim the callback
        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_payment',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        $this->service->recordPayment($shift->id, [
            'amount' => 100, 'currency' => 'USD', 'method' => 'cash',
            'guest_name' => 'Test', 'room' => '1',
        ], $shift->user_id, $callbackId);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'succeeded',
        ]);
    }

    public function test_callback_stays_processing_on_rejected_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        $callbackId = 'cb_reject_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_payment',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        try {
            $this->service->recordPayment($shift->id, [
                'amount' => 100, 'currency' => 'UZS', 'method' => 'cash',
                'guest_name' => 'Test', 'room' => '1',
            ], $shift->user_id, $callbackId);
        } catch (\RuntimeException $e) {
            // expected
        }

        // succeedCallback was inside the transaction — rolled back
        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'processing',
        ]);
    }

    // ── BUG-05 regression: legacy path must create Beds24PaymentSync ─────────

    /** @test */
    public function payment_with_beds24_booking_id_creates_sync_row(): void
    {
        $shift = $this->createOpenShift();

        $tx = $this->service->recordPayment($shift->id, [
            'amount'           => 1250000,
            'currency'         => 'UZS',
            'method'           => 'cash',
            'guest_name'       => 'Sync Test Guest',
            'room'             => null,
            'booking_id'       => '77001122',
            'booking_currency' => 'USD',
            'booking_amount'   => 100.0,
        ], $shift->user_id);

        $this->assertNotNull($tx->beds24_payment_sync_id,
            'beds24_payment_sync_id must be set after payment with a booking ID');

        $this->assertDatabaseHas('beds24_payment_syncs', [
            'cash_transaction_id' => $tx->id,
            'beds24_booking_id'   => '77001122',
            'amount_usd'          => 100.0,
            'status'              => 'pending',
        ]);
    }

    /** @test */
    public function payment_without_beds24_booking_id_does_not_create_sync_row(): void
    {
        $shift = $this->createOpenShift();

        $tx = $this->service->recordPayment($shift->id, [
            'amount'     => 50000,
            'currency'   => 'UZS',
            'method'     => 'cash',
            'guest_name' => 'Walk-in',
            'room'       => '5',
            'booking_id' => null,
        ], $shift->user_id);

        $this->assertNull($tx->beds24_payment_sync_id,
            'Walk-in payment (no booking) must not create a sync row');

        $this->assertEquals(0, Beds24PaymentSync::where('cash_transaction_id', $tx->id)->count());
    }

    /** @test */
    public function sync_row_has_zero_usd_when_booking_currency_is_not_usd(): void
    {
        $shift = $this->createOpenShift();

        $tx = $this->service->recordPayment($shift->id, [
            'amount'           => 850000,
            'currency'         => 'UZS',
            'method'           => 'cash',
            'guest_name'       => 'EUR Guest',
            'room'             => null,
            'booking_id'       => '88002233',
            'booking_currency' => 'EUR',
            'booking_amount'   => 70.0,
        ], $shift->user_id);

        $syncRow = Beds24PaymentSync::where('cash_transaction_id', $tx->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $syncRow->amount_usd,
            'USD equivalent must be 0 when the booking is in a non-USD currency (TODO to improve)');
    }
}
