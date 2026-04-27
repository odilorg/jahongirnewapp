<?php

namespace Tests\Unit;

use App\Services\GygEmailParser;
use PHPUnit\Framework\TestCase;

class GygEmailParserTest extends TestCase
{
    private GygEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GygEmailParser();
    }

    // ── New booking parsing ─────────────────────────────

    private function sampleBookingBody(): string
    {
        return <<<'BODY'
Hi Supply Partner, great news!
Your offer has been booked:

Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour

Samarkand to Bukhara: 2-Day Group Yurt & Camel

Reference numbergygzgz5xlfnq

DateApril 19, 2026 9:00 AM

Number of participants2 x Adults (Age 0 - 99)

Main customerKatrine Arps Studskjær customer-fnygpmmlvad4gooy@reply.getyourguide.com
Phone: +4527890741
Language: Danish

Price$ 330.00open booking
BODY;
    }

    public function test_parses_new_booking_with_all_fields(): void
    {
        $result = $this->parser->parseNewBooking(
            $this->sampleBookingBody(),
            'Booking - S374926 - GYGZGZ5XLFNQ'
        );

        $this->assertEquals('GYGZGZ5XLFNQ', $result['gyg_booking_reference']);
        $this->assertEquals('Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour', $result['tour_name']);
        $this->assertEquals('Samarkand to Bukhara: 2-Day Group Yurt & Camel', $result['option_title']);
        $this->assertEquals('Katrine Arps Studskjær', $result['guest_name']);
        $this->assertEquals('customer-fnygpmmlvad4gooy@reply.getyourguide.com', $result['guest_email']);
        $this->assertEquals('+4527890741', $result['guest_phone']);
        $this->assertEquals('2026-04-19', $result['travel_date']);
        $this->assertEquals('09:00:00', $result['travel_time']);
        $this->assertEquals(2, $result['pax']);
        $this->assertEquals(330.00, $result['price']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertEquals('Danish', $result['language']);
    }

    /**
     * Regression: incident 2026-04-27 (GYG48YVRXWBH).
     *
     * GetYourGuide's "Urgent" / last-minute booking variant uses
     * "received a last-minute booking:" as the trigger phrase instead of
     * "has been booked:". The original parser regex was anchored on the
     * latter, so tour_name + option_title silently came back null and the
     * email landed in needs_review until manual ops backfill.
     */
    public function test_parses_urgent_last_minute_booking_variant(): void
    {
        $body = <<<'BODY'
Hi Supply Partner, great news!
You've received a last-minute booking:

Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour

Samarkand to Bukhara: 2-Day Group Yurt & Camel

Reference numbergyg48yvrxwbh

DateApril 28, 2026 8:30 AM

Number of participants1 x Adult (Age 6 - 99)

Main customerWANG TING customer-eo44ny4uby3r5nou@reply.getyourguide.com
Phone: +886987293901
Language: Traditional Chinese

Price$ 220.00open booking
BODY;

        $result = $this->parser->parseNewBooking(
            $body,
            'Urgent: New booking received - S374926 - GYG48YVRXWBH'
        );

        $this->assertEquals('GYG48YVRXWBH', $result['gyg_booking_reference']);
        $this->assertEquals('Samarkand: 2-Day Desert Yurt Camp & Camel Ride Tour', $result['tour_name']);
        $this->assertEquals('Samarkand to Bukhara: 2-Day Group Yurt & Camel', $result['option_title']);
        $this->assertEquals('WANG TING', $result['guest_name']);
        $this->assertEquals('+886987293901', $result['guest_phone']);
        $this->assertEquals('2026-04-28', $result['travel_date']);
        $this->assertEquals('08:30:00', $result['travel_time']);
        $this->assertEquals(1, $result['pax']);
        $this->assertEquals(220.00, $result['price']);
        $this->assertEquals('Traditional Chinese', $result['language']);
        $this->assertEquals('group', $result['tour_type']);
        $this->assertEquals('explicit', $result['tour_type_source']);
    }

    public function test_language_capture_preserves_multi_word_locales(): void
    {
        // Regression: previous regex Language:\s*(\w+) silently truncated
        // multi-word locales — "Traditional Chinese" → "Traditional",
        // "Brazilian Portuguese" → "Brazilian". Matters for guide assignment.
        foreach (['Traditional Chinese', 'Simplified Chinese', 'Brazilian Portuguese', 'English'] as $lang) {
            $body = str_replace('Language: Danish', "Language: {$lang}", $this->sampleBookingBody());
            $result = $this->parser->parseNewBooking($body, 'Booking - S374926 - GYGXXX');
            $this->assertEquals($lang, $result['language'], "Failed for: {$lang}");
        }
    }

    public function test_uses_canonical_field_names(): void
    {
        $result = $this->parser->parseNewBooking($this->sampleBookingBody(), 'Booking - S374926 - GYGXXX');

        // Must use canonical names, not legacy
        $this->assertArrayHasKey('travel_date', $result);
        $this->assertArrayHasKey('travel_time', $result);
        $this->assertArrayHasKey('pax', $result);
        $this->assertArrayHasKey('price', $result);
        $this->assertArrayNotHasKey('tour_date', $result);
        $this->assertArrayNotHasKey('tour_time', $result);
        $this->assertArrayNotHasKey('number_of_guests', $result);
        $this->assertArrayNotHasKey('total_price', $result);
    }

    // ── Tour type ───────────────────────────────────────

    public function test_detects_explicit_group_tour_type(): void
    {
        $result = $this->parser->parseNewBooking($this->sampleBookingBody(), 'Booking - S374926 - GYGXXX');

        // "2-Day Group Yurt & Camel" in option_title contains "Group"
        $this->assertEquals('group', $result['tour_type']);
        $this->assertEquals('explicit', $result['tour_type_source']);
    }

    public function test_defaults_to_private_when_no_group_keyword(): void
    {
        $body = str_replace('2-Day Group Yurt', '2-Day Desert Yurt', $this->sampleBookingBody());
        $result = $this->parser->parseNewBooking($body, 'Booking - S374926 - GYGXXX');

        $this->assertEquals('private', $result['tour_type']);
        $this->assertEquals('defaulted', $result['tour_type_source']);
    }

    // ── Guide status ────────────────────────────────────

    public function test_defaults_to_no_guide(): void
    {
        $result = $this->parser->parseNewBooking($this->sampleBookingBody(), 'Booking - S374926 - GYGXXX');

        // No "guide" in "Samarkand: 2-Day Desert Yurt Camp" or "2-Day Group Yurt & Camel"
        $this->assertEquals('no_guide', $result['guide_status']);
        $this->assertEquals('defaulted', $result['guide_status_source']);
    }

    public function test_detects_explicit_guide_in_option_title(): void
    {
        $body = str_replace(
            'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
            'Group Tour with Guide – Shahrisabz Day Trip',
            $this->sampleBookingBody()
        );
        $result = $this->parser->parseNewBooking($body, 'Booking - S374926 - GYGXXX');

        $this->assertEquals('with_guide', $result['guide_status']);
        $this->assertEquals('explicit', $result['guide_status_source']);
    }

    // ── Option title edge cases ─────────────────────────

    public function test_rejects_option_title_with_metadata_keyword(): void
    {
        // Replace option_title line with something that looks like metadata
        $body = str_replace(
            'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
            'Reference number details here',
            $this->sampleBookingBody()
        );
        $result = $this->parser->parseNewBooking($body, 'Booking - S374926 - GYGXXX');

        $this->assertNull($result['option_title']);
        $this->assertNotEmpty($result['parse_errors']);
    }

    public function test_rejects_option_title_too_short(): void
    {
        $body = str_replace(
            'Samarkand to Bukhara: 2-Day Group Yurt & Camel',
            'abc',
            $this->sampleBookingBody()
        );
        $result = $this->parser->parseNewBooking($body, 'Booking - S374926 - GYGXXX');

        $this->assertNull($result['option_title']);
    }

    // ── Validation ──────────────────────────────────────

    public function test_new_booking_validation_requires_five_fields(): void
    {
        $missing = $this->parser->validateRequired('new_booking', [
            'gyg_booking_reference' => 'GYGXXX',
            'tour_name'             => 'Tour',
            'option_title'          => 'Option',
            'travel_date'           => '2026-04-19',
            'pax'                   => 2,
        ]);
        $this->assertEmpty($missing);
    }

    public function test_new_booking_validation_fails_on_missing_option_title(): void
    {
        $missing = $this->parser->validateRequired('new_booking', [
            'gyg_booking_reference' => 'GYGXXX',
            'tour_name'             => 'Tour',
            'option_title'          => null,
            'travel_date'           => '2026-04-19',
            'pax'                   => 2,
        ]);
        $this->assertContains('option_title', $missing);
    }

    public function test_cancellation_validation_requires_reference_only(): void
    {
        $missing = $this->parser->validateRequired('cancellation', [
            'gyg_booking_reference' => 'GYGXXX',
        ]);
        $this->assertEmpty($missing);

        $missing2 = $this->parser->validateRequired('cancellation', [
            'gyg_booking_reference' => null,
        ]);
        $this->assertContains('gyg_booking_reference', $missing2);
    }

    // ── Cancellation parsing ────────────────────────────

    public function test_parses_cancellation(): void
    {
        $body = <<<'BODY'
Hi supply partner,

We're writing to let you know that the following booking has been canceled.
Reference Number: GYGWZBBA7MMR
Name: Søren Sørit
Date: April 29, 2026, 4:00 AM
Tour: From Samarkand: Shahrisabz Day Trip & Mountain Pass Tour
Tour Option: Group Tour with Guide – Shahrisabz Day Trip
Please remove this customer from your list.
BODY;

        $result = $this->parser->parseCancellation($body, 'A booking has been canceled - S374926 - GYGWZBBA7MMR');

        $this->assertEquals('GYGWZBBA7MMR', $result['gyg_booking_reference']);
        $this->assertStringContainsString('Søren', $result['guest_name']);
        $this->assertEquals('2026-04-29', $result['travel_date']);
        $this->assertStringContainsString('Shahrisabz', $result['tour_name']);
        $this->assertStringContainsString('Group Tour with Guide', $result['option_title']);
    }
}
