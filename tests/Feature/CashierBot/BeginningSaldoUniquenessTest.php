<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Models\BeginningSaldo;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the (cashier_shift_id, currency) uniqueness invariant on
 * beginning_saldos.
 *
 * The DB-level UNIQUE KEY has existed since migration 2025_09_22_064137
 * (line 22). Balance calculation (CashierBotController::getBal) assumes
 * at most one saldo row per currency per shift — if duplicates ever
 * appear, per-currency balance becomes nondeterministic.
 *
 * These tests guard the invariant at the Laravel/PHPUnit layer so that
 * any future edit to the migration that drops the unique key (or any new
 * write path that tries to insert a duplicate) is caught in CI before
 * reaching prod.
 */
final class BeginningSaldoUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_shift_and_currency_is_rejected_by_the_database(): void
    {
        $shift = $this->openShift();

        BeginningSaldo::create([
            'cashier_shift_id' => $shift->id,
            'currency'         => 'USD',
            'amount'           => 100,
        ]);

        $this->expectException(QueryException::class);

        BeginningSaldo::create([
            'cashier_shift_id' => $shift->id,
            'currency'         => 'USD',
            'amount'           => 999,
        ]);
    }

    public function test_different_currencies_on_the_same_shift_are_allowed(): void
    {
        $shift = $this->openShift();

        foreach (['UZS', 'USD', 'EUR'] as $cur) {
            BeginningSaldo::create([
                'cashier_shift_id' => $shift->id,
                'currency'         => $cur,
                'amount'           => 1,
            ]);
        }

        $this->assertSame(3, BeginningSaldo::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_same_currency_across_different_shifts_is_allowed(): void
    {
        $shiftA = $this->openShift();
        $shiftB = $this->openShift();

        BeginningSaldo::create(['cashier_shift_id' => $shiftA->id, 'currency' => 'USD', 'amount' => 1]);
        BeginningSaldo::create(['cashier_shift_id' => $shiftB->id, 'currency' => 'USD', 'amount' => 2]);

        $this->assertSame(2, BeginningSaldo::whereIn('cashier_shift_id', [$shiftA->id, $shiftB->id])->count());
    }

    private int $drawerSeq = 0;

    private function openShift(): CashierShift
    {
        // cash_drawers.name is UNIQUE, so give each drawer a distinct name.
        $this->drawerSeq++;
        $drawer = CashDrawer::create(['name' => "Test drawer #{$this->drawerSeq}", 'is_active' => true]);
        $user = User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
    }
}
