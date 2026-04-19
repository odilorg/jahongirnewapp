<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadStatus;
use App\Filament\Pages\FollowUpQueuePage;
use App\Filament\Pages\FollowUpQueue\DueTodayFollowUpsTable;
use App\Filament\Pages\FollowUpQueue\LeadsWithoutFollowUpTable;
use App\Filament\Pages\FollowUpQueue\OverdueFollowUpsTable;
use App\Filament\Pages\FollowUpQueue\UpcomingFollowUpsTable;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lead CRM Phase 2a — follow-up queue sectioning correctness.
 *
 * Every section must agree on what "effective due" means (snooze wins if
 * later than due_at). Tests guard against drift between the four sections.
 */
class FollowUpQueuePageTest extends TestCase
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

    public function test_overdue_section_shows_past_due_followups(): void
    {
        $lead = Lead::factory()->create();
        $overdue = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(2),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
        $snoozedForward = LeadFollowUp::create([
            'lead_id'       => $lead->id,
            'due_at'        => now()->subHours(2),
            'snoozed_until' => now()->addHours(3),
            'status'        => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(OverdueFollowUpsTable::class)
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$snoozedForward]);
    }

    public function test_due_today_section_uses_effective_due_consistently(): void
    {
        $lead = Lead::factory()->create();

        // Due in 3 hours, not snoozed — belongs to today.
        $dueToday = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addHours(3),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        // Due in 3 hours but snoozed into tomorrow — must NOT appear in today.
        $snoozedTomorrow = LeadFollowUp::create([
            'lead_id'       => $lead->id,
            'due_at'        => now()->addHours(3),
            'snoozed_until' => now()->addDay()->addHours(2),
            'status'        => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(DueTodayFollowUpsTable::class)
            ->assertCanSeeTableRecords([$dueToday])
            ->assertCanNotSeeTableRecords([$snoozedTomorrow]);
    }

    public function test_upcoming_section_excludes_today_and_beyond_7_days(): void
    {
        $lead = Lead::factory()->create();

        $today = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addHours(5),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
        $day3 = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addDays(3),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
        $day10 = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addDays(10),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(UpcomingFollowUpsTable::class)
            ->assertCanSeeTableRecords([$day3])
            ->assertCanNotSeeTableRecords([$today, $day10]);
    }

    public function test_leads_without_followup_section_includes_active_only(): void
    {
        $neverTouched = Lead::factory()->create([
            'status' => LeadStatus::Qualified->value,
            'name'   => 'Never Touched',
        ]);

        $lapsedLead = Lead::factory()->create([
            'status' => LeadStatus::Contacted->value,
            'name'   => 'Lapsed Lead',
        ]);
        LeadFollowUp::create([
            'lead_id'      => $lapsedLead->id,
            'due_at'       => now()->subWeek(),
            'status'       => LeadFollowUpStatus::Done->value,
            'completed_at' => now()->subWeek(),
        ]);

        $lostLead = Lead::factory()->create(['status' => LeadStatus::Lost->value]);
        $convertedLead = Lead::factory()->create(['status' => LeadStatus::Converted->value]);

        $withOpenFU = Lead::factory()->create();
        LeadFollowUp::create([
            'lead_id' => $withOpenFU->id,
            'due_at'  => now()->addHour(),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(LeadsWithoutFollowUpTable::class)
            ->assertCanSeeTableRecords([$neverTouched, $lapsedLead])
            ->assertCanNotSeeTableRecords([$lostLead, $convertedLead, $withOpenFU]);
    }

    public function test_done_action_removes_followup_from_overdue_section(): void
    {
        $lead = Lead::factory()->create();
        $fu = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(2),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(OverdueFollowUpsTable::class)
            ->assertCanSeeTableRecords([$fu])
            ->callTableAction('done', $fu)
            ->assertCanNotSeeTableRecords([$fu]);

        $this->assertEquals(LeadFollowUpStatus::Done, $fu->fresh()->status);
    }

    public function test_snooze_1h_moves_overdue_followup_out_of_overdue(): void
    {
        $lead = Lead::factory()->create();
        $fu = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(2),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(OverdueFollowUpsTable::class)
            ->assertCanSeeTableRecords([$fu])
            ->callTableAction('snooze_1h', $fu)
            ->assertCanNotSeeTableRecords([$fu]);

        $fresh = $fu->fresh();
        $this->assertNotNull($fresh->snoozed_until);
        $this->assertTrue($fresh->snoozed_until->gt(now()));
    }

    // Regression guard: a single lead with one overdue and one future followup
    // must appear in its correct section with no cross-contamination.
    public function test_lead_with_overdue_and_future_followups_appears_in_each_correct_section(): void
    {
        $lead = Lead::factory()->create();

        $overdue = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(4),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
        $upcoming = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addDays(3),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        Livewire::test(OverdueFollowUpsTable::class)
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$upcoming]);

        Livewire::test(UpcomingFollowUpsTable::class)
            ->assertCanSeeTableRecords([$upcoming])
            ->assertCanNotSeeTableRecords([$overdue]);
    }

    public function test_new_lead_action_creates_lead_with_initial_followup_and_interaction(): void
    {
        Livewire::test(FollowUpQueuePage::class)
            ->callAction('new_lead', [
                'name'  => 'Fresh Inbound',
                'phone' => '+998903334455',
                'note'  => 'Asked about Silk Road tour on WhatsApp',
            ])
            ->assertHasNoActionErrors();

        $lead = Lead::where('phone', '+998903334455')->first();
        $this->assertNotNull($lead);
        $this->assertSame('Fresh Inbound', $lead->name);
        $this->assertSame(1, $lead->interactions()->count());
        $this->assertSame('Asked about Silk Road tour on WhatsApp', $lead->interactions()->first()->body);

        $followUp = $lead->followUps()->first();
        $this->assertNotNull($followUp);
        $this->assertSame('message', $followUp->type->value);
        $this->assertTrue($followUp->due_at->gt(now()));
    }

    public function test_new_lead_action_matches_existing_lead_when_contact_matches(): void
    {
        $existing = Lead::factory()->create([
            'phone' => '+998904445566',
            'name'  => 'Returning Guest',
        ]);

        Livewire::test(FollowUpQueuePage::class)
            ->callAction('new_lead', [
                'name'  => 'Should Be Ignored',
                'phone' => '+998904445566',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Lead::where('phone', '+998904445566')->count());
        $this->assertSame('Returning Guest', $existing->fresh()->name);
        $this->assertSame(1, $existing->followUps()->count());
    }

    public function test_new_lead_action_refuses_to_create_on_ambiguous_match(): void
    {
        Lead::factory()->create(['whatsapp_number' => '+998905556677']);
        Lead::factory()->create(['whatsapp_number' => '+998905556677']);

        Livewire::test(FollowUpQueuePage::class)
            ->callAction('new_lead', [
                'whatsapp_number' => '+998905556677',
                'name'            => 'Should Not Be Created',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Lead::where('name', 'Should Not Be Created')->count());
    }

    public function test_navigation_badge_reflects_overdue_count(): void
    {
        $lead = Lead::factory()->create();
        LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHour(),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
        LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(3),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        $this->assertSame('2', FollowUpQueuePage::getNavigationBadge());

        LeadFollowUp::query()->update(['status' => LeadFollowUpStatus::Done->value]);
        $this->assertNull(FollowUpQueuePage::getNavigationBadge());
    }
}
