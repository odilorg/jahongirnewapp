<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\OverrideTier;
use App\Exceptions\PaymentBlockedException;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\CashDrawer;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\DailyExchangeRate;
use App\Models\User;
use App\Services\BotPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 2026-05-08 regression — split-payment per-leg variance bypass.
 *
 * Bug: BotPaymentService::recordSplitPayment() correctly enforced
 * sum-lock at the parent layer (cash_leg + card_leg ≈ presented total).
 * It then called recordPayment() per leg, which re-evaluated FX
 * variance at the leg level — comparing each leg's amount against
 * the FULL presented booking total. For any realistic split (e.g.
 * 520k cash + 270k card = 790k) each leg looked like a 30–70%
 * variance and PaymentBlockedException fired (>10% threshold).
 *
 * Production impact: 0 successful splits in the 30 days before this
 * fix. Operators worked around it by single-method recording or
 * embedding amounts into guest_name for tracking.
 *
 * Fix: recordPayment() skips the per-leg variance evaluation when
 * paymentGroupType is one of {split, mixed_currency_split,
 * group_bulk}. The parent layer is the right authority for sum-lock
 * at this granularity.
 *
 * These tests pin the contract end-to-end through the DB so a
 * future change can't silently re-introduce per-leg variance
 * blocking on splits, AND can't weaken standalone variance
 * protection.
 */
