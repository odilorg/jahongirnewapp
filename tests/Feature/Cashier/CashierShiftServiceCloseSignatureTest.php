<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Enums\Currency;
use App\Enums\OverrideTier;
use App\Enums\ShiftStatus;
use App\Models\CashierShift;
use App\Models\EndSaldo;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * C1.2 — CashierShiftService::closeShift signature extension.
 *
 * Pins backward compatibility (existing 3-arg callers unchanged) and the
 * new behaviors when tier/reason are passed.
 */
final class CashierShiftServiceCloseSignatureTest extends TestCase
{
    use DatabaseTransactions;

    private CashierShiftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CashierShiftService::class);
    }

    public function test_legacy_three_arg_signature_still_works(): void
    {
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::OPEN,
            'opened_at' => now()->subHours(8),
        ]);

        $countData = [
            'counted_uzs' => 1_500_000,
            'counted_usd' => 100,
            'counted_eur' => 45,
            'expected'    => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
        ];

        $handover = $this->service->closeShift($shift->id, $countData);

        $this->assertNotNull($handover);
        $this->assertSame(ShiftStatus::CLOSED, $shift->fresh()->status);
        // No tier set → discrepancy_tier remains null.
        $this->assertNull($shift->fresh()->discrepancy_tier);
        $this->assertNull($shift->fresh()->discrepancy_severity_uzs);
    }

    public function test_tier_and_severity_persist_when_provided(): void
    {
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::OPEN,
            'opened_at' => now()->subHours(8),
        ]);

        $countData = [
            'counted_uzs'  => 1_400_000,
            'counted_usd'  => 100,
            'counted_eur'  => 45,
            'expected'     => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
            'severity_uzs' => 100_000,
        ];

        $this->service->closeShift($shift->id, $countData, '', OverrideTier::Cashier, 'Customer overpaid then underpaid back');

        $fresh = $shift->fresh();
        $this->assertSame(OverrideTier::Cashier, $fresh->discrepancy_tier);
        $this->assertEquals(100_000.0, (float) $fresh->discrepancy_severity_uzs);
    }

    public function test_reason_overrides_default_via_telegram_bot_string(): void
    {
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::OPEN,
            'opened_at' => now()->subHours(8),
        ]);

        $countData = [
            'counted_uzs' => 1_400_000,
            'counted_usd' => 100,
            'counted_eur' => 45,
            'expected'    => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
        ];

        $this->service->closeShift($shift->id, $countData, '', OverrideTier::Cashier, 'Banknote miscounted');

        $endSaldo = EndSaldo::where('cashier_shift_id', $shift->id)
            ->where('currency', Currency::UZS)
            ->first();
        $this->assertSame('Banknote miscounted', $endSaldo->discrepancy_reason);
    }

    public function test_legacy_default_reason_string_preserved_when_no_reason_passed(): void
    {
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::OPEN,
            'opened_at' => now()->subHours(8),
        ]);

        $countData = [
            'counted_uzs' => 1_400_000,
            'counted_usd' => 100,
            'counted_eur' => 45,
            'expected'    => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
        ];

        $this->service->closeShift($shift->id, $countData);

        $endSaldo = EndSaldo::where('cashier_shift_id', $shift->id)
            ->where('currency', Currency::UZS)
            ->first();
        $this->assertSame('Via Telegram bot', $endSaldo->discrepancy_reason);
    }

    public function test_under_review_shift_can_be_closed(): void
    {
        // Critical: ApproveShiftCloseAction calls closeShift on an UNDER_REVIEW
        // shift. The precondition was widened in C1.2 from `status='open'` to
        // `status IN ('open', 'under_review')`.
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::UNDER_REVIEW,
            'opened_at' => now()->subHours(8),
        ]);

        $countData = [
            'counted_uzs' => 1_500_000,
            'counted_usd' => 100,
            'counted_eur' => 45,
            'expected'    => ['UZS' => 1_500_000, 'USD' => 100, 'EUR' => 45],
        ];

        $handover = $this->service->closeShift($shift->id, $countData);

        $this->assertNotNull($handover);
        $this->assertSame(ShiftStatus::CLOSED, $shift->fresh()->status);
    }

    public function test_closed_shift_still_rejected(): void
    {
        // Backward compat: closeShift still throws on already-closed shifts.
        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $countData = [
            'counted_uzs' => 0, 'counted_usd' => 0, 'counted_eur' => 0,
            'expected'    => ['UZS' => 0, 'USD' => 0, 'EUR' => 0],
        ];

        $this->expectException(\RuntimeException::class);
        $this->service->closeShift($shift->id, $countData);
    }
}
