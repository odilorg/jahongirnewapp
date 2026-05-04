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
}
