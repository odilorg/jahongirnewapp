<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\TransitionLeadStatus;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadStatus;
use App\Exceptions\Leads\InvalidLeadStatusTransition;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — state machine correctness.
 *
 * The timeline depends on every real transition producing an internal-note
 * LeadInteraction for audit. Idempotent no-op transitions must not pollute
 * that timeline.
 */
class TransitionLeadStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_transition_updates_status(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        $result = app(TransitionLeadStatus::class)->handle($lead, LeadStatus::Contacted);

        $this->assertSame(LeadStatus::Contacted, $result->status);
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_invalid_transition_throws(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        $this->expectException(InvalidLeadStatusTransition::class);

        // new -> quoted skips the pipeline.
        app(TransitionLeadStatus::class)->handle($lead, LeadStatus::Quoted);
    }

    public function test_same_status_transition_is_idempotent_and_logs_nothing(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Qualified->value]);
        $this->assertSame(0, $lead->interactions()->count());

        $result = app(TransitionLeadStatus::class)->handle($lead, LeadStatus::Qualified);

        $this->assertSame(LeadStatus::Qualified, $result->status);
        $this->assertSame(0, $lead->fresh()->interactions()->count());
    }

    public function test_real_transition_writes_an_internal_note_interaction(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Quoted->value]);

        app(TransitionLeadStatus::class)->handle(
            $lead,
            LeadStatus::WaitingGuest,
            'Waiting for guest to confirm dates.',
        );

        $note = $lead->fresh()->interactions()->latest('id')->first();

        $this->assertNotNull($note);
        $this->assertSame(LeadInteractionChannel::InternalNote, $note->channel);
        $this->assertSame(LeadInteractionDirection::Internal, $note->direction);
        $this->assertStringContainsString('Status: quoted → waiting_guest', $note->body);
        $this->assertStringContainsString('Waiting for guest to confirm dates.', $note->body);
    }
}
