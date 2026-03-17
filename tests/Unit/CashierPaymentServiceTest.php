<?php

namespace Tests\Unit;

use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Services\CashierPaymentService;
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
        $this->service = new CashierPaymentService();
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
        $this->assertEquals('UZS', $tx->currency);
        $this->assertEquals('sale', $tx->category);
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
}
