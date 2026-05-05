<?php

declare(strict_types=1);

namespace Tests\Unit\Viator;

use App\Models\ViatorInboundEmail;
use App\Services\Viator\ViatorEmailParser;
use PHPUnit\Framework\TestCase;

/**
 * Parser invariants pinned against redacted real Viator emails. Every
 * fixture file under tests/Fixtures/Viator/ is a real email captured
 * via himalaya, with PII (names, phones, hotel addresses) replaced
 * by safe placeholders. BR refs / product codes / prices are real
 * because they are not personal.
 *
 * Each test answers a specific operational question:
 *   - "Does the parser correctly classify the event type?"
 *   - "Are the fields needed by the auto-apply path populated?"
 *   - "Are case-variant labels still picked up?"
 *   - "Does the cancellation extract its BR from the body when subject
 *      doesn't carry it?"
 */
class ViatorEmailParserTest extends TestCase
{
    private ViatorEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ViatorEmailParser();
    }

    private function load(string $name): array
    {
        $path = __DIR__ . '/../../Fixtures/Viator/' . $name . '.txt';
        $raw  = file_get_contents($path);

        // Fixture format mirrors himalaya output:
        //   From: Viator <booking@t1.viator.com>
        //   To: ...
        //   Subject: <subject>
        //
        //   <body>
        $subject = '';
        if (preg_match('/^Subject:\s*(.+)$/m', $raw, $m)) {
            $subject = trim($m[1]);
        }
        // Body = everything after the first blank line
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $body  = $parts[1] ?? $raw;

        return [$subject, $body];
    }

    /** @test */
    public function new_private_booking_classified_correctly(): void
    {
        [$subject, $body] = $this->load('new_private');
        $r = $this->parser->parse($subject, $body);

        $this->assertSame(ViatorInboundEmail::TYPE_NEW, $r['email_type']);
        $this->assertSame('BR-1390901059', $r['external_reference']);
    }

    /** @test */
    public function new_private_booking_extracts_core_fields(): void
    {
        [$subject, $body] = $this->load('new_private');
        $r = $this->parser->parse($subject, $body)['parsed_payload'];

        $this->assertSame('Test Guest A', $r['lead_traveler_name']);
        $this->assertSame(['Test Guest A', 'Test Guest A'], $r['traveler_names']);
        $this->assertSame(2, $r['people_adults']);
        $this->assertSame(0, $r['people_children']);
        $this->assertSame('153457P2', $r['product_code']);
        $this->assertSame('Private Guided Samarkand Tour 09:00', $r['tour_grade']);
        $this->assertSame('DEFAULT~09:00', $r['tour_grade_code']);
        $this->assertSame('2026-09-20', $r['travel_date']);
        $this->assertSame('USD', $r['net_rate_currency']);
        $this->assertSame(97.50, $r['net_rate_amount']);
        $this->assertSame('I will contact the supplier later', $r['hotel_pickup']);
        $this->assertSame('No', $r['special_requirements']);
    }

    /** @test */
    public function new_group_booking_uses_meeting_point_not_hotel_pickup(): void
    {
        [$subject, $body] = $this->load('new_group');
        $r = $this->parser->parse($subject, $body)['parsed_payload'];

        $this->assertSame('153457P2', $r['product_code']);
        $this->assertSame(1, $r['people_adults']);
        $this->assertStringContainsString('Group Tour', (string) $r['tour_grade']);
        $this->assertNotEmpty($r['meeting_point']);
        $this->assertStringContainsString('Amir Temur', (string) $r['meeting_point']);
        $this->assertSame(54.60, $r['net_rate_amount']);
    }

    /** @test */
    public function new_transfer_booking_recognises_p5_product(): void
    {
        [$subject, $body] = $this->load('new_transfer');
        $r = $this->parser->parse($subject, $body)['parsed_payload'];

        $this->assertSame('153457P5', $r['product_code']);
        $this->assertSame('BR-1335567079', $this->parser->parse($subject, $body)['external_reference']);
        $this->assertSame(22.50, $r['net_rate_amount']);
    }

    /** @test */
    public function new_daytour_carries_transport_extras(): void
    {
        [$subject, $body] = $this->load('new_daytour');
        $r = $this->parser->parse($subject, $body)['parsed_payload'];

        $this->assertSame('153457P1', $r['product_code']);
        // Transport-only product surfaces extra airline fields; we
        // capture them even when "Not applicable" so an operator can
        // see Viator's published values verbatim.
        $this->assertSame('Not applicable', $r['departure_airline']);
        $this->assertSame('Not applicable', $r['departure_time']);
        $this->assertSame('Not applicable', $r['departure_flight_no']);
    }

    /** @test */
    public function amendment_classified_and_carries_lowercase_t_lead_traveler(): void
    {
        [$subject, $body] = $this->load('amended');
        $r = $this->parser->parse($subject, $body);

        $this->assertSame(ViatorInboundEmail::TYPE_AMENDED, $r['email_type']);
        $this->assertSame('BR-1316352651', $r['external_reference']);

        $payload = $r['parsed_payload'];
        // Amendment uses "Lead traveler name" (lowercase t); parser
        // must fold to the same key as the new-booking variant.
        $this->assertSame('Test Guest F', $payload['lead_traveler_name']);
        $this->assertSame('153457P2', $payload['product_code']);
        $this->assertSame('Vegetarian', $payload['special_requirements']);
        $this->assertNotEmpty($payload['amendment_delta']);
    }

    /** @test */
    public function amendment_delta_captures_bullet_lines(): void
    {
        [$subject, $body] = $this->load('amended');
        $r = $this->parser->parse($subject, $body)['parsed_payload'];

        $delta = $r['amendment_delta'];
        $this->assertGreaterThanOrEqual(2, count($delta));
        $this->assertStringContainsString('Pickup point type changed', $delta[0]);
        $this->assertStringContainsString('Pickup point changed', $delta[1]);
    }

    /** @test */
    public function cancellation_extracts_BR_from_body_when_subject_lacks_it(): void
    {
        [$subject, $body] = $this->load('cancelled');
        // Cancellation subject form: "Cancelled Booking: Sun, Nov 30, 2025"
        // No BR ref in subject — parser must fall back to body.
        $this->assertStringContainsString('Cancelled Booking', $subject);
        $this->assertStringNotContainsString('BR-', $subject);

        $r = $this->parser->parse($subject, $body);
        $this->assertSame(ViatorInboundEmail::TYPE_CANCELLED, $r['email_type']);
        $this->assertSame('BR-1336204871', $r['external_reference']);

        $payload = $r['parsed_payload'];
        $this->assertSame('Test Guest B', $payload['lead_traveler_name']);
        $this->assertSame('Private Guided Samarkand Tour 10:00', $payload['tour_grade']);
        $this->assertSame('2025-11-30', $payload['travel_date']);
        $this->assertSame(2, $payload['people_adults']);
        // Cancellations carry no Net Rate by design.
        $this->assertArrayNotHasKey('net_rate_amount', $payload);
    }

    /** @test */
    public function unknown_email_returns_unknown_type_without_throwing(): void
    {
        $r = $this->parser->parse(
            'Some unrelated Viator email',
            'No booking content here, just promotional text.',
        );

        $this->assertSame(ViatorInboundEmail::TYPE_UNKNOWN, $r['email_type']);
        $this->assertNull($r['external_reference']);
    }

    // ── tour_name extraction (regression for BR-1393592315) ─────────

    /**
     * Real production failure mode pinned: the email body repeats the
     * subject preamble "New Booking for <date> (#BR-...)" near the top, and
     * the old loose `|booking for` alternation captured that date/ref junk
     * before the real reservation phrase. Inquiry 94 (Laura Tassi) ended up
     * with tour_name="Tue, May 19, 2026 (#BR-...) No action is required",
     * which broke fuzzy catalog matching → tour_product_id=NULL → calendar
     * couldn't span the 2-day chip across May 19→May 20.
     */
    public function test_tour_name_uses_labeled_field_over_subject_preamble(): void
    {
        // Body shape mirrors the real BR-1393592315 email layout.
        $body = <<<'BODY'
New Booking for Tue, May 19, 2026 (#BR-1393592315)               No action is required. This booking is confirmed.

Booking Confirmation     You have a new reservation for Private 2-Day Aydarkul & Yurt Camp Tour: Samarkand-Bukhara. This is an Instant Confirmation booking, so no action is required.

Booking Details     Booking Reference: BR-1393592315         Tour Name: Private 2-Day Aydarkul & Yurt Camp Tour: Samarkand-Bukhara     Travel Date: Tue, May 19, 2026     Lead Traveler Name: Laura Tassi     Travelers: 2 Adults     Product Code: 153457P16      Tour Grade: Private 2-Day Aydarkul & Yurt Camp Tour: Samarkand-Bukhara 08:00     Tour Grade Code: TG1~08:00      Location: Samarkand, Uzbekistan
BODY;

        $r = $this->parser->parse(
            'New Booking for Tue, May 19, 2026 (#BR-1393592315)',
            $body,
        )['parsed_payload'];

        // Must NOT capture the subject preamble (the old bug).
        $this->assertNotSame(
            'Tue, May 19, 2026 (#BR-1393592315) No action is required',
            $r['tour_name'],
            'parser must not capture the date/ref preamble as tour_name',
        );
        // Must capture the real product name from the labeled field.
        $this->assertSame(
            'Private 2-Day Aydarkul & Yurt Camp Tour: Samarkand-Bukhara',
            $r['tour_name'],
        );
    }

    public function test_tour_name_fallback_when_label_field_absent(): void
    {
        // Older/edge-case templates omit the "Tour Name:" label. The
        // narrowed fallback regex (no "|booking for") must still pick up
        // the real product name from the reservation phrase.
        $body = <<<'BODY'
Booking Confirmation     You have a new reservation for Some Legacy Tour Name. This is an Instant Confirmation booking.

Booking Details     Booking Reference: BR-9999999999         Travel Date: Sun, Sep 20, 2026
BODY;

        $r = $this->parser->parse('Booking - BR-9999999999', $body)['parsed_payload'];

        $this->assertSame('Some Legacy Tour Name', $r['tour_name']);
    }

    public function test_existing_fixtures_still_extract_correct_tour_name(): void
    {
        // Triple-check no regression on the canonical happy paths.
        [$s, $b] = $this->load('new_private');
        $this->assertSame(
            'Private Guided Tour Samarkand city history and culture',
            $this->parser->parse($s, $b)['parsed_payload']['tour_name'],
        );

        [$s, $b] = $this->load('new_daytour');
        $this->assertSame(
            'Day Tour to Shahrisabz – Birthplace of Amir Temur',
            $this->parser->parse($s, $b)['parsed_payload']['tour_name'],
        );

        [$s, $b] = $this->load('new_group');
        $this->assertSame(
            'Group Tour Samarkand city history, architecture and culture',
            $this->parser->parse($s, $b)['parsed_payload']['tour_name'],
        );
    }
}
