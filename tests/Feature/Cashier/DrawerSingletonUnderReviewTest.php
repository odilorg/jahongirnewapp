<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * C1.2 — drawer-singleton invariant under UNDER_REVIEW.
 *
 * userHasOpenShift / getUserOpenShift must treat UNDER_REVIEW as still-locked.
 * This blocks the cashier from starting a new shift or new payments while
 * an owner approval is pending.
 */
final class DrawerSingletonUnderReviewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_has_open_shift_returns_true_for_open_status(): void
    {
        $user = User::factory()->create();
        CashierShift::factory()->create([
            'user_id' => $user->id,
            'status'  => ShiftStatus::OPEN,
        ]);

        $this->assertTrue(CashierShift::userHasOpenShift($user->id));
    }

    public function test_user_has_open_shift_returns_true_for_under_review_status(): void
    {
        $user = User::factory()->create();
        CashierShift::factory()->create([
            'user_id' => $user->id,
            'status'  => ShiftStatus::UNDER_REVIEW,
        ]);

        $this->assertTrue(
            CashierShift::userHasOpenShift($user->id),
            'UNDER_REVIEW must lock the drawer just like OPEN'
        );
    }

    public function test_user_has_open_shift_returns_false_for_closed_status(): void
    {
        $user = User::factory()->create();
        CashierShift::factory()->create([
            'user_id'   => $user->id,
            'status'    => ShiftStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->assertFalse(CashierShift::userHasOpenShift($user->id));
    }

    public function test_get_user_open_shift_returns_open_shift(): void
    {
        $user  = User::factory()->create();
        $shift = CashierShift::factory()->create([
            'user_id' => $user->id,
            'status'  => ShiftStatus::OPEN,
        ]);

        $this->assertSame($shift->id, CashierShift::getUserOpenShift($user->id)?->id);
    }

    public function test_get_user_open_shift_returns_under_review_shift(): void
    {
        $user  = User::factory()->create();
        $shift = CashierShift::factory()->create([
            'user_id' => $user->id,
            'status'  => ShiftStatus::UNDER_REVIEW,
        ]);

        $this->assertSame(
            $shift->id,
            CashierShift::getUserOpenShift($user->id)?->id,
            'getUserOpenShift must return UNDER_REVIEW shifts so caller respects the lock'
        );
    }

    public function test_get_user_open_shift_returns_null_when_only_closed_exists(): void
    {
        $user = User::factory()->create();
        CashierShift::factory()->create([
            'user_id'   => $user->id,
            'status'    => ShiftStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->assertNull(CashierShift::getUserOpenShift($user->id));
    }
}
