<?php

namespace Tests\Unit;

use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\OverrideTier;
use App\Exceptions\PaymentBlockedException;
use App\Services\BotPaymentService;
use App\Services\Fx\Beds24PaymentSyncService;
use App\Services\Fx\OverridePolicyEvaluator;
use App\Services\FxManagerApprovalService;
use App\Services\Cashier\GroupAwareCashierAmountResolver;
use App\Services\FxSyncService;
use Tests\TestCase;

/**
 * Verifies that BotPaymentService now injects the canonical FxOverridePolicyEvaluator
 * (App\Services\Fx\OverridePolicyEvaluator) instead of the deprecated one.
 *
 * Bug: the deprecated evaluator (App\Services\OverridePolicyEvaluator) never returned
 * OverrideTier::Blocked. The service-layer PaymentBlockedException would never fire,
 * meaning extreme over/under payments could bypass the block check at the service layer.
 *
 * The controller is the primary gate, but defence-in-depth requires the service to also
 * enforce the Blocked tier so the invariant holds regardless of how the service is called.
 */
class BotPaymentServiceOverrideTest extends TestCase
{
    /**
     * Verify the canonical evaluator is wired in by checking that Blocked tier
     * fires when variance exceeds the manager threshold.
     *
     * We construct the service with a real canonical OverridePolicyEvaluator and a
     * stale PaymentPresentation, then verify PaymentBlockedException is raised
     * before the DB transaction is even attempted.
     */
    /** @test */
    public function record_payment_throws_blocked_exception_when_canonical_evaluator_returns_blocked(): void
    {
        // The canonical evaluator: tolerancePct=0.5, cashierThresholdPct=2, managerThresholdPct=10
        // Passing a variance of 50% must trigger Blocked.
        $evaluator = new OverridePolicyEvaluator();

        // Stub the other services — recordPayment() checks override BEFORE touching DB
        $fxSync     = $this->createMock(FxSyncService::class);
        $approvals  = $this->createMock(FxManagerApprovalService::class);
        $syncSvc    = $this->createMock(Beds24PaymentSyncService::class);

        $groupResolver = $this->createMock(GroupAwareCashierAmountResolver::class);
        $service = new BotPaymentService($fxSync, $evaluator, $approvals, $syncSvc, $groupResolver);

        // Build a presentation showing 1,000,000 UZS; cashier tries to pay 500,000 (50% under)
        $presentation = PaymentPresentation::fromArray([
            'beds24_booking_id' => 'B_BLOCK_TEST',
            'sync_id'           => 1,
            'daily_rate_id'     => null,
            'guest_name'        => 'Test Guest',
            'arrival_date'      => '2026-04-10',
            'uzs_presented'     => 1_000_000,
            'eur_presented'     => 80,
            'rub_presented'     => 8000,
            'fx_rate_date'      => '06.04.2026',
            'bot_session_id'    => 'sess-block',
            'presented_at'      => now()->toIso8601String(),  // fresh — not expired
        ]);

        $data = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         1,
            cashierId:       1,
            currencyPaid:    'UZS',
            amountPaid:      500_000.0, // 50% of presented — far exceeds managerThresholdPct (10%)
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        );

        $this->expectException(PaymentBlockedException::class);

        $service->recordPayment($data);
    }

    /** @test */
    public function record_payment_does_not_throw_blocked_for_amount_within_tolerance(): void
    {
        $evaluator = new OverridePolicyEvaluator();

        $fxSync    = $this->createMock(FxSyncService::class);
        $approvals = $this->createMock(FxManagerApprovalService::class);
        $syncSvc   = $this->createMock(Beds24PaymentSyncService::class);

        $groupResolver = $this->createMock(GroupAwareCashierAmountResolver::class);
        $service = new BotPaymentService($fxSync, $evaluator, $approvals, $syncSvc, $groupResolver);

        $presentation = PaymentPresentation::fromArray([
            'beds24_booking_id' => 'B_WITHIN_TOLERANCE',
            'sync_id'           => 1,
            'daily_rate_id'     => null,
            'guest_name'        => 'Test Guest',
            'arrival_date'      => '2026-04-10',
            'uzs_presented'     => 1_000_000,
            'eur_presented'     => 80,
            'rub_presented'     => 8000,
            'fx_rate_date'      => '06.04.2026',
            'bot_session_id'    => 'sess-tolerance',
            'presented_at'      => now()->toIso8601String(),
        ]);

        $data = new RecordPaymentData(
            presentation:    $presentation,
            shiftId:         1,
            cashierId:       1,
            currencyPaid:    'UZS',
            amountPaid:      1_000_100.0,  // 0.01% variance — within tolerance (< 0.5%)
            paymentMethod:   'cash',
            overrideReason:  null,
            managerApproval: null,
        );

        // Should not throw Blocked — will throw something else (BookingNotPayableException, etc.)
        // because we haven't mocked the DB. The point is no PaymentBlockedException.
        try {
            $service->recordPayment($data);
        } catch (PaymentBlockedException $e) {
            $this->fail('PaymentBlockedException must not be thrown for within-tolerance amounts');
        } catch (\Throwable) {
            // Expected — other exceptions (DB, etc.) are fine here
        }

        $this->addToAssertionCount(1); // test reached here = not blocked
    }

    /** @test */
    public function canonical_evaluator_is_injected_not_deprecated_one(): void
    {
        // Verify the constructor type hint resolves to the canonical class.
        // We do this by constructing via the reflection API and checking the parameter type.
        $reflection = new \ReflectionClass(BotPaymentService::class);
        $constructor = $reflection->getConstructor();

        $overrideParam = collect($constructor->getParameters())
            ->first(fn($p) => $p->getName() === 'overridePolicy');

        $this->assertNotNull($overrideParam, 'overridePolicy parameter must exist');

        $type = $overrideParam->getType();
        $this->assertNotNull($type, 'overridePolicy must be type-hinted');

        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
        $this->assertEquals(
            OverridePolicyEvaluator::class,
            $typeName,
            'BotPaymentService must inject the canonical Fx\OverridePolicyEvaluator, not the deprecated one'
        );
    }
}
