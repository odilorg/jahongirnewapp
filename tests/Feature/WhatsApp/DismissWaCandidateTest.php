<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\DismissWaCandidate;
use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DismissWaCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_dismiss_stores_reason_creates_no_inquiry_and_does_not_delete(): void
    {
        $c = WaLeadCandidate::create([
            'phone' => '998903330001', 'inbound_count' => 1, 'outbound_count' => 0,
            'status' => WaLeadCandidate::STATUS_REVIEW, 'classification' => 'not_lead',
            'not_lead_subtype' => 'accommodation',
        ]);

        app(DismissWaCandidate::class)->dismiss($c, 'hotel booking, not a tour', 'tester');

        $c->refresh();
        $this->assertSame(WaLeadCandidate::STATUS_DISMISSED, $c->status);
        $this->assertSame('hotel booking, not a tour', $c->dismissed_reason);
        $this->assertSame('tester', $c->decided_by);
        $this->assertSame(0, BookingInquiry::count());            // no inquiry
        $this->assertDatabaseHas('wa_lead_candidates', ['id' => $c->id]); // not deleted
    }
}
