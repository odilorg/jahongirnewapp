<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Beds24SyncStatus;
use App\Jobs\Beds24PaymentSyncJob;
use App\Models\Beds24PaymentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the Beds24 payment sync repair commands.
 *
 * Tests are grouped by failure mode and command:
 *
 *  beds24:repair-missing-syncs
 *  (A) Cash transaction with no sync row is detected and repaired
 *  (B) Cash transaction already linked to a sync row is skipped
 *  (C) Cash transaction with a sync row via FK (but no back-link) is skipped
 *  (D) Zero-amount transaction is skipped safely (no API push makes sense)
 *  (E) --since-days limits lookback window
 *  (F) --dry-run reports without dispatching
 *  (G) Running twice does not create a second sync row (idempotent)
 *
 *  beds24:repair-failed-syncs
 *  (H) Failed sync within attempt budget is reset to pending and re-dispatched
 *  (I) Failed sync at or beyond budget is escalated (not re-dispatched)
 *  (J) Non-failed syncs are untouched
 *  (K) --dry-run reports without dispatching
 *  (L) Running twice does not cause double-dispatch (idempotent via status change)
 *  (M) Summary counts are correct
 *
 *  fx:repair-stuck-syncs (existing command — regression guard)
 *  (N) Stuck-pending sync is re-dispatched
 *  (O) Stuck-pushing sync is reset to pending and re-dispatched
 *  (P) Fresh pushing sync (recent last_push_at) is not touched
 */
