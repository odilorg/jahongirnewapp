<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\CashierBot\Handlers\RequestShiftCloseApprovalAction;
use App\DTOs\Cashier\ShiftCloseEvaluation;
use App\Enums\OverrideTier;
use App\Enums\ShiftStatus;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\CashierShift;
use App\Models\User;
use App\Services\OwnerAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * C1.2 — RequestShiftCloseApprovalAction.
 *
 * Verifies the OPEN → UNDER_REVIEW transition + owner-alert dispatch.
 * No bot wiring; the action is invoked directly.
 */
final class RequestShiftCloseApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Owner alert needs an owner chat configured; otherwise the alert
        // is silently skipped and the dispatch assertion fails for the
        // wrong reason.
        config(['services.owner_alert_bot.owner_chat_id' => 999_888_777]);
    }

    public function test_it_sets_status_to_under_review_and_persists_tier_severity(): void
    {
        Bus::fake();

        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::OPEN,
            'opened_at' => now()->subHours(8),
        ]);

        $eval = $this->managerEval(severity: 250_000.0);

        $action = $this->app->make(RequestShiftCloseApprovalAction::class);
        $result = $action->execute($shift->id, $eval);

        $this->assertSame(ShiftStatus::UNDER_REVIEW, $result->status);
        $this->assertSame(OverrideTier::Manager, $result->discrepancy_tier);
        $this->assertEquals(250_000.0, (float) $result->discrepancy_severity_uzs);
    }

    public function test_it_dispatches_owner_alert_with_inline_keyboard(): void
    {
        Bus::fake();

        $shift = CashierShift::factory()->create(['status' => ShiftStatus::OPEN]);
        $eval  = $this->managerEval(severity: 250_000.0);

        $this->app->make(RequestShiftCloseApprovalAction::class)
            ->execute($shift->id, $eval);

        Bus::assertDispatched(SendTelegramNotificationJob::class, function ($job) use ($shift) {
            $payload = $this->payloadOf($job);
            $kb = json_decode($payload['reply_markup'] ?? '{}', true);
            $buttons = $kb['inline_keyboard'][0] ?? [];

            return $payload['chat_id'] === 999_888_777
                && str_contains($payload['text'] ?? '', 'требует одобрения')
                && ($buttons[0]['callback_data'] ?? '') === "approve_shift_{$shift->id}"
                && ($buttons[1]['callback_data'] ?? '') === "reject_shift_{$shift->id}";
        });
    }

    public function test_it_is_idempotent_when_already_under_review(): void
    {
        Bus::fake();

        $shift = CashierShift::factory()->create([
            'status'                   => ShiftStatus::UNDER_REVIEW,
            'discrepancy_tier'         => OverrideTier::Manager,
            'discrepancy_severity_uzs' => 250_000,
        ]);

        $result = $this->app->make(RequestShiftCloseApprovalAction::class)
            ->execute($shift->id, $this->managerEval(severity: 250_000.0));

        $this->assertSame(ShiftStatus::UNDER_REVIEW, $result->status);
        // Idempotent: no second alert dispatched.
        Bus::assertNotDispatched(SendTelegramNotificationJob::class);
    }

    public function test_it_throws_when_shift_already_closed(): void
    {
        Bus::fake();

        $shift = CashierShift::factory()->create([
            'status'    => ShiftStatus::CLOSED,
            'closed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->app->make(RequestShiftCloseApprovalAction::class)
            ->execute($shift->id, $this->managerEval(severity: 250_000.0));
    }

    public function test_it_throws_on_missing_shift(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->app->make(RequestShiftCloseApprovalAction::class)
            ->execute(999_999_999, $this->managerEval(severity: 250_000.0));
    }

    private function managerEval(float $severity): ShiftCloseEvaluation
    {
        return new ShiftCloseEvaluation(
            tier: OverrideTier::Manager,
            severityUzs: $severity,
            perCurrencyBreakdown: [
                'UZS' => ['delta' => -250_000.0, 'rate' => 1.0,    'uzs_equiv' => 250_000.0],
                'USD' => ['delta' =>        0.0, 'rate' => 12700.0, 'uzs_equiv' =>       0.0],
                'EUR' => ['delta' =>        0.0, 'rate' => 13800.0, 'uzs_equiv' =>       0.0],
            ],
            fxStale: false,
        );
    }

    /**
     * Pull the payload that was passed to SendTelegramNotificationJob via
     * Bus::dispatch(). Implementation-detail of how the job stores its args.
     */
    private function payloadOf(SendTelegramNotificationJob $job): array
    {
        // Inspect protected props via reflection — keeps test independent of
        // job's public API drifting.
        $r = new \ReflectionObject($job);
        foreach (['payload', 'data', 'params'] as $candidate) {
            if ($r->hasProperty($candidate)) {
                $p = $r->getProperty($candidate);
                $p->setAccessible(true);
                return (array) $p->getValue($job);
            }
        }
        return [];
    }
}
