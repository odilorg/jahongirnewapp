<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\CashierBot\Handlers\ApproveShiftCloseAction;
use App\Enums\OverrideTier;
use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C1.2 — ApproveShiftCloseAction.
 *
 * Role-gated to ['super_admin', 'admin']. Calls CashierShiftService::closeShift,
 * stamps approver fields. Idempotent on double-click.
 */
final class ApproveShiftCloseTest extends TestCase
{
    use DatabaseTransactions;

    private array $countData;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin');
        Role::findOrCreate('admin');
        Role::findOrCreate('manager');

        $this->countData = [
            'counted_uzs'  => 1_500_000,
            'counted_usd'  => 100,
            'counted_eur'  => 45,
            'expected'     => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
            'severity_uzs' => 0,
        ];
    }

    public function test_super_admin_can_approve_under_review_shift(): void
    {
        $shift = $this->underReviewShift();
        $approver = $this->userWithRole('super_admin');

        $handover = $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $this->countData, 'Reviewed counts, OK');

        $this->assertNotNull($handover);
        $this->assertSame($shift->id, $handover->outgoing_shift_id);

        $fresh = $shift->fresh();
        $this->assertSame(ShiftStatus::CLOSED, $fresh->status);
        $this->assertSame($approver->id, (int) $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame('Reviewed counts, OK', $fresh->approval_notes);
    }

    public function test_admin_can_approve(): void
    {
        $shift    = $this->underReviewShift();
        $approver = $this->userWithRole('admin');

        $handover = $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $this->countData);

        $this->assertNotNull($handover);
        $this->assertSame(ShiftStatus::CLOSED, $shift->fresh()->status);
    }

    public function test_regular_user_is_denied(): void
    {
        $shift = $this->underReviewShift();
        $regular = User::factory()->create(); // no role

        $this->expectException(AuthorizationException::class);
        $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $regular->id, $this->countData);
    }

    public function test_manager_role_is_denied(): void
    {
        // Per locked decision: super_admin/admin only. Manager NOT allowed.
        $shift   = $this->underReviewShift();
        $manager = $this->userWithRole('manager');

        $this->expectException(AuthorizationException::class);
        $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $manager->id, $this->countData);
    }

    public function test_it_passes_tier_and_reason_to_close_service(): void
    {
        $shift = $this->underReviewShift([
            'discrepancy_tier'         => OverrideTier::Manager,
            'discrepancy_severity_uzs' => 250_000,
        ]);

        // Pre-existing EndSaldo with a real cashier reason (set by C1.3 in
        // production; here we seed it to verify resolveReason picks it up).
        EndSaldo::create([
            'cashier_shift_id'    => $shift->id,
            'currency'            => \App\Enums\Currency::UZS,
            'expected_end_saldo'  => 1_500_000,
            'counted_end_saldo'   => 1_250_000,
            'discrepancy'         => -250_000,
            'discrepancy_reason'  => 'Banknote miscounted, reconciled with manager',
        ]);

        $approver = $this->userWithRole('super_admin');
        $countData = [
            'counted_uzs' => 1_250_000,
            'counted_usd' => 100,
            'counted_eur' => 45,
            'expected'    => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
            'severity_uzs' => 250_000,
        ];

        $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $countData);

        $fresh = $shift->fresh();
        $this->assertSame(OverrideTier::Manager, $fresh->discrepancy_tier);
        $this->assertEquals(250_000.0, (float) $fresh->discrepancy_severity_uzs);

        $endSaldo = EndSaldo::where('cashier_shift_id', $shift->id)
            ->where('currency', \App\Enums\Currency::UZS)
            ->first();
        $this->assertSame(
            'Banknote miscounted, reconciled with manager',
            $endSaldo->discrepancy_reason,
            'reason from EndSaldo should flow through closeShift'
        );
    }

    public function test_it_is_idempotent_on_double_click_same_user(): void
    {
        $shift    = $this->underReviewShift();
        $approver = $this->userWithRole('super_admin');

        $first  = $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $this->countData);
        $second = $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $this->countData);

        $this->assertSame($first->id, $second->id, 'second call must return existing handover');
        $this->assertSame(1, \App\Models\ShiftHandover::where('outgoing_shift_id', $shift->id)->count());
    }

    public function test_it_throws_when_shift_not_under_review(): void
    {
        $shift = CashierShift::factory()->create([
            'status' => ShiftStatus::OPEN,
        ]);
        $approver = $this->userWithRole('super_admin');

        $this->expectException(\RuntimeException::class);
        $this->app->make(ApproveShiftCloseAction::class)
            ->execute($shift->id, $approver->id, $this->countData);
    }

    private function underReviewShift(array $overrides = []): CashierShift
    {
        return CashierShift::factory()->create(array_merge([
            'status'                   => ShiftStatus::UNDER_REVIEW,
            'discrepancy_tier'         => OverrideTier::Manager,
            'discrepancy_severity_uzs' => 250_000,
            'opened_at'                => now()->subHours(8),
        ], $overrides));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }
}
