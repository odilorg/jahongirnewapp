<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\IngestWaCandidates;
use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestWaCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(string $phone, string $msg = 'can i book a tour?'): array
    {
        return ['phone' => $phone, 'first_inbound' => $msg, 'last_inbound_at' => '2026-06-16T09:00:00+00:00',
                'inbound' => 1, 'outbound' => 0];
    }

    private function inquiry(string $phone): void
    {
        BookingInquiry::create([
            'reference' => BookingInquiry::generateReference(), 'source' => BookingInquiry::SOURCE_WHATSAPP,
            'status' => BookingInquiry::STATUS_NEW, 'customer_name' => 'X', 'customer_email' => null,
            'customer_phone' => $phone, 'tour_name_snapshot' => 'X', 'people_adults' => 1,
            'people_children' => 0, 'submitted_at' => now(), 'message' => 'x',
        ]);
    }

    public function test_new_prospect_is_queued_pending(): void
    {
        $counts = (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: false);

        $this->assertSame(1, $counts['queued'] ?? 0);
        $this->assertDatabaseHas('wa_lead_candidates', [
            'phone' => '998901112233', 'status' => WaLeadCandidate::STATUS_PENDING, 'booking_inquiry_id' => null,
        ]);
    }

    public function test_phone_already_a_crm_inquiry_is_skipped(): void
    {
        $this->inquiry('+998 90 111 2233');   // stored with formatting; normalized match
        $counts = (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: false);

        $this->assertSame(1, $counts['skip:existing_inquiry'] ?? 0);
        $this->assertSame(0, WaLeadCandidate::count());
    }

    public function test_already_a_candidate_is_idempotent(): void
    {
        (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: false);
        $second = (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: false);

        $this->assertSame(1, $second['skip:already_candidate'] ?? 0);
        $this->assertSame(1, WaLeadCandidate::count());
    }

    public function test_dismissed_candidate_does_not_resurface(): void
    {
        WaLeadCandidate::create(['phone' => '998901112233', 'status' => WaLeadCandidate::STATUS_DISMISSED,
            'inbound_count' => 1, 'outbound_count' => 0]);
        $counts = (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: false);

        $this->assertSame(1, $counts['skip:already_candidate'] ?? 0);
        $this->assertSame(1, WaLeadCandidate::where('status', WaLeadCandidate::STATUS_DISMISSED)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $counts = (new IngestWaCandidates())->ingest([$this->candidate('998901112233')], dryRun: true);

        $this->assertSame(1, $counts['would_queue'] ?? 0);
        $this->assertSame(0, WaLeadCandidate::count());
    }

    public function test_invalid_phone_skipped(): void
    {
        $counts = (new IngestWaCandidates())->ingest([$this->candidate('123')], dryRun: false);
        $this->assertSame(1, $counts['skip:invalid_phone'] ?? 0);
        $this->assertSame(0, WaLeadCandidate::count());
    }
}
