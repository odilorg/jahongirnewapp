<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Enums\CashTransactionSource;
use App\Enums\DrawerTruthExcludedReason;
use App\Enums\TransactionType;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Beds24Booking;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\User;
use App\Services\OwnerAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase 1 (2026-05-11) — Beds24 admin-cash → drawer-truth.
 *
 * Exercises the five-guard chain in
 * `Beds24WebhookController::createExternalBookkeepingRow` end-to-end:
 * given a webhook-shaped invocation, verify the resulting
 * `cash_transactions` row has the correct
 * (counts_as_drawer_truth, drawer_truth_excluded_reason) verdict,
 * the right side-effect alert fired (or didn't), and
 * `scopeDrawerTruth` reflects the verdict accordingly.
 *
 * Side-effect alerts are exercised via `Bus::fake([SendTelegramNotificationJob])`
 * so nothing reaches Telegram during the test (also
 * belt-and-suspendered by the OwnerAlertService env-guard deployed
 * in cc2b93c). Tests opt in to the dispatch flag so the assertions
 * see the dispatch the env-guard would otherwise suppress.
 */
final class Beds24AdminCashDrawerTruthTest extends TestCase
{
    use DatabaseTransactions;

    private const TEST_BOOKING_ID = 'TEST-12345';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 5, 12, 14, 0, 0, 'Asia/Tashkent'));

        // Opt-in to OwnerAlertService dispatch in tests so Bus::fake
        // can intercept assertions. Without this the env-guard
        // suppresses the job and Bus::assertDispatched would fail.
        config([
            'services.owner_alert_bot.allow_outbound_in_testing' => true,
            'services.owner_alert_bot.owner_chat_id' => 12345,
            // Phase 1 config: cutoff is yesterday so all our fixtures
            // (today) are past it unless a specific test overrides.
            'cashier.beds24_admin_cash_drawer_truth_from' => '2026-05-11 00:00:00',
            'cashier.beds24_admin_cash_alert_threshold_usd' => 200.0,
            'cashier.beds24_external_cash_methods' => ['cash', 'naqd'],
        ]);

        Bus::fake([SendTelegramNotificationJob::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — happy path
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_cash_with_open_shift_no_dupe_after_cutoff_counts_as_drawer_truth(): void
    {
        $booking = $this->seedBooking();
        $shift = $this->seedOpenShift();

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->counts_as_drawer_truth, 'expected drawer-truth=true');
        $this->assertNull($row->drawer_truth_excluded_reason);
        $this->assertSame($shift->id, $row->cashier_shift_id);
        $this->assertSame(CashTransactionSource::Beds24External, $row->source_trigger);

        // Drawer balance includes this row now.
        $this->assertTrue(
            CashTransaction::drawerTruth()->where('id', $row->id)->exists(),
            'scopeDrawerTruth should include the row',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — partial-paid scenario (also happy path; included for parity
    // with the user's listing-vs-payment-prep alignment work)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_cash_amount_equals_payment_prep_amount_for_same_outstanding(): void
    {
        // The webhook delivers the OUTSTANDING amount as the payment
        // line's amount. We don't compute outstanding here — that's
        // GroupAwareCashierAmountResolver's job at payment-prep. This
        // test asserts the drawer-truth row's `amount` matches what
        // the webhook fed it byte-for-byte (no rounding, no FX).
        $booking = $this->seedBooking();
        $this->seedOpenShift();

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();
        $this->assertSame('60.00', (string) $row->amount);
        $this->assertSame('USD', (string) $row->currency?->value ?? $row->currency);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — Guard 1 (non-cash method) excludes
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_card_method_does_not_count_as_drawer_truth(): void
    {
        $booking = $this->seedBooking();
        $this->seedOpenShift();

        $this->invokeHandler($booking, amount: 60.0, method: 'karta');

        $row = CashTransaction::latest('id')->first();
        $this->assertFalse((bool) $row->counts_as_drawer_truth);
        $this->assertSame(
            DrawerTruthExcludedReason::NonCashMethod,
            $row->drawer_truth_excluded_reason,
        );

        $this->assertFalse(
            CashTransaction::drawerTruth()->where('id', $row->id)->exists(),
            'karta row must not enter drawer truth',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Guard 2 (before-cutoff) excludes (no retroactive shift)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_cash_before_cutoff_does_not_count_as_drawer_truth(): void
    {
        // Push the cutoff into the future relative to "now" (2026-05-12 14:00).
        config(['cashier.beds24_admin_cash_drawer_truth_from' => '2099-01-01 00:00:00']);

        $booking = $this->seedBooking();
        $this->seedOpenShift();

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();
        $this->assertFalse((bool) $row->counts_as_drawer_truth);
        $this->assertSame(
            DrawerTruthExcludedReason::BeforeCutoff,
            $row->drawer_truth_excluded_reason,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 5 — Guard 3 (matching cashier_bot row) excludes (dedup)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_cash_with_matching_cashier_bot_row_does_not_count_as_drawer_truth(): void
    {
        $booking = $this->seedBooking();
        $shift = $this->seedOpenShift();

        // A cashier-bot row arrived 60s before the webhook for the
        // same booking/amount — simulates [ref:UUID] reconciliation
        // failure where both paths created rows.
        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 60.0,
            'currency' => 'USD',
            'category' => \App\Enums\TransactionCategory::SALE,
            'source_trigger' => CashTransactionSource::CashierBot,
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'payment_method' => 'cash',
            'occurred_at' => now()->subSeconds(60),
        ]);

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();
        $this->assertFalse((bool) $row->counts_as_drawer_truth);
        $this->assertSame(
            DrawerTruthExcludedReason::MatchingCashierBotRow,
            $row->drawer_truth_excluded_reason,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 6 — Guard 4 (no open shift) excludes + alert fires
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function admin_cash_with_no_open_shift_does_not_count_as_drawer_truth_and_alerts_manager(): void
    {
        $booking = $this->seedBooking();
        // No CashierShift seeded — none is open.

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();
        $this->assertFalse((bool) $row->counts_as_drawer_truth);
        $this->assertSame(
            DrawerTruthExcludedReason::NoOpenShift,
            $row->drawer_truth_excluded_reason,
        );

        // Manager alert fired.
        Bus::assertDispatched(
            SendTelegramNotificationJob::class,
            fn ($job) => str_contains($job->params['text'] ?? '', 'нет открытой смены'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 7 — Existing scopeDrawerTruth regression
    // (historical rows still excluded)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function historical_beds24_external_row_without_flag_remains_excluded(): void
    {
        // Pre-Phase-1 row: no counts_as_drawer_truth set (default false).
        CashTransaction::create([
            'type' => TransactionType::IN,
            'amount' => 60.0,
            'currency' => 'USD',
            'category' => \App\Enums\TransactionCategory::SALE,
            'source_trigger' => CashTransactionSource::Beds24External,
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'payment_method' => 'cash',
            'occurred_at' => now()->subDays(30),
            // counts_as_drawer_truth defaults to false
        ]);

        $found = CashTransaction::drawerTruth()
            ->where('beds24_booking_id', self::TEST_BOOKING_ID)
            ->exists();

        $this->assertFalse(
            $found,
            'historical beds24_external row must remain excluded from drawer truth',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 8 — Manual manager flip is auditable
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function manager_can_manually_flip_drawer_truth_with_audit_metadata(): void
    {
        $booking = $this->seedBooking();
        $this->invokeHandler($booking, amount: 60.0, method: 'naqd'); // no shift → excluded

        $row = CashTransaction::latest('id')->first();
        $this->assertFalse((bool) $row->counts_as_drawer_truth);

        // Simulate the Filament action behavior (without spinning up
        // Livewire). The action's body just forceFill()->save() +
        // Log::info — exercised directly here.
        $manager = User::factory()->create();
        $now = now();

        $row->forceFill([
            'counts_as_drawer_truth' => true,
            'drawer_truth_flipped_by_user_id' => $manager->id,
            'drawer_truth_flipped_at' => $now,
            'drawer_truth_flip_note' => 'сверено с ночным админом',
        ])->save();

        $row->refresh();

        $this->assertTrue((bool) $row->counts_as_drawer_truth);
        $this->assertSame($manager->id, $row->drawer_truth_flipped_by_user_id);
        $this->assertNotNull($row->drawer_truth_flipped_at);
        $this->assertSame('сверено с ночным админом', $row->drawer_truth_flip_note);

        // Drawer balance now includes it.
        $this->assertTrue(
            CashTransaction::drawerTruth()->where('id', $row->id)->exists(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 9 — variance threshold alert (NEW per user instruction)
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function large_admin_cash_above_threshold_counts_as_drawer_truth_and_alerts_owner(): void
    {
        $booking = $this->seedBooking();
        $this->seedOpenShift();
        // Threshold default $200 → $500 is well above.

        $this->invokeHandler($booking, amount: 500.0, method: 'naqd');

        $row = CashTransaction::latest('id')->first();

        // Row IS drawer truth (all guards passed).
        $this->assertTrue((bool) $row->counts_as_drawer_truth);
        $this->assertNull($row->drawer_truth_excluded_reason);

        // Owner alert ALSO fired because amount > threshold.
        Bus::assertDispatched(
            SendTelegramNotificationJob::class,
            fn ($job) => str_contains($job->params['text'] ?? '', 'Крупная наличная')
                && str_contains($job->params['text'] ?? '', '$500'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Invoke `Beds24WebhookController::createExternalBookkeepingRow`
     * directly via reflection so we test exactly the unit under
     * change without spinning up the full Beds24 webhook payload
     * pipeline. The five guards + alert side-effects live inside
     * that method.
     */
    private function invokeHandler(
        Beds24Booking $booking,
        float $amount,
        string $method,
    ): void {
        $controller = app(\App\Http\Controllers\Beds24WebhookController::class);

        $reflection = new \ReflectionClass($controller);
        $methodRef = $reflection->getMethod('createExternalBookkeepingRow');
        $methodRef->setAccessible(true);

        $methodRef->invoke(
            $controller,
            $booking,
            $amount,
            $method,
            'Test payment',
            "Beds24 #{$booking->beds24_booking_id} item#test-".uniqid(),
            'item-'.uniqid(),
        );
    }

    private function seedBooking(): Beds24Booking
    {
        return Beds24Booking::create([
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'property_id' => 41097,
            'guest_name' => 'Test Guest',
            'room_name' => 'Room 101',
            'arrival_date' => '2026-05-12',
            'departure_date' => '2026-05-13',
            'booking_status' => 'confirmed',
            'currency' => 'USD',
            'total_amount' => 120.0,
            'invoice_balance' => 60.0,
        ]);
    }

    private function seedOpenShift(): CashierShift
    {
        $drawer = \App\Models\CashDrawer::create([
            'name' => 'Test Drawer',
            'is_active' => true,
        ]);
        $user = User::factory()->create();

        return CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now()->subHours(2),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reviewer follow-ups (code-reviewer 2026-05-11)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Defence-in-depth for the scopeDrawerTruth Path B invariant:
     * a row with counts_as_drawer_truth=true AND a non-cash
     * payment_method (which the webhook handler should never create
     * but a raw SQL backfill or a future writer could) must still
     * be excluded from drawer truth by the scope's classifier-driven
     * cash-method filter.
     *
     * @test
     */
    public function scope_excludes_drawer_truth_flag_with_non_cash_method(): void
    {
        $row = CashTransaction::create([
            'type' => TransactionType::IN,
            'amount' => 60.0,
            'currency' => 'USD',
            'category' => \App\Enums\TransactionCategory::SALE,
            'source_trigger' => CashTransactionSource::Beds24External,
            'counts_as_drawer_truth' => true,
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'payment_method' => 'karta',
            'occurred_at' => now(),
        ]);

        $this->assertFalse(
            CashTransaction::drawerTruth()->where('id', $row->id)->exists(),
            'scopeDrawerTruth must reject the row despite the flag being true',
        );
    }

    /** @test */
    public function scope_includes_drawer_truth_flag_with_naqd_method(): void
    {
        $row = CashTransaction::create([
            'type' => TransactionType::IN,
            'amount' => 60.0,
            'currency' => 'USD',
            'category' => \App\Enums\TransactionCategory::SALE,
            'source_trigger' => CashTransactionSource::Beds24External,
            'counts_as_drawer_truth' => true,
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'payment_method' => 'naqd',
            'occurred_at' => now(),
        ]);

        $this->assertTrue(
            CashTransaction::drawerTruth()->where('id', $row->id)->exists(),
            'scopeDrawerTruth must include naqd-method drawer-truth rows',
        );
    }

    /**
     * NoOpenShift exclusion must fire EXACTLY ONE alert. Previously
     * the legacy `alertViolation` also fired for any cash-method
     * excluded row, so a no-shift exclusion produced two alerts.
     * The Phase 1 refactor removed `alertViolation` entirely.
     *
     * @test
     */
    public function no_open_shift_exclusion_fires_exactly_one_alert(): void
    {
        $booking = $this->seedBooking();

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        Bus::assertDispatchedTimes(SendTelegramNotificationJob::class, 1);
    }

    /**
     * MatchingCashierBotRow exclusion is silent — no alert. Filament
     * reconciliation surfaces it for daily review instead.
     *
     * @test
     */
    public function matching_cashier_bot_row_exclusion_fires_no_alert(): void
    {
        $booking = $this->seedBooking();
        $shift = $this->seedOpenShift();

        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type' => TransactionType::IN,
            'amount' => 60.0,
            'currency' => 'USD',
            'category' => \App\Enums\TransactionCategory::SALE,
            'source_trigger' => CashTransactionSource::CashierBot,
            'beds24_booking_id' => self::TEST_BOOKING_ID,
            'payment_method' => 'cash',
            'occurred_at' => now()->subSeconds(30),
        ]);

        $this->invokeHandler($booking, amount: 60.0, method: 'naqd');

        Bus::assertNotDispatched(SendTelegramNotificationJob::class);
    }
}
