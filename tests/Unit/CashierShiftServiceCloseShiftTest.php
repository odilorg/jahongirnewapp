<?php

namespace Tests\Unit;

use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the null-check order fix in CashierShiftService::closeShift().
 *
 * Bug: before the fix, accessing $lockedShift->status on line 35 crashed with
 * "Attempt to read property 'status' on null" when the shift ID didn't exist,
 * because the null guard was placed AFTER the property access.
 */
class CashierShiftServiceCloseShiftTest extends TestCase
{
    use RefreshDatabase;

    private CashierShiftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashierShiftService();
    }

    private function makeOpenShift(): CashierShift
    {
        $drawer = CashDrawer::create(['name' => 'Test Drawer', 'is_active' => true]);
        $user   = User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
    }

    // ── Null-check order regression test (the actual bug) ────────────────────

    /** @test */
    public function close_shift_throws_runtime_exception_for_nonexistent_shift_id(): void
    {
        // Before the fix: $lockedShift->status access on null → fatal PHP error (not catchable).
        // After the fix: explicit null check returns a proper RuntimeException.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift not found');

        $this->service->closeShift(
            shiftId:    99999, // non-existent
            countData: ['counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0, 'expected' => []],
        );
    }

    /** @test */
    public function exception_for_missing_shift_is_catchable_as_runtime_exception(): void
    {
        // The pre-fix TypeError/Error from null dereference is NOT a RuntimeException and
        // would have bypassed catch (\RuntimeException) in calling code.
        $caught = false;
        try {
            $this->service->closeShift(99999, ['counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0, 'expected' => []]);
        } catch (\RuntimeException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'Should throw catchable RuntimeException, not uncatchable null dereference error');
    }

    // ── Already-closed shift ──────────────────────────────────────────────────

    /** @test */
    public function close_shift_throws_for_already_closed_shift(): void
    {
        $shift = $this->makeOpenShift();
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shift already closed');

        $this->service->closeShift(
            shiftId:   $shift->id,
            countData: ['counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0, 'expected' => []],
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function close_shift_marks_shift_closed_and_returns_handover(): void
    {
        $shift = $this->makeOpenShift();

        $handover = $this->service->closeShift(
            shiftId:   $shift->id,
            countData: [
                'counted_uzs' => 500_000,
                'counted_usd' => 10,
                'counted_eur' => 5,
                'expected'    => ['UZS' => 500_000, 'USD' => 10, 'EUR' => 5],
            ],
        );

        $shift->refresh();
        $this->assertEquals('closed', is_string($shift->status) ? $shift->status : $shift->status->value);
        $this->assertNotNull($shift->closed_at);
        $this->assertEquals($shift->id, $handover->outgoing_shift_id);
    }

    /** @test */
    public function close_shift_creates_end_saldo_records(): void
    {
        $shift = $this->makeOpenShift();

        $this->service->closeShift(
            shiftId:   $shift->id,
            countData: [
                'counted_uzs' => 1_000_000,
                'counted_usd' => 0,
                'counted_eur' => 50,
                'expected'    => ['UZS' => 1_000_000, 'USD' => 0, 'EUR' => 50],
            ],
        );

        $this->assertDatabaseHas('end_saldos', [
            'cashier_shift_id' => $shift->id,
            'currency'         => Currency::UZS->value,
            'counted_end_saldo' => 1_000_000,
        ]);
        $this->assertDatabaseHas('end_saldos', [
            'cashier_shift_id' => $shift->id,
            'currency'         => Currency::EUR->value,
            'counted_end_saldo' => 50,
        ]);
    }

    /** @test */
    public function close_shift_does_not_persist_partial_state_when_something_fails(): void
    {
        // Closing a non-existent shift should leave the DB unchanged
        $before = \App\Models\ShiftHandover::count();

        try {
            $this->service->closeShift(99999, [
                'counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0, 'expected' => [],
            ]);
        } catch (\RuntimeException) {}

        $this->assertEquals($before, \App\Models\ShiftHandover::count(),
            'No handover row should be created when shift is not found');
    }
}
