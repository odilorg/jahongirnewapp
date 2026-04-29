<?php

declare(strict_types=1);

namespace Tests\Feature\Fx;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\Beds24SyncStatus;
use App\Models\Beds24Booking;
use App\Models\Beds24PaymentSync;
use App\Models\BookingFxSync;
use App\Models\CashDrawer;
use App\Models\DailyExchangeRate;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\BotPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * fix/cashier-bot-allow-usd-collection — feature regression for the
 * USD-collection path that was previously blocked.
 *
 * Verifies the full BotPaymentService::recordPayment flow with
 * currencyPaid='USD', and that the resulting CashTransaction +
 * Beds24PaymentSync rows have the right shape.
 */
final class UsdCollectedPaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cashier.fx.override_reason_required_pct' => 3.0,
            'cashier.fx.hard_block_pct'               => 15.0,
            'features.beds24_auto_push_payment'       => false, // don't dispatch real job from tests
        ]);
    }

    public function test_usd_collected_payment_records_cash_transaction_with_currency_usd(): void
    {
        [$shift, $user, $booking, $sync] = $this->scenario();

        $tx = $this->record($shift, $user, $booking, $sync, currency: 'USD', amount: 67.0);

        $this->assertSame('USD', $tx->currency instanceof \BackedEnum ? $tx->currency->value : (string) $tx->currency,
            'cash_transaction.currency must be USD when collected in USD');
        $this->assertEquals(67.0, (float) $tx->amount);
        $this->assertSame((string) $booking->beds24_booking_id, (string) $tx->beds24_booking_id);
        $this->assertSame('cash', $tx->payment_method);
    }

    public function test_usd_collected_payment_writes_one_to_one_usd_equivalent(): void
    {
        [$shift, $user, $booking, $sync] = $this->scenario();

        $tx = $this->record($shift, $user, $booking, $sync, currency: 'USD', amount: 67.0);

        $this->assertEquals(67.0, (float) $tx->usd_equivalent_paid,
            'USD-collected payment usd_equivalent_paid is 1:1 with amount (no FX conversion)');
    }

    public function test_usd_collected_payment_creates_beds24_payment_sync_with_amount_usd(): void
    {
        [$shift, $user, $booking, $sync] = $this->scenario();

        $tx = $this->record($shift, $user, $booking, $sync, currency: 'USD', amount: 67.0);

        $syncRow = Beds24PaymentSync::where('cash_transaction_id', $tx->id)->first();
        $this->assertNotNull($syncRow, 'Beds24PaymentSync row must be created for USD payment');
        $this->assertEquals(67.0, (float) $syncRow->amount_usd,
            'Beds24 sync amount_usd must equal collected USD amount (no conversion)');
        $this->assertSame(Beds24SyncStatus::Pending, $syncRow->status);
    }

    public function test_usd_collected_payment_dual_write_columns_left_null(): void
    {
        // FX Phase 1 dual-write only fires for UZS-collected payments
        // (BotPaymentService::dualWriteSimpleFxFields early-returns on
        // non-UZS currencies). USD-collected payments leave the simple
        // FX columns NULL — documented Phase 1 limitation; not a bug.
        [$shift, $user, $booking, $sync] = $this->scenario();

        $tx = $this->record($shift, $user, $booking, $sync, currency: 'USD', amount: 67.0);

        $this->assertNull($tx->reference_rate);
        $this->assertNull($tx->actual_rate);
        $this->assertNull($tx->deviation_pct);
        $this->assertFalse((bool) $tx->was_overridden);
    }

    public function test_existing_uzs_collected_payment_still_works_unchanged(): void
    {
        // Backward compat — make sure adding USD didn't affect UZS path.
        [$shift, $user, $booking, $sync] = $this->scenario();

        $tx = $this->record($shift, $user, $booking, $sync, currency: 'UZS', amount: 820_000);

        $this->assertSame('UZS', $tx->currency instanceof \BackedEnum ? $tx->currency->value : (string) $tx->currency);
        $this->assertEquals(820_000.0, (float) $tx->amount);
        // UZS path DOES populate dual-write columns
        $this->assertNotNull($tx->reference_rate);
    }

    /**
     * @return array{0: CashierShift, 1: User, 2: Beds24Booking, 3: BookingFxSync}
     */
    private function scenario(): array
    {
        $drawer = CashDrawer::create(['name' => 'Test', 'is_active' => true]);
        $user   = User::factory()->create();
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $booking = Beds24Booking::create([
            'beds24_booking_id' => 'B-USD-' . uniqid(),
            'property_id'       => '41097',
            'guest_name'        => 'John Doe',
            'arrival_date'      => now()->toDateString(),
            'departure_date'    => now()->addDay()->toDateString(),
            'invoice_balance'   => 67.0,
            'total_amount'      => 67.0,
            'booking_status'    => 'confirmed',
            'channel'           => 'direct',
        ]);

        $rate = DailyExchangeRate::firstOrCreate(
            ['rate_date' => now()->toDateString()],
            [
                'usd_uzs_rate'           => 12_700.0,
                'eur_uzs_cbu_rate'       => 14_000.0,
                'eur_margin'             => 200.0,
                'eur_effective_rate'     => 13_800.0,
                'rub_uzs_cbu_rate'       => 160.0,
                'rub_margin'             => 20.0,
                'rub_effective_rate'     => 140.0,
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
            'usd_amount_used'        => 67.0,
            'uzs_final'              => 820_000,
            'eur_final'              => 60.0,
            'rub_final'              => 6_200.0,
            'usd_final'              => 67.0,
            'push_status'            => 'pending',
        ]);

        return [$shift, $user, $booking, $sync];
    }

    private function record(
        CashierShift $shift,
        User $user,
        Beds24Booking $booking,
        BookingFxSync $sync,
        string $currency,
        float $amount,
    ): CashTransaction {
        $presentation = new PaymentPresentation(
            beds24BookingId:     $booking->beds24_booking_id,
            syncId:              $sync->id,
            dailyExchangeRateId: $sync->daily_exchange_rate_id,
            guestName:           'John Doe',
            arrivalDate:         now()->toDateString(),
            uzsPresented:        820_000,
            eurPresented:        60,
            rubPresented:        6_200,
            fxRateDate:          now()->format('d.m.Y'),
            botSessionId:        'test-sess-' . uniqid(),
            presentedAt:         Carbon::now(),
            usdPresented:        67,
        );

        return app(BotPaymentService::class)->recordPayment(new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         $shift->id,
            cashierId:       $user->id,
            currencyPaid:    $currency,
            amountPaid:      $amount,
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        ));
    }
}