final class SplitPaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.beds24_auto_push_payment' => false, // don't dispatch real job
        ]);
    }

    // ── Test 1: realistic split succeeds ────────────────────────────

    /** @test */
    public function realistic_uzs_split_succeeds_without_blocking(): void
    {
        // The exact 2026-05-08 incident: booking ≈ 790,000 UZS, split
        // 520k cash + 270k card. Each leg ≈ 34% / 66% of the full
        // total — both above the 10% block threshold. Pre-fix this
        // threw PaymentBlockedException. Post-fix it must record both
        // legs cleanly.
        [$shift, $user, $booking, $sync] = $this->scenario(invoiceBalanceUsd: 65.0);

        $presentation = $this->presentation($booking, $sync);

        $cashLeg = $this->leg($presentation, $shift, $user, 'UZS', 520_000.0, 'cash');
        $cardLeg = $this->leg($presentation, $shift, $user, 'UZS', 270_000.0, 'card');

        $rows = app(BotPaymentService::class)->recordSplitPayment($cashLeg, $cardLeg);

        $this->assertCount(2, $rows, 'split records 2 cash_transaction rows');

        // Both rows persisted in DB — refetch to be sure
        $cashRow = CashTransaction::find($rows[0]->id)->fresh();
        $cardRow = CashTransaction::find($rows[1]->id)->fresh();

        $this->assertNotNull($cashRow);
        $this->assertNotNull($cardRow);
        $this->assertSame('cash', $cashRow->payment_method);
        $this->assertSame('card', $cardRow->payment_method);
        $this->assertEquals(520_000.0, (float) $cashRow->amount);
        $this->assertEquals(270_000.0, (float) $cardRow->amount);
    }

    // ── Test 2: failing sum-lock still blocks at the parent ────────

    /** @test */
    public function failing_sum_lock_throws_at_parent_not_payment_blocked(): void
    {
        // Sum 700k vs presented 790k → InvalidArgumentException at the
        // parent recordSplitPayment sum-lock guard. Must NOT be the
        // per-leg PaymentBlockedException (which would mean the bypass
        // is too permissive).
        [$shift, $user, $booking, $sync] = $this->scenario(invoiceBalanceUsd: 65.0);

        $presentation = $this->presentation($booking, $sync);

        $cashLeg = $this->leg($presentation, $shift, $user, 'UZS', 500_000.0, 'cash');
        $cardLeg = $this->leg($presentation, $shift, $user, 'UZS', 200_000.0, 'card'); // 700k total, expects 790k

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Split-payment sum-lock failed');

        app(BotPaymentService::class)->recordSplitPayment($cashLeg, $cardLeg);
    }

    // ── Test 3: split legs share journal_entry_id + group type ─────

    /** @test */
    public function split_legs_share_journal_uuid_and_payment_group_type(): void
    {
        [$shift, $user, $booking, $sync] = $this->scenario(invoiceBalanceUsd: 65.0);

        $presentation = $this->presentation($booking, $sync);

        $cashLeg = $this->leg($presentation, $shift, $user, 'UZS', 520_000.0, 'cash');
        $cardLeg = $this->leg($presentation, $shift, $user, 'UZS', 270_000.0, 'card');

        [$cashTx, $cardTx] = app(BotPaymentService::class)->recordSplitPayment($cashLeg, $cardLeg);

        $cashRow = CashTransaction::find($cashTx->id);
        $cardRow = CashTransaction::find($cardTx->id);

        $this->assertNotNull($cashRow->journal_entry_id);
        $this->assertSame($cashRow->journal_entry_id, $cardRow->journal_entry_id,
            'both legs of a split must share one journal_entry_id');
        $this->assertSame('split', $cashRow->payment_group_type);
        $this->assertSame('split', $cardRow->payment_group_type);

        // Audit honesty: bypass should record tier=none / withinTolerance=true /
        // variance_pct=0 — NOT pretend the evaluator ran.
        foreach ([$cashRow, $cardRow] as $row) {
            $tierValue = $row->override_tier instanceof \BackedEnum
                ? $row->override_tier->value
                : (string) $row->override_tier;
            $this->assertSame(OverrideTier::None->value, $tierValue);
            $this->assertTrue((bool) $row->within_tolerance);
            $this->assertEquals(0.0, (float) $row->variance_pct);
        }
    }

    // ── Test 4: standalone over-variance still blocks ──────────────

    /** @test */
    public function standalone_over_variance_payment_still_blocks(): void
    {
        // Defensive: the fix must NOT weaken the variance gate for
        // standalone payments. A 50% under-payment with no
        // paymentGroupType MUST still throw PaymentBlockedException.
        [$shift, $user, $booking, $sync] = $this->scenario(invoiceBalanceUsd: 65.0);

        $presentation = $this->presentation($booking, $sync);

        $standalone = new RecordPaymentData(
            presentation:     $presentation,
            shiftId:          $shift->id,
            cashierId:        $user->id,
            currencyPaid:     'UZS',
            amountPaid:       400_000.0, // ≈ 49% of 790k presented — well over 10%
            paymentMethod:    'cash',
            overrideReason:   null,
            managerApproval:  null,
            // paymentGroupType: null  — explicit standalone
        );

        $this->expectException(PaymentBlockedException::class);

        app(BotPaymentService::class)->recordPayment($standalone);
    }

    // ── Test 5: explicit standalone with same amount as a leg blocks
    // (proves the bypass is not triggered by amount, only by context) ─

    /** @test */
    public function standalone_payment_with_same_amount_as_leg_still_blocks(): void
    {
        // Same dollar value as the cash-leg in test #1 (520k UZS), but
        // recorded as a standalone payment: must block. This proves the
        // bypass is keyed on paymentGroupType, NOT on the amount itself.
        [$shift, $user, $booking, $sync] = $this->scenario(invoiceBalanceUsd: 65.0);

        $presentation = $this->presentation($booking, $sync);

        $standalone = new RecordPaymentData(
            presentation:     $presentation,
            shiftId:          $shift->id,
            cashierId:        $user->id,
            currencyPaid:     'UZS',
            amountPaid:       520_000.0, // same as test #1 cash leg
            paymentMethod:    'cash',
            overrideReason:   null,
            managerApproval:  null,
        );

        $this->expectException(PaymentBlockedException::class);

        app(BotPaymentService::class)->recordPayment($standalone);
    }

    // ── Fixture builders ───────────────────────────────────────────

    /**
     * @return array{0: CashierShift, 1: User, 2: Beds24Booking, 3: BookingFxSync}
     */
    private function scenario(float $invoiceBalanceUsd = 65.0): array
    {
        $drawer = CashDrawer::firstOrCreate(['name' => 'Test'], ['is_active' => true]);
        $user   = User::factory()->create();
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $booking = Beds24Booking::create([
            'beds24_booking_id' => 'B-SPLIT-' . uniqid(),
            'property_id'       => '41097',
            'guest_name'        => 'Tatyana Test',
            'arrival_date'      => now()->toDateString(),
            'departure_date'    => now()->addDay()->toDateString(),
            'invoice_balance'   => $invoiceBalanceUsd,
            'total_amount'      => $invoiceBalanceUsd,
            'booking_status'    => 'confirmed',
            'channel'           => 'direct',
        ]);

        $rate = DailyExchangeRate::firstOrCreate(
            ['rate_date' => now()->toDateString()],
            [
                'usd_uzs_rate'           => 12_115.0,
                'eur_uzs_cbu_rate'       => 14_261.0,
                'eur_margin'             => 200.0,
                'eur_effective_rate'     => 14_061.0,
                'rub_uzs_cbu_rate'       => 162.0,
                'rub_margin'             => 20.0,
                'rub_effective_rate'     => 142.0,
                'uzs_rounding_increment' => 1000,
                'eur_rounding_increment' => 1,
                'rub_rounding_increment' => 100,
                'source'                 => 'test',
                'fetched_at'             => now(),
            ],
        );

        $sync = BookingFxSync::create([
            'beds24_booking_id'      => $booking->beds24_booking_id,
            'fx_rate_date'           => now()->toDateString(),
            'daily_exchange_rate_id' => $rate->id,
            'arrival_date_used'      => now()->toDateString(),
            'usd_amount_used'        => $invoiceBalanceUsd,
            'uzs_final'              => 790_000,
            'eur_final'              => 57.0,
            'rub_final'              => 5_600.0,
            'usd_final'              => $invoiceBalanceUsd,
            'push_status'            => 'pending',
        ]);

        return [$shift, $user, $booking, $sync];
    }

    private function presentation(Beds24Booking $booking, BookingFxSync $sync): PaymentPresentation
    {
        return new PaymentPresentation(
            beds24BookingId:     $booking->beds24_booking_id,
            syncId:              $sync->id,
            dailyExchangeRateId: $sync->daily_exchange_rate_id,
            guestName:           $booking->guest_name,
            arrivalDate:         now()->toDateString(),
            uzsPresented:        790_000,
            eurPresented:        57,
            rubPresented:        5_600,
            fxRateDate:          now()->format('d.m.Y'),
            botSessionId:        'test-split-' . uniqid(),
            presentedAt:         Carbon::now(),
            usdPresented:        65,
        );
    }

    private function leg(
        PaymentPresentation $presentation,
        CashierShift $shift,
        User $user,
        string $currency,
        float $amount,
        string $method,
    ): RecordPaymentData {
        return new RecordPaymentData(
            presentation:     $presentation,
            shiftId:          $shift->id,
            cashierId:        $user->id,
            currencyPaid:     $currency,
            amountPaid:       $amount,
            paymentMethod:    $method,
            overrideReason:   null,
            managerApproval:  null,
        );
    }
}
