<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\AgentActionLog;
use App\Models\BookingInquiry;
use App\Models\User;
use App\Services\Agent\AgentActionDispatcher;
use App\Support\Agent\AgentAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Guardrail contract tests for the tour-agent write path (agent:apply).
 *
 * Load-bearing invariants: Tier-2 never sends while disabled; writes require an
 * approval token; idempotency never double-applies; dry-run never writes.
 */
class AgentApplyDispatcherTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Belt-and-suspenders: the phase invariant under test.
        config()->set('agent.sending_enabled', false);
    }

    private function dispatcher(): AgentActionDispatcher
    {
        return app(AgentActionDispatcher::class);
    }

    private function makeInquiry(array $overrides = []): BookingInquiry
    {
        return BookingInquiry::create(array_merge([
            'reference' => 'INQ-TEST-'.uniqid(),
            'source' => 'whatsapp',
            'status' => BookingInquiry::STATUS_NEW,
            'customer_name' => 'Test Guest',
            'customer_phone' => '+998901234567',
            'tour_name_snapshot' => 'Bukhara City Tour',
            'people_adults' => 2,
            'people_children' => 0,
            'travel_date' => now()->addDays(10)->toDateString(),
            'submitted_at' => now(),
        ], $overrides));
    }

    public function test_add_note_apply_appends_attributed_line(): void
    {
        $inquiry = $this->makeInquiry();

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::AddNote, ['note' => 'Asked guest for travel dates'],
            'tour-agent', 'tok-1', 'key-note-1', true,
        );

        $this->assertTrue($res['ok']);
        $this->assertSame('applied', $res['status']);
        $inquiry->refresh();
        $this->assertStringContainsString('[tour-agent] Asked guest for travel dates', (string) $inquiry->internal_notes);
        $this->assertDatabaseHas('agent_action_log', ['idempotency_key' => 'key-note-1', 'status' => 'applied']);
    }

    public function test_dry_run_simulates_without_writing(): void
    {
        $inquiry = $this->makeInquiry();

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::MarkContacted, [], 'tour-agent', '', 'key-sim-1', false,
        );

        $this->assertTrue($res['ok']);
        $this->assertSame('simulated', $res['status']);
        $this->assertSame(BookingInquiry::STATUS_NEW, $inquiry->refresh()->status, 'dry-run must not change status');
    }

    public function test_mark_contacted_apply_transitions_and_stamps(): void
    {
        $inquiry = $this->makeInquiry();

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::MarkContacted, [], 'tour-agent', 'tok-2', 'key-contact-1', true,
        );

        $this->assertTrue($res['ok']);
        $inquiry->refresh();
        $this->assertSame(BookingInquiry::STATUS_CONTACTED, $inquiry->status);
        $this->assertNotNull($inquiry->contacted_at);
        $this->assertStringContainsString('Status new → contacted', (string) $inquiry->internal_notes);
    }

    public function test_apply_requires_approval_token(): void
    {
        $inquiry = $this->makeInquiry();

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::MarkContacted, [], 'tour-agent', '', 'key-notoken-1', true,
        );

        $this->assertFalse($res['ok']);
        $this->assertSame('refused', $res['status']);
        $this->assertSame('approval_token_required', $res['reason']);
        $this->assertSame(BookingInquiry::STATUS_NEW, $inquiry->refresh()->status);
    }

    public function test_tier2_is_refused_while_sending_disabled_and_never_sends(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_CONTACTED]);

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::SendOffer, ['message' => 'hi'], 'tour-agent', 'tok-3', 'key-tier2-1', true,
        );

        $this->assertFalse($res['ok']);
        $this->assertSame('refused', $res['status']);
        $this->assertSame('sending_disabled', $res['reason']);
        // Status untouched; the action short-circuits before any sender is touched.
        $this->assertSame(BookingInquiry::STATUS_CONTACTED, $inquiry->refresh()->status);
        $this->assertDatabaseHas('agent_action_log', ['idempotency_key' => 'key-tier2-1', 'status' => 'refused', 'reason' => 'sending_disabled']);
    }

    public function test_idempotent_apply_does_not_double_apply(): void
    {
        $inquiry = $this->makeInquiry();

        $first = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::AddNote, ['note' => 'Only once please'],
            'tour-agent', 'tok-4', 'key-idem-1', true,
        );
        $second = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::AddNote, ['note' => 'Only once please'],
            'tour-agent', 'tok-4', 'key-idem-1', true,
        );

        $this->assertSame('applied', $first['status']);
        $this->assertSame('idempotent_replay', $second['status']);
        $this->assertTrue($second['ok']);
        $this->assertSame(1, substr_count((string) $inquiry->refresh()->internal_notes, 'Only once please'));
        $this->assertSame(1, AgentActionLog::where('idempotency_key', 'key-idem-1')->count());
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $inquiry = $this->makeInquiry(['status' => BookingInquiry::STATUS_CONFIRMED, 'confirmed_at' => now()]);

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::MarkContacted, [], 'tour-agent', 'tok-5', 'key-bad-1', true,
        );

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
        $this->assertSame('validation_error', $res['reason']);
        $this->assertSame(BookingInquiry::STATUS_CONFIRMED, $inquiry->refresh()->status);
    }

    public function test_assign_respects_first_touch_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $inquiry = $this->makeInquiry(['assigned_to_user_id' => $owner->id]);

        $res = $this->dispatcher()->dispatch(
            $inquiry, AgentAction::Assign, ['user_id' => $other->id],
            'tour-agent', 'tok-6', 'key-assign-1', true,
        );

        $this->assertTrue($res['ok']);
        $this->assertSame($owner->id, $inquiry->refresh()->assigned_to_user_id, 'existing owner must not be overwritten');
    }
}
