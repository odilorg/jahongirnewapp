<?php

namespace Tests\Feature\Fx;

use App\Enums\Currency;
use App\Enums\ManagerApprovalStatus;
use App\Exceptions\Fx\ManagerApprovalAlreadyUsedException;
use App\Exceptions\Fx\ManagerApprovalNotFoundException;
use App\Models\FxManagerApproval;
use App\Models\User;
use App\Services\Fx\FxManagerApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers FxManagerApprovalService::consume() double-consume fix.
 *
 * Bug: the model passed to consume() could be stale (fetched before the outer
 * DB::transaction). Two concurrent requests could both pass the status check using
 * their respective stale copies and both successfully consume the same approval.
 *
 * Fix: consume() now re-fetches the row with SELECT FOR UPDATE inside the caller's
 * transaction, ensuring only one request can mutate it at a time.
 */
class FxManagerApprovalConsumeTest extends TestCase
{
    use RefreshDatabase;

    private FxManagerApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FxManagerApprovalService();
    }

    private function makeApproval(ManagerApprovalStatus $status = ManagerApprovalStatus::Approved): FxManagerApproval
    {
        $user = User::factory()->create();

        return FxManagerApproval::create([
            'beds24_booking_id'  => 'B_CONSUME_TEST',
            'bot_session_id'     => 'sess-test',
            'cashier_id'         => $user->id,
            'currency'           => Currency::UZS,
            'amount_presented'   => 1_000_000,
            'amount_proposed'    => 950_000,
            'variance_pct'       => 5.0,
            'status'             => $status->value,
            'expires_at'         => now()->addHour(),
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function consume_marks_approval_as_consumed(): void
    {
        $approval = $this->makeApproval(ManagerApprovalStatus::Approved);

        \Illuminate\Support\Facades\DB::transaction(function () use ($approval) {
            $this->service->consume($approval, 999);
        });

        $approval->refresh();
        $this->assertEquals(ManagerApprovalStatus::Consumed, $approval->status);
        $this->assertEquals(999, $approval->used_in_cash_transaction_id);
        $this->assertNotNull($approval->resolved_at);
    }

    // ── Already-consumed guard ────────────────────────────────────────────────

    /** @test */
    public function consume_throws_already_used_exception_when_already_consumed(): void
    {
        $approval = $this->makeApproval(ManagerApprovalStatus::Consumed);

        $this->expectException(ManagerApprovalAlreadyUsedException::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($approval) {
            $this->service->consume($approval, 999);
        });
    }

    /** @test */
    public function consume_with_stale_approved_model_detects_already_consumed_via_lock(): void
    {
        // Simulate the double-consume scenario:
        // - Request A fetches approval (status=Approved) → passes pre-check
        // - Request B fetches approval (status=Approved) → passes pre-check
        // - Request A consumes → DB now has status=Consumed
        // - Request B calls consume() with its stale 'Approved' model
        //   → The fix: re-fetches with lockForUpdate → sees 'Consumed' → throws
        $approval = $this->makeApproval(ManagerApprovalStatus::Approved);

        // Request A consumes first (simulated by direct DB update)
        \Illuminate\Support\Facades\DB::transaction(function () use ($approval) {
            $this->service->consume($approval, 100);
        });

        // Request B has a stale model still showing 'Approved' in memory
        $staleModel = clone $approval;
        // Force the in-memory status to 'Approved' to simulate the stale read
        $staleModel->status = ManagerApprovalStatus::Approved;

        $this->expectException(ManagerApprovalAlreadyUsedException::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($staleModel) {
            // The lock inside consume() will re-read the actual DB state (Consumed),
            // NOT the stale in-memory status on $staleModel
            $this->service->consume($staleModel, 200);
        });
    }

    // ── Not-found guard ───────────────────────────────────────────────────────

    /** @test */
    public function consume_throws_not_found_when_approval_row_deleted(): void
    {
        $approval   = $this->makeApproval(ManagerApprovalStatus::Approved);
        $approvalId = $approval->id;
        $approval->delete(); // Row removed from DB

        // Create a detached model with the old ID so the lock returns null
        $phantom           = new FxManagerApproval();
        $phantom->id       = $approvalId;
        $phantom->status   = ManagerApprovalStatus::Approved;
        $phantom->expires_at = now()->addHour();

        $this->expectException(ManagerApprovalNotFoundException::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($phantom) {
            $this->service->consume($phantom, 999);
        });
    }

    // ── Non-consumable statuses ───────────────────────────────────────────────

    /** @test */
    public function consume_throws_not_found_for_pending_approval(): void
    {
        $approval = $this->makeApproval(ManagerApprovalStatus::Pending);

        $this->expectException(ManagerApprovalNotFoundException::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($approval) {
            $this->service->consume($approval, 999);
        });
    }

    /** @test */
    public function consume_throws_not_found_for_expired_approval(): void
    {
        $approval             = $this->makeApproval(ManagerApprovalStatus::Approved);
        $approval->expires_at = now()->subMinute();
        $approval->save();

        $this->expectException(ManagerApprovalNotFoundException::class);

        \Illuminate\Support\Facades\DB::transaction(function () use ($approval) {
            $this->service->consume($approval, 999);
        });
    }
}
