<?php

namespace Tests\Feature\CashierBot;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Exceptions\DuplicateGroupPaymentException;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\BotPaymentService;
use App\Services\Cashier\GroupAwareCashierAmountResolver;
use App\Services\Fx\Beds24PaymentSyncService;
use App\Services\Fx\OverridePolicyEvaluator;
use App\Services\FxManagerApprovalService;
use App\Services\FxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the group booking payment path.
 *
 * Scenarios:
 *  (A) Standalone booking — group fields null on CashTransaction
 *  (B) Group booking — group audit metadata written to CashTransaction
 *  (C) Duplicate group payment guard — second attempt throws DuplicateGroupPaymentException
 *  (D) PaymentPresentation group fields round-trip through toArray/fromArray
 *  (E) Old session (no group keys) defaults to standalone
 */
class GroupPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function openShift(): CashierShift
    {
        $user = User::factory()->create();
        return CashierShift::factory()->create([
            'user_id'   => $user->id,
            'status'    => 'open',
            'opened_at' => now(),
        ]);
    }

    private function makePresentation(array $overrides = []): PaymentPresentation
    {
        return PaymentPresentation::fromArray(array_merge([
            'beds24_booking_id' => 'TEST_B1',
            'sync_id'           => 1,
            'daily_rate_id'     => null,
            'guest_name'        => 'Ivan Ivanov',
            'arrival_date'      => '2026-05-01',
            'uzs_presented'     => 1_280_000,
            'eur_presented'     => 100,
            'rub_presented'     => 9000,
            'fx_rate_date'      => '06.04.2026',
            'bot_session_id'    => 'sess-group-test',
            'presented_at'      => now()->toIso8601String(),
        ], $overrides));
    }

    private function makeBotPaymentService(): BotPaymentService
    {
        $fxSync    = $this->createMock(FxSyncService::class);
        $evaluator = new OverridePolicyEvaluator();
        $approvals = $this->createMock(FxManagerApprovalService::class);

        // syncSvc: createPending returns a minimal Beds24PaymentSync stub (not persisted)
        $syncRow = new \App\Models\Beds24PaymentSync([
            'beds24_booking_id' => 'TEST',
            'local_reference'   => \Illuminate\Support\Str::uuid()->toString(),
            'amount_usd'        => 100.0,
            'status'            => 'pending',
        ]);
        $syncRow->id = 999;  // fake PK so ->id isn't null

        $syncSvc = $this->createMock(Beds24PaymentSyncService::class);
        $syncSvc->method('createPending')->willReturn($syncRow);

        $groupResolver = $this->createMock(GroupAwareCashierAmountResolver::class);

        return new BotPaymentService($fxSync, $evaluator, $approvals, $syncSvc, $groupResolver);
    }

    private function makeBookingRow(string $bookingId, ?string $masterBookingId = null): Beds24Booking
    {
        return Beds24Booking::factory()->create([
            'beds24_booking_id'  => $bookingId,
            'booking_status'     => 'confirmed',
            'total_amount'       => 100.00,
            'invoice_balance'    => 0,
            'master_booking_id'  => $masterBookingId,
            'booking_group_size' => $masterBookingId ? 2 : null,
        ]);
    }

    private function makeFxSyncRow(string $bookingId, int $syncId): BookingFxSync
    {
        return BookingFxSync::updateOrCreate(
            ['beds24_booking_id' => $bookingId],
            [
                'usd_final'          => 100.0,
                'uzs_final'          => 1_280_000,
                'eur_final'          => 100,
                'rub_final'          => 9000,
                'usd_amount_used'    => 100.0,
                'fx_rate_date'       => today(),
                'arrival_date_used'  => today(),
                'push_status'        => 'pushed',
                'fx_last_pushed_at'  => now(),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // (A) Standalone — group columns null on CashTransaction
    // -------------------------------------------------------------------------

    /** @test */
    public function standalone_payment_writes_null_group_columns(): void
    {
        $shift = $this->openShift();
        $cashier = User::factory()->create();
        $this->makeBookingRow('SOLO_1');

        // Insert FX sync row directly (avoids factory dependency)
        $this->makeFxSyncRow('SOLO_1', 99);

        $service = $this->makeBotPaymentService();
        $presentation = $this->makePresentation([
            'beds24_booking_id' => 'SOLO_1',
            'sync_id'           => 99,
            'is_group_payment'  => false,
        ]);

        $data = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         $shift->id,
            cashierId:       $cashier->id,
            currencyPaid:    'UZS',
            amountPaid:      1_280_000,
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        );

        $tx = $service->recordPayment($data);

        $this->assertFalse((bool) $tx->is_group_payment);
        $this->assertNull($tx->group_master_booking_id);
        $this->assertNull($tx->group_size_expected);
        $this->assertNull($tx->group_size_local);
    }

    // -------------------------------------------------------------------------
    // (B) Group payment — audit metadata written to CashTransaction
    // -------------------------------------------------------------------------

    /** @test */
    public function group_payment_writes_group_audit_columns_to_cash_transaction(): void
    {
        $shift   = $this->openShift();
        $cashier = User::factory()->create();
        $master  = 'GRP_MASTER_AUDIT';

        $this->makeBookingRow('GRP_B1', $master);
        $this->makeFxSyncRow('GRP_B1', 98);

        $service = $this->makeBotPaymentService();

        $presentation = $this->makePresentation([
            'beds24_booking_id'       => 'GRP_B1',
            'sync_id'                 => 98,
            'is_group_payment'        => true,
            'group_master_booking_id' => $master,
            'group_size_expected'     => 3,
            'group_size_local'        => 3,
        ]);

        $data = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         $shift->id,
            cashierId:       $cashier->id,
            currencyPaid:    'UZS',
            amountPaid:      1_280_000,
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        );

        $tx = $service->recordPayment($data);

        $this->assertTrue((bool) $tx->is_group_payment);
        $this->assertEquals($master, $tx->group_master_booking_id);
        $this->assertEquals(3, $tx->group_size_expected);
        $this->assertEquals(3, $tx->group_size_local);
    }

    // -------------------------------------------------------------------------
    // (C) Duplicate group payment guard
    // -------------------------------------------------------------------------

    /** @test */
    public function duplicate_group_payment_is_rejected(): void
    {
        $shift   = $this->openShift();
        $cashier = User::factory()->create();
        $master  = 'GRP_DUPLICATE_MASTER';

        $this->makeBookingRow('GRP_D1', $master);
        $this->makeBookingRow('GRP_D2', $master);
        $this->makeFxSyncRow('GRP_D1', 97);
        $this->makeFxSyncRow('GRP_D2', 96);

        $service = $this->makeBotPaymentService();

        $makeData = fn(string $bookingId, int $syncId) => new RecordPaymentData(
            presentation: $this->makePresentation([
                'beds24_booking_id'       => $bookingId,
                'sync_id'                 => $syncId,
                'is_group_payment'        => true,
                'group_master_booking_id' => $master,
                'group_size_expected'     => 2,
                'group_size_local'        => 2,
            ]),
            shiftId:         $shift->id,
            cashierId:       $cashier->id,
            currencyPaid:    'UZS',
            amountPaid:      1_280_000,
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        );

        // First payment succeeds
        $service->recordPayment($makeData('GRP_D1', 97));

        // Second attempt (different sibling) must be blocked
        $this->expectException(DuplicateGroupPaymentException::class);
        $service->recordPayment($makeData('GRP_D2', 96));
    }

    // -------------------------------------------------------------------------
    // (D) PaymentPresentation round-trip (no DB needed)
    // -------------------------------------------------------------------------

    /** @test */
    public function payment_presentation_group_fields_survive_serialization_round_trip(): void
    {
        $p = $this->makePresentation([
            'is_group_payment'        => true,
            'group_master_booking_id' => 'MASTER_999',
            'group_size_expected'     => 4,
            'group_size_local'        => 4,
        ]);

        $restored = PaymentPresentation::fromArray($p->toArray());

        $this->assertTrue($restored->isGroupPayment);
        $this->assertEquals('MASTER_999', $restored->groupMasterBookingId);
        $this->assertEquals(4, $restored->groupSizeExpected);
        $this->assertEquals(4, $restored->groupSizeLocal);
    }

    // -------------------------------------------------------------------------
    // (E) Old session — group keys absent → standalone defaults
    // -------------------------------------------------------------------------

    /** @test */
    public function payment_presentation_from_old_session_defaults_to_standalone(): void
    {
        $oldData = [
            'beds24_booking_id' => 'OLD_SESSION',
            'sync_id'           => 1,
            'daily_rate_id'     => null,
            'guest_name'        => 'Legacy Guest',
            'arrival_date'      => '2026-01-01',
            'uzs_presented'     => 500_000,
            'eur_presented'     => 40,
            'rub_presented'     => 4000,
            'fx_rate_date'      => '01.01.2026',
            'bot_session_id'    => 'old-sess',
            'presented_at'      => now()->toIso8601String(),
            // No group fields — simulates pre-feature session data
        ];

        $p = PaymentPresentation::fromArray($oldData);

        $this->assertFalse($p->isGroupPayment);
        $this->assertNull($p->groupMasterBookingId);
        $this->assertNull($p->groupSizeExpected);
        $this->assertNull($p->groupSizeLocal);
    }
}
