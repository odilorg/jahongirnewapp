<?php

namespace Tests\Feature\Fx;

use App\DTOs\Fx\PaymentPresentation;
use App\DTOs\Fx\RecordPaymentData;
use App\Enums\Currency;
use App\Exceptions\DuplicatePaymentException;
use App\Models\BookingFxSync;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\Fx\Beds24PaymentSyncService;
use App\Services\Fx\BotPaymentService;
use App\Services\Fx\FxManagerApprovalService;
use App\Services\Fx\FxSyncService;
use App\Services\Fx\OverridePolicyEvaluator;
use App\Services\Fx\SettlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Duplicate-payment guard tests for App\Services\Fx\BotPaymentService.
 *
 * Scenarios:
 *  (A) First payment succeeds, second attempt throws DuplicatePaymentException
 *  (B) Exactly one CashTransaction exists after a blocked duplicate attempt
 *  (C) Different bookings do not interfere — guard is per-booking scoped
 */
class BotPaymentDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['features.beds24_auto_push_payment' => false]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeService(): BotPaymentService
    {
        $syncSvc = $this->createMock(Beds24PaymentSyncService::class);
        $syncRow = new \App\Models\Beds24PaymentSync([
            'local_reference' => \Illuminate\Support\Str::uuid()->toString(),
        ]);
        $syncSvc->method('createPending')->willReturn($syncRow);

        return new BotPaymentService(
            fxSync:          $this->createMock(FxSyncService::class),
            settlement:      $this->createMock(SettlementCalculator::class),
            overridePolicy:  new OverridePolicyEvaluator(),
            approvalService: $this->createMock(FxManagerApprovalService::class),
            syncService:     $syncSvc,
        );
    }

    private function makeFxSync(string $bookingId): BookingFxSync
    {
        return BookingFxSync::updateOrCreate(
            ['beds24_booking_id' => $bookingId],
            [
                'usd_final'         => 100.0,
                'uzs_final'         => 1_280_000,
                'eur_final'         => 100.0,
                'rub_final'         => 9000.0,
                'usd_amount_used'   => 100.0,
                'fx_rate_date'      => today(),
                'arrival_date_used' => today(),
                'push_status'       => 'pushed',
                'fx_last_pushed_at' => now(),
            ]
        );
    }

    private function makeRecordData(string $bookingId, int $shiftId, int $cashierId): RecordPaymentData
    {
        $presentation = new PaymentPresentation(
            beds24BookingId:  $bookingId,
            guestName:        'Test Guest',
            roomNumber:       '17',
            uzsAmount:        1_280_000,
            eurAmount:        100.0,
            rubAmount:        9000.0,
            usdAmount:        100.0,
            usdBookingAmount: 100.0,
            exchangeRateId:   null,
            rateDate:         now()->subMinutes(5),
            preparedAt:       now()->subMinutes(1), // fresh — not stale
            isPrinted:        false,
        );

        return new RecordPaymentData(
            beds24BookingId: $bookingId,
            paidCurrency:    Currency::UZS,
            paidAmount:      1_280_000,
            paymentMethod:   'cash',
            botSessionId:    'test-session-' . $bookingId,
            cashierShiftId:  $shiftId,
            createdBy:       $cashierId,
            presentation:    $presentation,
        );
    }

    // ── (A) Duplicate blocked ────────────────────────────────────────────────

    /** @test */
    public function second_payment_attempt_for_same_booking_throws_duplicate_exception(): void
    {
        $shift   = CashierShift::factory()->create(['status' => 'open', 'opened_at' => now()]);
        $cashier = User::factory()->create();
        $this->makeFxSync('FX_DUP_A');

        $service = $this->makeService();
        $data    = $this->makeRecordData('FX_DUP_A', $shift->id, $cashier->id);

        // First payment must succeed
        $service->recordPayment($data);

        // Second attempt must be blocked
        $this->expectException(DuplicatePaymentException::class);
        $service->recordPayment($data);
    }

    // ── (B) Exactly one CashTransaction after blocked duplicate ──────────────

    /** @test */
    public function exactly_one_transaction_exists_after_blocked_duplicate(): void
    {
        $shift   = CashierShift::factory()->create(['status' => 'open', 'opened_at' => now()]);
        $cashier = User::factory()->create();
        $this->makeFxSync('FX_DUP_B');

        $service = $this->makeService();
        $data    = $this->makeRecordData('FX_DUP_B', $shift->id, $cashier->id);

        $service->recordPayment($data);

        try {
            $service->recordPayment($data);
        } catch (DuplicatePaymentException) {
            // expected
        }

        $this->assertSame(
            1,
            \App\Models\CashTransaction::where('beds24_booking_id', 'FX_DUP_B')->count(),
            'Duplicate attempt must not create a second CashTransaction'
        );
    }

    // ── (C) Guard is per-booking scoped — different bookings independent ──────

    /** @test */
    public function duplicate_guard_does_not_interfere_with_different_booking(): void
    {
        $shift   = CashierShift::factory()->create(['status' => 'open', 'opened_at' => now()]);
        $cashier = User::factory()->create();
        $this->makeFxSync('FX_SCOPE_1');
        $this->makeFxSync('FX_SCOPE_2');

        $service = $this->makeService();

        // Pay booking 1 first
        $service->recordPayment($this->makeRecordData('FX_SCOPE_1', $shift->id, $cashier->id));

        // Booking 2 must succeed independently — guard for booking 1 must not block it
        $tx2 = $service->recordPayment($this->makeRecordData('FX_SCOPE_2', $shift->id, $cashier->id));

        $this->assertNotNull($tx2->id);
        $this->assertSame('FX_SCOPE_2', $tx2->beds24_booking_id);
    }
}
