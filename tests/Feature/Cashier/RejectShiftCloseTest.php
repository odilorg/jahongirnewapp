<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\CashierBot\Handlers\RejectShiftCloseAction;
use App\Enums\OverrideTier;
use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C1.2 — RejectShiftCloseAction.
 *
 * Returns shift to OPEN, stamps rejecter metadata. Does NOT touch cashier
 * session FSM (that is C1.3's job).
 */
final class RejectShiftCloseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin');
        Role::findOrCreate('admin');
        Role::findOrCreate('manager');
    }

    public function test_super_admin_can_reject(): void
    {
        $shift   = $this->underReviewShift();
        $rejecter = $this->userWithRole('super_admin');

        $result = $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $rejecter->id, 'Counts look implausibly low; recount.');

        $this->assertSame(ShiftStatus::OPEN, $result->status);
        $this->assertSame($rejecter->id, (int) $result->rejected_by);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame('Counts look implausibly low; recount.', $result->rejection_reason);
    }

    public function test_admin_can_reject(): void
    {
        $shift   = $this->underReviewShift();
        $rejecter = $this->userWithRole('admin');

        $result = $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $rejecter->id, 'recount please');

        $this->assertSame(ShiftStatus::OPEN, $result->status);
    }

    public function test_regular_user_is_denied(): void
    {
        $shift   = $this->underReviewShift();
        $regular = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $regular->id, 'reason');
    }

    public function test_manager_role_is_denied(): void
    {
        $shift   = $this->underReviewShift();
        $manager = $this->userWithRole('manager');

        $this->expectException(AuthorizationException::class);
        $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $manager->id, 'reason');
    }

    public function test_it_does_not_touch_cashier_session_state(): void
    {
        $shift   = $this->underReviewShift();
        $rejecter = $this->userWithRole('super_admin');

        // Cashier session pretending to be in shift_close_pending_approval
        $session = TelegramPosSession::create([
            'chat_id' => 555_111,
            'user_id' => $shift->user_id,
            'state'   => 'shift_close_pending_approval',
            'data'    => ['shift_id' => $shift->id],
        ]);

        $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $rejecter->id, 'recount');

        // Action must NOT mutate the cashier session — that's C1.3's job.
        $this->assertSame('shift_close_pending_approval', $session->fresh()->state);
        $this->assertSame(['shift_id' => $shift->id], $session->fresh()->data);
    }

    public function test_it_is_idempotent_when_already_rejected_by_same_user(): void
    {
        $rejecter = $this->userWithRole('super_admin');
        $shift = CashierShift::factory()->create([
            'status'           => ShiftStatus::OPEN,
            'rejected_by'      => $rejecter->id,
            'rejected_at'      => now()->subMinutes(5),
            'rejection_reason' => 'first rejection',
        ]);

        $result = $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $rejecter->id, 'second call reason');

        // Idempotent: original reason preserved, no second mutation.
        $this->assertSame('first rejection', $result->fresh()->rejection_reason);
    }

    public function test_it_throws_when_shift_not_under_review(): void
    {
        $shift   = CashierShift::factory()->create(['status' => ShiftStatus::CLOSED, 'closed_at' => now()]);
        $rejecter = $this->userWithRole('super_admin');

        $this->expectException(\RuntimeException::class);
        $this->app->make(RejectShiftCloseAction::class)
            ->execute($shift->id, $rejecter->id, 'reason');
    }

    private function underReviewShift(): CashierShift
    {
        return CashierShift::factory()->create([
            'status'                   => ShiftStatus::UNDER_REVIEW,
            'discrepancy_tier'         => OverrideTier::Manager,
            'discrepancy_severity_uzs' => 250_000,
            'opened_at'                => now()->subHours(8),
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }
}
