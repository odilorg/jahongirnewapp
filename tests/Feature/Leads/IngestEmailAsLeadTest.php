<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\IngestEmailAsLead;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadEmailIngestion;
use App\Models\LeadFollowUp;
use App\Services\Zoho\InboundEmail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3a — inbound email ingestion decision tree + idempotency.
 *
 * All 6 cases exercised without a real IMAP server. The ingestion action
 * receives a plain InboundEmail DTO so these tests stay fast and offline.
 */
class IngestEmailAsLeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_sender_creates_lead_with_interaction_and_initial_followup(): void
    {
        $email = $this->email(senderEmail: 'traveler@example.com', senderName: 'Jane Traveler');

        $result = app(IngestEmailAsLead::class)->handle($email);

        $this->assertSame(IngestEmailAsLead::DECISION_CREATED_NEW_LEAD, $result['decision']);
        $this->assertNotNull($result['lead_id']);

        $lead = Lead::find($result['lead_id']);
        $this->assertSame('traveler@example.com', $lead->email);
        $this->assertSame(1, $lead->interactions()->count());
        $this->assertSame(LeadInteractionChannel::Email, $lead->interactions()->first()->channel);
        $this->assertSame(LeadInteractionDirection::Inbound, $lead->interactions()->first()->direction);
        $this->assertSame(1, $lead->followUps()->count());

        $ingestion = LeadEmailIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadEmailIngestion::STATUS_PROCESSED, $ingestion->status);
        $this->assertSame($lead->id, $ingestion->lead_id);
    }

    public function test_matched_sender_with_open_followup_gets_interaction_only(): void
    {
        $existing = Lead::factory()->create(['email' => 'known@example.com']);
        LeadFollowUp::create([
            'lead_id' => $existing->id,
            'due_at'  => now()->addDay(),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        $email = $this->email(senderEmail: 'known@example.com');
        $result = app(IngestEmailAsLead::class)->handle($email);

        $this->assertSame(IngestEmailAsLead::DECISION_APPENDED_EXISTING, $result['decision']);
        $this->assertSame($existing->id, $result['lead_id']);
        $this->assertSame(1, $existing->interactions()->count());
        $this->assertSame(1, $existing->followUps()->count(), 'must not create a duplicate follow-up');
    }

    public function test_matched_sender_with_no_open_followup_creates_one(): void
    {
        $existing = Lead::factory()->create(['email' => 'lapsed@example.com', 'status' => LeadStatus::Contacted->value]);
        LeadFollowUp::create([
            'lead_id'      => $existing->id,
            'due_at'       => now()->subWeek(),
            'status'       => LeadFollowUpStatus::Done->value,
            'completed_at' => now()->subWeek(),
        ]);

        $email = $this->email(senderEmail: 'lapsed@example.com');
        $result = app(IngestEmailAsLead::class)->handle($email);

        $this->assertSame(IngestEmailAsLead::DECISION_APPENDED_NO_FOLLOW, $result['decision']);
        $this->assertSame(1, $existing->followUps()->where('status', LeadFollowUpStatus::Open->value)->count());
    }

    public function test_ambiguous_sender_leaves_mailbox_for_human_and_no_lead_created(): void
    {
        Lead::factory()->create(['email' => 'shared@example.com']);
        Lead::factory()->create(['email' => 'shared@example.com']);

        $email = $this->email(senderEmail: 'shared@example.com');
        $result = app(IngestEmailAsLead::class)->handle($email);

        $this->assertSame(IngestEmailAsLead::DECISION_AMBIGUOUS, $result['decision']);
        $this->assertNull($result['lead_id']);

        $ingestion = LeadEmailIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadEmailIngestion::STATUS_AMBIGUOUS, $ingestion->status);
        $this->assertNull($ingestion->lead_id);
        $this->assertStringContainsString('shared@example.com', (string) $ingestion->error_message);
    }

    public function test_blocklisted_sender_is_skipped_and_flagged_processed(): void
    {
        $email = $this->email(senderEmail: 'NoReply@booking.com', subject: 'booking confirmation');
        $result = app(IngestEmailAsLead::class)->handle($email);

        $this->assertSame(IngestEmailAsLead::DECISION_SKIPPED_BLOCKLIST, $result['decision']);
        $this->assertSame(0, Lead::count(), 'blocklisted senders must not spawn leads');

        $ingestion = LeadEmailIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadEmailIngestion::STATUS_SKIPPED_BLOCKLIST, $ingestion->status);
    }

    public function test_duplicate_message_id_is_explicit_skip_not_double_ingested(): void
    {
        $email = $this->email(senderEmail: 'new@example.com');

        $first = app(IngestEmailAsLead::class)->handle($email);
        $this->assertSame(IngestEmailAsLead::DECISION_CREATED_NEW_LEAD, $first['decision']);

        $leadId = $first['lead_id'];
        $interactionCountBefore = Lead::find($leadId)->interactions()->count();

        $second = app(IngestEmailAsLead::class)->handle($email);
        $this->assertSame(IngestEmailAsLead::DECISION_SKIPPED_DUPLICATE, $second['decision']);

        $this->assertSame($interactionCountBefore, Lead::find($leadId)->interactions()->count(),
            'second ingestion of same message_id must not log another interaction');
        $this->assertSame(1, LeadEmailIngestion::where('remote_message_id', $email->messageId)->count(),
            'dedupe row must not duplicate');
    }

    public function test_dry_run_reaches_a_decision_without_any_writes(): void
    {
        $email = $this->email(senderEmail: 'dryrun@example.com');

        $result = app(IngestEmailAsLead::class)->handle($email, dryRun: true);

        $this->assertSame(IngestEmailAsLead::DECISION_CREATED_NEW_LEAD, $result['decision']);
        $this->assertSame(0, Lead::count(), 'dry-run must not persist leads');
        $this->assertSame(0, LeadEmailIngestion::count(), 'dry-run must not persist ingestion rows');
    }

    private function email(
        string $senderEmail = 'sender@example.com',
        ?string $senderName = 'Sender',
        string $subject = 'Hello',
        string $body = 'Hi, I am interested in a tour.',
        ?string $messageId = null,
    ): InboundEmail {
        return new InboundEmail(
            messageId: $messageId ?? 'msg-'.uniqid().'@example.com',
            uid: (string) random_int(10000, 99999),
            folder: 'INBOX',
            senderEmail: $senderEmail,
            senderName: $senderName,
            subject: $subject,
            body: $body,
            hasAttachments: false,
            attachmentFilenames: [],
        );
    }
}
