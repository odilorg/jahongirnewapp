<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\RecordWaClassification;
use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordWaClassificationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function candidate(): WaLeadCandidate
    {
        return WaLeadCandidate::create([
            'phone' => '99890111' . str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT),
            'inbound_count' => 1, 'outbound_count' => 0, 'status' => WaLeadCandidate::STATUS_PENDING,
        ]);
    }

    private function record(array $result): WaLeadCandidate
    {
        $c = $this->candidate();
        app(RecordWaClassification::class)->record($c, $result);
        return $c->fresh();
    }

    public function test_accommodation_is_reviewed_never_dismissed(): void
    {
        config()->set('wa_leads.auto_dismiss_enabled', true);   // even with the gate ON
        $c = $this->record(['classification' => 'not_lead', 'confidence' => 0.97, 'not_lead_subtype' => 'accommodation']);

        $this->assertSame(WaLeadCandidate::STATUS_REVIEW, $c->status);
        $this->assertSame('would_review', $c->decision);
        $this->assertNotSame(WaLeadCandidate::STATUS_DISMISSED, $c->status);
    }

    public function test_only_junk_subtypes_are_dismiss_eligible_when_gate_on(): void
    {
        config()->set('wa_leads.auto_dismiss_enabled', true);
        foreach (['spam', 'b2b', 'supplier'] as $junk) {
            $c = $this->record(['classification' => 'not_lead', 'confidence' => 0.95, 'not_lead_subtype' => $junk]);
            $this->assertSame(WaLeadCandidate::STATUS_DISMISSED, $c->status, $junk);
            $this->assertSame($junk, $c->dismissed_reason);
        }
        foreach (['accommodation', 'logistics', 'personal', 'other'] as $keep) {
            $c = $this->record(['classification' => 'not_lead', 'confidence' => 0.95, 'not_lead_subtype' => $keep]);
            $this->assertSame(WaLeadCandidate::STATUS_REVIEW, $c->status, $keep);
        }
    }

    public function test_junk_not_dismissed_while_gate_off(): void
    {
        // default gate OFF -> even spam routes to review
        $c = $this->record(['classification' => 'not_lead', 'confidence' => 0.99, 'not_lead_subtype' => 'spam']);
        $this->assertSame(WaLeadCandidate::STATUS_REVIEW, $c->status);
        $this->assertSame('would_auto_dismiss', $c->decision);   // recommendation recorded, not acted on
    }

    public function test_no_booking_inquiries_created_while_gates_off(): void
    {
        config()->set('wa_leads.auto_create_enabled', true);    // even with the gate ON, 2d not built
        $c = $this->record(['classification' => 'genuine_tour_inquiry', 'confidence' => 0.97,
            'detected_tour' => 'Yurt camp', 'party_size' => 2]);

        $this->assertSame('would_auto_create', $c->decision);
        $this->assertSame(WaLeadCandidate::STATUS_REVIEW, $c->status);
        $this->assertSame(0, BookingInquiry::count());          // nothing created
    }

    public function test_malformed_classification_routes_to_review(): void
    {
        $c = $this->record(['classification' => 'banana', 'confidence' => 'x']);
        $this->assertSame(WaLeadCandidate::CLASS_UNCERTAIN, $c->classification);
        $this->assertSame(WaLeadCandidate::STATUS_REVIEW, $c->status);
    }
}
