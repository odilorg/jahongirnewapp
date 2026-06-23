<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\CreateInquiryFromWaCandidate;
use App\Models\BookingInquiry;
use App\Models\WaLeadCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateInquiryFromWaCandidateTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function candidate(array $a = []): WaLeadCandidate
    {
        return WaLeadCandidate::create(array_merge([
            'phone' => '99890222' . str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT),
            'inbound_count' => 1, 'outbound_count' => 0, 'status' => WaLeadCandidate::STATUS_REVIEW,
            'first_messages' => 'price for 2 pax yurt camp?',
        ], $a));
    }

    private function act(WaLeadCandidate $c): array
    {
        return app(CreateInquiryFromWaCandidate::class)->create($c, 'tester');
    }

    public function test_creates_exactly_one_inquiry_with_safe_mapping(): void
    {
        $c = $this->candidate(['detected_tour' => 'Yurt camp', 'detected_party_size' => 2,
            'detected_date' => '2026-08-14', 'classification' => 'genuine_tour_inquiry', 'confidence' => 0.95]);
        $res = $this->act($c);

        $this->assertTrue($res['created']);
        $this->assertSame(1, BookingInquiry::count());
        $inq = $res['inquiry'];
        $this->assertSame(BookingInquiry::SOURCE_WHATSAPP, $inq->source);
        $this->assertSame(BookingInquiry::STATUS_NEW, $inq->status);
        $this->assertSame('Yurt camp', $inq->tour_name_snapshot);
        $this->assertSame(2, $inq->people_adults);
        $this->assertSame('2026-08-14', $inq->travel_date?->toDateString());
        $this->assertNull($inq->price_quoted);                    // no price guessing
        $this->assertSame(WaLeadCandidate::STATUS_CREATED, $c->fresh()->status);
        $this->assertSame($inq->id, $c->fresh()->booking_inquiry_id);
    }

    public function test_unconfident_candidate_creates_with_null_detected_fields(): void
    {
        $inq = $this->act($this->candidate())['inquiry'];          // no detected_* set
        $this->assertSame('WhatsApp inquiry', $inq->tour_name_snapshot);
        $this->assertSame(1, $inq->people_adults);
        $this->assertNull($inq->travel_date);
        $this->assertNull($inq->price_quoted);
    }

    public function test_rechecks_duplicate_and_links_existing_instead_of_creating(): void
    {
        $phone = '998902220001';
        BookingInquiry::create([
            'reference' => BookingInquiry::generateReference(), 'source' => BookingInquiry::SOURCE_WEBSITE,
            'status' => BookingInquiry::STATUS_NEW, 'customer_name' => 'X', 'customer_phone' => $phone,
            'tour_name_snapshot' => 'X', 'people_adults' => 1, 'people_children' => 0, 'submitted_at' => now(), 'message' => 'x',
        ]);
        $this->seq = 0; // make candidate() produce 998902220001
        $res = $this->act($this->candidate());

        $this->assertFalse($res['created']);
        $this->assertSame('linked_existing', $res['reason']);
        $this->assertSame(1, BookingInquiry::count());            // no second inquiry
    }

    public function test_idempotent_does_not_double_create(): void
    {
        $c = $this->candidate();
        $first = $this->act($c);
        $second = $this->act($c->fresh());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame('already_created', $second['reason']);
        $this->assertSame(1, BookingInquiry::count());
    }
}
