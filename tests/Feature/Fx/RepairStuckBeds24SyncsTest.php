<?php

namespace Tests\Feature\Fx;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for fx:repair-stuck-syncs artisan command.
 *
 * Scenarios:
 *  (A) Pending rows older than threshold → job dispatched
 *  (B) Pushing rows older than threshold → reset to pending, job dispatched
 *  (C) Recent pending rows → not touched (below threshold)
 *  (D) Terminal rows (Confirmed/Failed/Skipped) → not touched
 *  (E) --dry-run → reports without dispatching
 *  (F) Nothing stuck → exits cleanly
 */
class RepairStuckBeds24SyncsTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSyncRow(string $status, int $minutesOld, ?string $lastPushAt = null): Beds24PaymentSync
    {
        $shift   = CashierShift::factory()->create(['status' => 'open', 'opened_at' => now()]);
        $cashier = User::factory()->create();

        $tx = CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'amount'           => 100,
            'currency'         => 'USD',
            'category'         => 'sale',
            'beds24_booking_id' => 'BK-' . uniqid(),
            'payment_method'   => 'cash',
            'source_trigger'   => 'cashier_bot',
            'created_by'       => $cashier->id,
            'occurred_at'      => now(),
        ]);

        $sync = Beds24PaymentSync::create([
            'cash_transaction_id' => $tx->id,
            'beds24_booking_id'   => $tx->beds24_booking_id,
            'local_reference'     => \Illuminate\Support\Str::uuid()->toString(),
            'amount_usd'          => 100.0,
            'status'              => $status,
            'last_push_at'        => $lastPushAt,
        ]);

        // Back-date created_at to simulate age (created_at is not fillable, use DB directly)
        \Illuminate\Support\Facades\DB::table('beds24_payment_syncs')
            ->where('id', $sync->id)
            ->update(['created_at' => now()->subMinutes($minutesOld)]);

        return $sync->fresh();
    }

    // ── (A) Stuck pending → job dispatched ───────────────────────────────────

    /** @test */
    public function dispatches_job_for_pending_rows_older_than_threshold(): void
    {
        Queue::fake();

        $this->makeSyncRow(Beds24SyncStatus::Pending->value, minutesOld: 20);

        $this->artisan('fx:repair-stuck-syncs', ['--pending-after' => 15])
            ->assertSuccessful();

        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (B) Stuck pushing → reset to pending + job dispatched ────────────────

    /** @test */
    public function resets_pushing_rows_to_pending_and_dispatches_job(): void
    {
        Queue::fake();

        $sync = $this->makeSyncRow(
            Beds24SyncStatus::Pushing->value,
            minutesOld:  15,
            lastPushAt:  now()->subMinutes(12)->toDateTimeString(),
        );

        $this->artisan('fx:repair-stuck-syncs', ['--pushing-after' => 10])
            ->assertSuccessful();

        Queue::assertPushed(Beds24PaymentSyncJob::class);

        // Row must have been reset to pending
        $this->assertSame(
            Beds24SyncStatus::Pending->value,
            $sync->fresh()->status->value
        );
    }

    // ── (C) Recent pending rows → not touched ────────────────────────────────

    /** @test */
    public function does_not_touch_pending_rows_below_age_threshold(): void
    {
        Queue::fake();

        // 5 minutes old — below the 15-minute threshold
        $this->makeSyncRow(Beds24SyncStatus::Pending->value, minutesOld: 5);

        $this->artisan('fx:repair-stuck-syncs', ['--pending-after' => 15])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── (D) Terminal rows → not touched ──────────────────────────────────────

    /** @test */
    public function does_not_touch_terminal_rows(): void
    {
        Queue::fake();

        $this->makeSyncRow(Beds24SyncStatus::Confirmed->value, minutesOld: 60);
        $this->makeSyncRow(Beds24SyncStatus::Failed->value,    minutesOld: 60);
        $this->makeSyncRow(Beds24SyncStatus::Skipped->value,   minutesOld: 60);

        $this->artisan('fx:repair-stuck-syncs')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── (E) --dry-run → no dispatch ──────────────────────────────────────────

    /** @test */
    public function dry_run_does_not_dispatch_jobs(): void
    {
        Queue::fake();

        $this->makeSyncRow(Beds24SyncStatus::Pending->value, minutesOld: 20);

        $this->artisan('fx:repair-stuck-syncs', ['--dry-run' => true])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── (F) Nothing stuck → clean exit ───────────────────────────────────────

    /** @test */
    public function exits_cleanly_when_nothing_is_stuck(): void
    {
        Queue::fake();

        $this->artisan('fx:repair-stuck-syncs')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
