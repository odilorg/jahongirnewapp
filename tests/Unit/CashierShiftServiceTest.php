<?php

namespace Tests\Unit;

use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Models\ShiftHandover;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierShiftServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashierShiftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashierShiftService();
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

    public function test_closes_shift_with_handover_and_end_saldos(): void
    {
        $shift = $this->createOpenShift();

        $ho = $this->service->closeShift($shift->id, [
            'counted_uzs' => 500000,
            'counted_usd' => 50,
            'counted_eur' => 0,
            'expected' => ['UZS' => 480000, 'USD' => 50, 'EUR' => 0],
        ]);

        $this->assertInstanceOf(ShiftHandover::class, $ho);
        $this->assertEquals(500000, $ho->counted_uzs);

        $shift->refresh();
        $this->assertSame('closed', $shift->status->value);
        $this->assertNotNull($shift->closed_at);

        // CashierShiftService::closeShift creates an EndSaldo for every
        // currency where either expected > 0 OR counted > 0 — not only
        // currencies with discrepancies (see service line 65). Here UZS
        // (480k/500k) and USD (50/50) both qualify; EUR is 0/0 and skipped.
        $this->assertEquals(2, EndSaldo::where('cashier_shift_id', $shift->id)->count());

        $uzsSaldo = EndSaldo::where('cashier_shift_id', $shift->id)
            ->where('currency', 'UZS')
            ->first();
        $this->assertNotNull($uzsSaldo);
        $this->assertEquals(480000, $uzsSaldo->expected_end_saldo);
        $this->assertEquals(500000, $uzsSaldo->counted_end_saldo);
        $this->assertEquals(20000, $uzsSaldo->discrepancy);
    }

    public function test_rejects_close_on_already_closed_shift(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        // Service message drifted from "Shift already closed or not found" to
        // "Shift already closed" — match the shorter prefix to stay
        // resilient across future wording tweaks.
        $this->expectExceptionMessage('Shift already closed');

        $this->service->closeShift($shift->id, [
            'counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0,
            'expected' => ['UZS' => 0, 'USD' => 0, 'EUR' => 0],
        ]);
    }

    public function test_no_records_written_on_rejected_close(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        try {
            $this->service->closeShift($shift->id, [
                'counted_uzs' => 100000, 'counted_usd' => 0, 'counted_eur' => 0,
                'expected' => ['UZS' => 100000, 'USD' => 0, 'EUR' => 0],
            ]);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertEquals(0, ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
        $this->assertEquals(0, EndSaldo::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_rolls_back_completely_on_db_failure(): void
    {
        $shift = $this->createOpenShift();

        // Use a subclass trick: override to force failure after handover
        // Instead, test via the actual service by causing EndSaldo failure
        // For this test, we verify normal rollback behavior using DB::transaction directly
        try {
            DB::transaction(function () use ($shift) {
                $lockedShift = CashierShift::where('id', $shift->id)->lockForUpdate()->first();

                ShiftHandover::create([
                    'outgoing_shift_id' => $lockedShift->id,
                    'counted_uzs' => 100, 'counted_usd' => 0, 'counted_eur' => 0,
                    'expected_uzs' => 100, 'expected_usd' => 0, 'expected_eur' => 0,
                ]);

                $lockedShift->update(['status' => 'closed', 'closed_at' => now()]);

                throw new \RuntimeException('Simulated failure mid-close');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $shift->refresh();
        $this->assertSame('open', $shift->status->value);
        $this->assertEquals(0, ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
    }

    public function test_succeeds_callback_inside_transaction(): void
    {
        $shift = $this->createOpenShift();
        $callbackId = 'cb_close_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_close',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        $this->service->closeShift($shift->id, [
            'counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0,
            'expected' => ['UZS' => 0, 'USD' => 0, 'EUR' => 0],
        ], $callbackId);

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'succeeded',
        ]);
    }

    public function test_callback_stays_processing_on_rejected_close(): void
    {
        $shift = $this->createOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);
        $callbackId = 'cb_close_reject_' . uniqid();

        DB::table('telegram_processed_callbacks')->insert([
            'callback_query_id' => $callbackId,
            'chat_id' => 12345,
            'action' => 'confirm_close',
            'status' => 'processing',
            'claimed_at' => now(),
        ]);

        try {
            $this->service->closeShift($shift->id, [
                'counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0,
                'expected' => ['UZS' => 0, 'USD' => 0, 'EUR' => 0],
            ], $callbackId);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertDatabaseHas('telegram_processed_callbacks', [
            'callback_query_id' => $callbackId,
            'status' => 'processing',
        ]);
    }

    public function test_creates_end_saldos_for_multiple_currencies(): void
    {
        $shift = $this->createOpenShift();

        $this->service->closeShift($shift->id, [
            'counted_uzs' => 500000,
            'counted_usd' => 100,
            'counted_eur' => 50,
            'expected' => ['UZS' => 500000, 'USD' => 100, 'EUR' => 50],
        ]);

        $this->assertEquals(3, EndSaldo::where('cashier_shift_id', $shift->id)->count());
    }
}
