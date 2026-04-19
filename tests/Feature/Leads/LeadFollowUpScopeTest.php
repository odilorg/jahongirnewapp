<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Enums\LeadFollowUpStatus;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead CRM Phase 1 — follow-up queue correctness.
 *
 * scopeDue and scopeOverdue both have to respect snoozed_until; the fix was
 * applied late in review so it's specifically regression-prone.
 */
class LeadFollowUpScopeTest extends TestCase
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

    public function test_due_scope_excludes_followups_snoozed_into_the_future(): void
    {
        $lead = Lead::factory()->create();

        $plainDue = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHour(),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        $snoozedAhead = LeadFollowUp::create([
            'lead_id'       => $lead->id,
            'due_at'        => now()->subHour(),
            'snoozed_until' => now()->addHours(2),
            'status'        => LeadFollowUpStatus::Open->value,
        ]);

        $dueIds = LeadFollowUp::due()->pluck('id')->all();

        $this->assertContains($plainDue->id, $dueIds);
        $this->assertNotContains($snoozedAhead->id, $dueIds);
    }

    public function test_overdue_scope_excludes_followups_snoozed_into_the_future(): void
    {
        $lead = Lead::factory()->create();

        $plainOverdue = LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->subHours(3),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        $snoozedForward = LeadFollowUp::create([
            'lead_id'       => $lead->id,
            'due_at'        => now()->subHours(3),
            'snoozed_until' => now()->addHour(),
            'status'        => LeadFollowUpStatus::Open->value,
        ]);

        $overdueIds = LeadFollowUp::overdue()->pluck('id')->all();

        $this->assertContains($plainOverdue->id, $overdueIds);
        $this->assertNotContains($snoozedForward->id, $overdueIds);
    }
}
