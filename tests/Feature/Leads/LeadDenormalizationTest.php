<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\CompleteFollowUp;
use App\Actions\Leads\CreateFollowUp;
use App\Actions\Leads\LogInteraction;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — denormalization invariants.
 *
 * Protects lead.last_interaction_at and lead.next_followup_at. Both are
 * maintained by observers and power the operator queue view; if they drift
 * from the source rows, nothing visibly breaks but operators stop trusting
 * the "due now" counts.
 */
class LeadDenormalizationTest extends TestCase
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

    public function test_logging_interaction_updates_last_interaction_at(): void
    {
        $lead = Lead::factory()->create(['last_interaction_at' => null]);
        $when = now()->subMinutes(15);

        app(LogInteraction::class)->handle($lead, [
            'channel'     => LeadInteractionChannel::WhatsApp->value,
            'direction'   => LeadInteractionDirection::Inbound->value,
            'body'        => 'test message',
            'occurred_at' => $when,
        ]);

        $this->assertSame(
            $when->toDateTimeString(),
            $lead->fresh()->last_interaction_at->toDateTimeString(),
        );
    }

    public function test_creating_followup_populates_next_followup_at(): void
    {
        $lead = Lead::factory()->create(['next_followup_at' => null]);
        $due = now()->addHour();

        app(CreateFollowUp::class)->handle($lead, ['due_at' => $due]);

        $this->assertSame(
            $due->toDateTimeString(),
            $lead->fresh()->next_followup_at->toDateTimeString(),
        );
    }

    public function test_completing_only_followup_clears_next_followup_at(): void
    {
        $lead = Lead::factory()->create();
        $fu = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);

        $this->assertNotNull($lead->fresh()->next_followup_at);

        app(CompleteFollowUp::class)->handle($fu);

        $this->assertNull($lead->fresh()->next_followup_at);
    }

    public function test_deleting_followup_recomputes_next_followup_at(): void
    {
        $lead = Lead::factory()->create();
        $fu = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);

        $fu->delete();

        $this->assertNull($lead->fresh()->next_followup_at);
    }

    public function test_active_snooze_pushes_next_followup_at_to_snoozed_until(): void
    {
        $lead = Lead::factory()->create();
        $fu = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);

        $snoozedUntil = now()->addHours(5);
        $fu->update(['snoozed_until' => $snoozedUntil]);

        $this->assertSame(
            $snoozedUntil->toDateTimeString(),
            $lead->fresh()->next_followup_at->toDateTimeString(),
        );
    }

    // Edge case: earlier-but-snoozed vs later-but-active — observer must pick
    // the earlier effective time, which is the later one's plain due_at.
    public function test_snoozed_earlier_followup_does_not_win_over_active_later_one(): void
    {
        $lead = Lead::factory()->create();

        // FU1: due in 1h, snoozed to +5h (effective = 5h from now)
        app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()])
            ->update(['snoozed_until' => now()->addHours(5)]);

        // FU2: due in 2h, not snoozed (effective = 2h from now)
        app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHours(2)]);

        $this->assertSame(
            now()->addHours(2)->toDateTimeString(),
            $lead->fresh()->next_followup_at->toDateTimeString(),
        );
    }

    // Edge case: multiple open followups — completing the soonest must shift
    // lead.next_followup_at to the next one, not null it out.
    public function test_completing_one_of_many_followups_advances_next_followup_at(): void
    {
        $lead = Lead::factory()->create();
        $soon = app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHour()]);
        app(CreateFollowUp::class)->handle($lead, ['due_at' => now()->addHours(3)]);

        $this->assertSame(
            now()->addHour()->toDateTimeString(),
            $lead->fresh()->next_followup_at->toDateTimeString(),
        );

        app(CompleteFollowUp::class)->handle($soon);

        $this->assertSame(
            now()->addHours(3)->toDateTimeString(),
            $lead->fresh()->next_followup_at->toDateTimeString(),
        );
    }
}
