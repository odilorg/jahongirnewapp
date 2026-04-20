<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Actions\Leads\IngestWhatsAppAsLead;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadWhatsAppIngestion;
use App\Services\Wacli\InboundWhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3b — WhatsApp inbound ingestion decision tree + idempotency.
 * Mirrors IngestEmailAsLeadTest.
 */
class IngestWhatsAppAsLeadTest extends TestCase
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

    public function test_new_phone_sender_creates_lead_with_interaction_and_followup(): void
    {
        $msg = $this->message(chatJid: '998903334455@s.whatsapp.net', chatName: 'New Inbound');

        $result = app(IngestWhatsAppAsLead::class)->handle($msg);

        $this->assertSame(IngestWhatsAppAsLead::DECISION_CREATED_NEW_LEAD, $result['decision']);
        $lead = Lead::find($result['lead_id']);
        $this->assertSame('+998903334455', $lead->whatsapp_number);
        $this->assertSame('New Inbound', $lead->name);
        $this->assertSame(1, $lead->interactions()->count());
        $this->assertSame(LeadInteractionChannel::WhatsApp, $lead->interactions()->first()->channel);
        $this->assertSame(LeadInteractionDirection::Inbound, $lead->interactions()->first()->direction);
        $this->assertSame(1, $lead->followUps()->count());

        $row = LeadWhatsAppIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadWhatsAppIngestion::STATUS_PROCESSED, $row->status);
        $this->assertSame($lead->id, $row->lead_id);
    }

    public function test_matched_sender_with_open_followup_gets_interaction_only(): void
    {
        $existing = Lead::factory()->create(['whatsapp_number' => '+998904445566']);
        LeadFollowUp::create([
            'lead_id' => $existing->id,
            'due_at'  => now()->addDay(),
            'status'  => LeadFollowUpStatus::Open->value,
        ]);

        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '998904445566@s.whatsapp.net')
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_APPENDED_EXISTING, $result['decision']);
        $this->assertSame($existing->id, $result['lead_id']);
        $this->assertSame(1, $existing->interactions()->count());
        $this->assertSame(1, $existing->followUps()->count(), 'no duplicate follow-up');
    }

    public function test_matched_sender_with_no_open_followup_creates_one(): void
    {
        $existing = Lead::factory()->create([
            'whatsapp_number' => '+998905556677',
            'status'          => LeadStatus::Contacted->value,
        ]);
        LeadFollowUp::create([
            'lead_id'      => $existing->id,
            'due_at'       => now()->subWeek(),
            'status'       => LeadFollowUpStatus::Done->value,
            'completed_at' => now()->subWeek(),
        ]);

        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '998905556677@s.whatsapp.net')
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_APPENDED_NO_FOLLOW, $result['decision']);
        $this->assertSame(1, $existing->followUps()->where('status', LeadFollowUpStatus::Open->value)->count());
    }

    public function test_from_me_message_is_skipped_as_self(): void
    {
        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '998901234567@s.whatsapp.net', isFromMe: true)
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_SKIPPED_SELF, $result['decision']);
        $this->assertSame(0, Lead::count(), 'operator outbound messages must not create leads');

        $row = LeadWhatsAppIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadWhatsAppIngestion::STATUS_SKIPPED_SELF, $row->status);
    }

    public function test_group_chat_is_skipped(): void
    {
        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '120363123456789012@g.us', chatName: 'Operators')
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_SKIPPED_GROUP, $result['decision']);
        $this->assertSame(0, Lead::count());
    }

    public function test_lid_only_chat_is_skipped_no_phone(): void
    {
        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '159626955911255@lid', chatName: 'Zafar')
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_SKIPPED_NO_PHONE, $result['decision']);
        $this->assertSame(0, Lead::count());
    }

    public function test_ambiguous_match_leaves_no_lead_and_records_reason(): void
    {
        Lead::factory()->create(['whatsapp_number' => '+998909998877']);
        Lead::factory()->create(['whatsapp_number' => '+998909998877']);

        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '998909998877@s.whatsapp.net')
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_AMBIGUOUS, $result['decision']);
        $this->assertNull($result['lead_id']);

        $row = LeadWhatsAppIngestion::find($result['ingestion_id']);
        $this->assertSame(LeadWhatsAppIngestion::STATUS_AMBIGUOUS, $row->status);
        $this->assertNull($row->lead_id);
        $this->assertStringContainsString('+998909998877', (string) $row->error_message);
    }

    public function test_duplicate_remote_message_id_is_explicit_skip(): void
    {
        $msg = $this->message(chatJid: '998906667788@s.whatsapp.net');

        $first = app(IngestWhatsAppAsLead::class)->handle($msg);
        $this->assertSame(IngestWhatsAppAsLead::DECISION_CREATED_NEW_LEAD, $first['decision']);

        $leadId = $first['lead_id'];
        $before = Lead::find($leadId)->interactions()->count();

        $second = app(IngestWhatsAppAsLead::class)->handle($msg);
        $this->assertSame(IngestWhatsAppAsLead::DECISION_SKIPPED_DUPLICATE, $second['decision']);

        $this->assertSame($before, Lead::find($leadId)->interactions()->count());
        $this->assertSame(1, LeadWhatsAppIngestion::where('remote_message_id', $msg->remoteMessageId)->count());
    }

    public function test_dry_run_reaches_decision_without_writes(): void
    {
        $result = app(IngestWhatsAppAsLead::class)->handle(
            $this->message(chatJid: '998907778899@s.whatsapp.net'),
            dryRun: true,
        );

        $this->assertSame(IngestWhatsAppAsLead::DECISION_CREATED_NEW_LEAD, $result['decision']);
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadWhatsAppIngestion::count());
    }

    private function message(
        string $chatJid = '998900000001@s.whatsapp.net',
        ?string $chatName = 'Sender',
        bool $isFromMe = false,
        string $body = 'Interested in a tour.',
        ?string $msgId = null,
    ): InboundWhatsAppMessage {
        $msgId = $msgId ?? strtoupper(bin2hex(random_bytes(8)));

        return new InboundWhatsAppMessage(
            remoteMessageId: $chatJid.':'.$msgId,
            msgId: $msgId,
            chatJid: $chatJid,
            senderJid: $chatJid,
            chatName: $chatName,
            body: $body,
            isFromMe: $isFromMe,
            mediaType: null,
            sentAt: Carbon::parse('2026-04-20 11:55:00'),
        );
    }
}
