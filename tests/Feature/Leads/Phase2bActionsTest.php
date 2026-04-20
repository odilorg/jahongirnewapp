<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\SetLeadPriority;
use App\Actions\Leads\TransitionLeadStatus;
use App\Actions\Leads\UpdateLeadContact;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead CRM Phase 2b — new inline update actions.
 *
 * Only covers the domain actions themselves. UI wiring (Filament modals
 * and action groups) is trivial on top of these and doesn't need its own
 * suite at this stage.
 */
class Phase2bActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_lead_priority_updates_when_different(): void
    {
        $lead = Lead::factory()->create(['priority' => LeadPriority::Medium->value]);

        $result = app(SetLeadPriority::class)->handle($lead, LeadPriority::Urgent);

        $this->assertSame(LeadPriority::Urgent, $result->priority);
        $this->assertSame(LeadPriority::Urgent, $lead->fresh()->priority);
    }

    public function test_set_lead_priority_noop_when_same(): void
    {
        $lead = Lead::factory()->create(['priority' => LeadPriority::Low->value]);
        $originalUpdatedAt = $lead->updated_at;

        sleep(1);
        app(SetLeadPriority::class)->handle($lead, LeadPriority::Low);

        $this->assertSame(
            $originalUpdatedAt->toDateTimeString(),
            $lead->fresh()->updated_at->toDateTimeString(),
        );
    }

    public function test_update_lead_contact_only_touches_whitelisted_fields(): void
    {
        $lead = Lead::factory()->create([
            'name'     => 'Original',
            'phone'    => '+998901111111',
            'priority' => LeadPriority::High->value,
            'status'   => LeadStatus::Contacted->value,
        ]);

        app(UpdateLeadContact::class)->handle($lead, [
            'name'   => 'Renamed',
            'phone'  => '+998902222222',
            'status' => LeadStatus::Converted->value,  // should be ignored
            'priority' => LeadPriority::Urgent->value, // should be ignored
        ]);

        $fresh = $lead->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('+998902222222', $fresh->phone);
        $this->assertSame(LeadStatus::Contacted, $fresh->status, 'status must not be mutated by UpdateLeadContact');
        $this->assertSame(LeadPriority::High, $fresh->priority, 'priority must not be mutated by UpdateLeadContact');
    }

    public function test_transition_lead_status_exposes_allowed_transitions_for_ui(): void
    {
        $fromNew = TransitionLeadStatus::allowedTransitionsFrom(LeadStatus::New);
        $this->assertContains(LeadStatus::Contacted, $fromNew);
        $this->assertContains(LeadStatus::Lost, $fromNew);
        $this->assertNotContains(LeadStatus::Quoted, $fromNew);

        $fromConverted = TransitionLeadStatus::allowedTransitionsFrom(LeadStatus::Converted);
        $this->assertSame([], $fromConverted, 'terminal state must expose zero transitions');
    }
}