class Beds24SyncRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Disable FK checks so test fixtures don't need real cashier_shifts / users
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // Purge both tables within this test's transaction scope.
        // RefreshDatabase wraps each test in a transaction (rolled back in tearDown),
        // so these deletes are invisible outside this test. This also hides any stale
        // rows that were committed outside a test transaction (e.g. from manual artisan
        // runs against the test DB), so tests always see a clean slate.
        DB::table('beds24_payment_syncs')->delete();
        DB::table('cash_transactions')->delete();
    }

    protected function tearDown(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Insert a minimal cash_transaction row and return its ID.
     */
    private function makeCashTx(array $overrides = []): int
    {
        return DB::table('cash_transactions')->insertGetId(array_merge([
            'cashier_shift_id'    => 1,
            'type'                => 'in',
            'amount'              => 100.00,
            'created_by'          => 1,
            'beds24_booking_id'   => 'B24-TEST-001',
            'usd_equivalent_paid' => 50.00,
            'beds24_payment_sync_id' => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));
    }

    /**
     * Create a Beds24PaymentSync row for a given cash transaction ID.
     */
    private function makeSyncRow(
        int    $cashTxId,
        string $status       = 'pending',
        int    $pushAttempts = 0,
        ?string $beds24BookingId = 'B24-TEST-001',
    ): Beds24PaymentSync {
        return Beds24PaymentSync::create([
            'cash_transaction_id' => $cashTxId,
            'beds24_booking_id'   => $beds24BookingId,
            'local_reference'     => (string) Str::uuid(),
            'amount_usd'          => 50.00,
            'status'              => $status,
            'push_attempts'       => $pushAttempts,
            'last_push_at'        => in_array($status, ['pushing']) ? now() : null,
            'last_error'          => $status === 'failed' ? 'Beds24 API timeout' : null,
        ]);
    }

    // ── (A) Missing sync detected and repaired ────────────────────────────────

    /** @test */
    public function repair_missing_creates_sync_row_for_unlinked_transaction(): void
    {
        $txId = $this->makeCashTx(); // no sync row created

        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        // Sync row should now exist
        $this->assertDatabaseHas('beds24_payment_syncs', [
            'cash_transaction_id' => $txId,
            'status'              => 'pending',
        ]);

        // Transaction back-link should be set
        $this->assertDatabaseHas('cash_transactions', [
            'id'                    => $txId,
            'beds24_booking_id'     => 'B24-TEST-001',
        ]);
        $this->assertNotNull(
            DB::table('cash_transactions')->where('id', $txId)->value('beds24_payment_sync_id')
        );

        // Job should have been dispatched
        Queue::assertPushed(Beds24PaymentSyncJob::class);
    }

    // ── (B) Already-linked transaction skipped ────────────────────────────────

    /** @test */
    public function repair_missing_skips_transaction_already_linked_to_sync_row(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'confirmed');

        // Back-link the sync row
        DB::table('cash_transactions')->where('id', $txId)->update([
            'beds24_payment_sync_id' => $sync->id,
        ]);

        Queue::fake(); // Reset after setUp
        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        // Exactly one sync row (no duplicate created)
        $this->assertSame(
            1,
            Beds24PaymentSync::where('cash_transaction_id', $txId)->count()
        );

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── (C) Sync row exists via FK but back-link column is null ───────────────

    /** @test */
    public function repair_missing_skips_transaction_with_sync_row_via_fk(): void
    {
        $txId = $this->makeCashTx(); // beds24_payment_sync_id = null
        $this->makeSyncRow($txId, 'pushed'); // sync row references this tx

        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        // Still exactly one sync row
        $this->assertSame(
            1,
            Beds24PaymentSync::where('cash_transaction_id', $txId)->count()
        );

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── (D) Zero-amount transaction skipped ───────────────────────────────────

    /** @test */
    public function repair_missing_skips_zero_usd_transaction(): void
    {
        $this->makeCashTx(['usd_equivalent_paid' => 0.00]);

        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        $this->assertSame(0, Beds24PaymentSync::count());
        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    /** @test */
    public function repair_missing_skips_null_usd_transaction(): void
    {
        $this->makeCashTx(['usd_equivalent_paid' => null]);

        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        $this->assertSame(0, Beds24PaymentSync::count());
    }

    // ── (E) --since-days limits lookback ─────────────────────────────────────

    /** @test */
    public function repair_missing_respects_since_days_window(): void
    {
        // Old transaction (outside window)
        $this->makeCashTx(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)]);

        $this->artisan('beds24:repair-missing-syncs', ['--since-days' => 30])->assertExitCode(0);

        $this->assertSame(0, Beds24PaymentSync::count());
        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    /** @test */
    public function repair_missing_includes_transactions_within_since_days_window(): void
    {
        // Recent transaction (inside window)
        $this->makeCashTx(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);

        $this->artisan('beds24:repair-missing-syncs', ['--since-days' => 30])->assertExitCode(0);

        $this->assertSame(1, Beds24PaymentSync::count());
        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (F) --dry-run does not dispatch ───────────────────────────────────────

    /** @test */
    public function repair_missing_dry_run_does_not_create_sync_row_or_dispatch(): void
    {
        $txId = $this->makeCashTx();

        $this->artisan('beds24:repair-missing-syncs', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Beds24PaymentSync::count());
        Queue::assertNotPushed(Beds24PaymentSyncJob::class);

        // Transaction should still have no sync link
        $this->assertNull(
            DB::table('cash_transactions')->where('id', $txId)->value('beds24_payment_sync_id')
        );
    }

    // ── (G) Idempotent — running twice does not duplicate sync row ────────────

    /** @test */
    public function repair_missing_is_idempotent(): void
    {
        $txId = $this->makeCashTx();

        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);
        $this->artisan('beds24:repair-missing-syncs')->assertExitCode(0);

        // Still exactly one sync row
        $this->assertSame(
            1,
            Beds24PaymentSync::where('cash_transaction_id', $txId)->count()
        );

        // Only one job dispatched across two runs (second run skips already-linked tx)
        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (H) Failed sync within budget re-dispatched ───────────────────────────

    /** @test */
    public function repair_failed_resets_and_dispatches_within_attempt_budget(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'failed', pushAttempts: 3);

        $this->artisan('beds24:repair-failed-syncs', ['--max-attempts' => 9])
            ->assertExitCode(0);

        // Status should be reset to pending
        $this->assertDatabaseHas('beds24_payment_syncs', [
            'id'     => $sync->id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(Beds24PaymentSyncJob::class, fn ($job) =>
            $this->getPrivateProperty($job, 'syncId') === $sync->id
        );
    }

    // ── (I) Failed sync beyond budget escalated ───────────────────────────────

    /** @test */
    public function repair_failed_escalates_sync_beyond_attempt_budget(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'failed', pushAttempts: 9);

        $this->artisan('beds24:repair-failed-syncs', ['--max-attempts' => 9])
            ->assertExitCode(0);

        // Status stays 'failed' — escalated, not retried
        $this->assertDatabaseHas('beds24_payment_syncs', [
            'id'     => $sync->id,
            'status' => 'failed',
        ]);

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── (J) Non-failed syncs untouched by repair-failed ──────────────────────

    /** @test */
    public function repair_failed_does_not_touch_non_failed_syncs(): void
    {
        foreach (['pending', 'pushing', 'pushed', 'confirmed', 'skipped'] as $i => $status) {
            $txId = $this->makeCashTx(['beds24_booking_id' => "B24-{$i}"]);
            $this->makeSyncRow($txId, $status, beds24BookingId: "B24-{$i}");
        }

        $this->artisan('beds24:repair-failed-syncs')->assertExitCode(0);

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── (K) repair-failed --dry-run ───────────────────────────────────────────

    /** @test */
    public function repair_failed_dry_run_does_not_dispatch(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'failed', pushAttempts: 2);

        $this->artisan('beds24:repair-failed-syncs', ['--dry-run' => true])->assertExitCode(0);

        // Status unchanged
        $this->assertDatabaseHas('beds24_payment_syncs', [
            'id'     => $sync->id,
            'status' => 'failed',
        ]);

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── (L) repair-failed idempotency ─────────────────────────────────────────

    /** @test */
    public function repair_failed_is_idempotent(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'failed', pushAttempts: 2);

        // First run: resets to pending, dispatches job
        $this->artisan('beds24:repair-failed-syncs')->assertExitCode(0);

        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);

        // The row is now 'pending' — second run finds no 'failed' rows → no-op
        $this->artisan('beds24:repair-failed-syncs')->assertExitCode(0);

        // Still only 1 dispatch (first run only)
        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (M) Summary counts ────────────────────────────────────────────────────

    /** @test */
    public function repair_failed_summary_counts_are_accurate(): void
    {
        // 2 retryable (push_attempts = 3)
        foreach (range(1, 2) as $i) {
            $txId = $this->makeCashTx(['beds24_booking_id' => "B24-R{$i}"]);
            $this->makeSyncRow($txId, 'failed', pushAttempts: 3, beds24BookingId: "B24-R{$i}");
        }

        // 1 escalated (push_attempts = 9)
        $txId3 = $this->makeCashTx(['beds24_booking_id' => 'B24-E1']);
        $this->makeSyncRow($txId3, 'failed', pushAttempts: 9, beds24BookingId: 'B24-E1');

        $this->artisan('beds24:repair-failed-syncs', ['--max-attempts' => 9])
            ->assertExitCode(0)
            ->expectsOutputToContain('2')   // retried
            ->expectsOutputToContain('1');  // escalated

        Queue::assertPushed(Beds24PaymentSyncJob::class, 2);
    }

    // ── (N) fx:repair-stuck-syncs — stuck-pending re-dispatched ─────────────

    /** @test */
    public function repair_stuck_redispatches_old_pending_sync(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'pending');

        // Make it old enough to be "stuck"
        DB::table('beds24_payment_syncs')
            ->where('id', $sync->id)
            ->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('fx:repair-stuck-syncs', ['--pending-after' => 15])
            ->assertExitCode(0);

        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (O) fx:repair-stuck-syncs — stuck-pushing reset and re-dispatched ────

    /** @test */
    public function repair_stuck_resets_old_pushing_sync_to_pending_and_redispatches(): void
    {
        $txId = $this->makeCashTx();
        $sync = $this->makeSyncRow($txId, 'pushing');

        DB::table('beds24_payment_syncs')
            ->where('id', $sync->id)
            ->update(['last_push_at' => now()->subMinutes(20)]);

        $this->artisan('fx:repair-stuck-syncs', ['--pushing-after' => 10])
            ->assertExitCode(0);

        $this->assertDatabaseHas('beds24_payment_syncs', [
            'id'     => $sync->id,
            'status' => 'pending',
        ]);

        Queue::assertPushed(Beds24PaymentSyncJob::class, 1);
    }

    // ── (P) fx:repair-stuck-syncs — fresh pushing sync not touched ───────────

    /** @test */
    public function repair_stuck_leaves_fresh_pushing_sync_alone(): void
    {
        $txId = $this->makeCashTx();
        $this->makeSyncRow($txId, 'pushing'); // last_push_at = now()

        $this->artisan('fx:repair-stuck-syncs', ['--pushing-after' => 10])
            ->assertExitCode(0);

        $this->assertDatabaseHas('beds24_payment_syncs', [
            'cash_transaction_id' => $txId,
            'status'              => 'pushing',
        ]);

        Queue::assertNotPushed(Beds24PaymentSyncJob::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Read a private property from a job instance for assertion purposes.
     */
    private function getPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }
}
