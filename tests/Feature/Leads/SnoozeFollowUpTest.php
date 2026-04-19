<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\CreateFollowUp;
use App\Actions\Leads\SnoozeFollowUp;
use App\Enums\LeadFollowUpStatus;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — snooze guardrails.
 *
 * Both throws protect against ways an operator (or future automation) could
 * quietly remove a follow-up from the due queue without resolving it.
 */
class SnoozeFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-19 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snoozing_non_open_followup_throws(): void
    {
        $lead = Lead::factory()->create();
        $fu = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);
        $fu->update(['status' => LeadFollowUpStatus::Done->value]);

        $this->expectException(LogicException::class);

        app(SnoozeFollowUp::class)->handle($fu->fresh(), now()->addHours(4));
    }

    public function test_snoozing_to_past_datetime_throws(): void
    {
        $lead = Lead::factory()->create();
        $fu = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);

        $this->expectException(InvalidArgumentException::class);

        app(SnoozeFollowUp::class)->handle($fu, now()->subHour());
    }
}
