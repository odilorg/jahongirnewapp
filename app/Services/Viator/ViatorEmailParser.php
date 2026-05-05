<?php

declare(strict_types=1);

namespace App\Services\Viator;

use App\Models\ViatorInboundEmail;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Parses Viator booking-event emails into a normalised payload.
 *
 * Three event types share the same general "Field: Value" body shape
 * but differ in which fields are present and a few label spellings.
 * The parser detects type from subject + body cues, then walks a
 * type-specific allow-list of labels to populate the payload.
 *
 * Schema observations encoded:
 *   - "Lead Traveler Name" (new/cancelled) vs "Lead traveler name"
 *     (amendment) — case-insensitive label matching is mandatory
 *   - "Tour Grade" (new) vs "Tour Option" (cancelled) — both flatten
 *     to payload['tour_grade']
 *   - Cancellation subjects DON'T carry the BR ref — must read body
 *   - Net Rate is new-only; never present on amendment/cancellation
 *   - Amendment carries a "what changed" delta block we capture in
 *     payload['amendment_delta_lines'] for operator review
 *
 * The parser does NOT touch the database — it returns a payload the
 * fetch command persists. Pure logic, fully unit-testable.
 */
class ViatorEmailParser
{
    /**
     * @return array{
     *   email_type: string,
     *   external_reference: ?string,
     *   parsed_payload: array<string, mixed>,
     * }
     */
    public function parse(string $subject, string $body): array
    {
        $cleanBody = $this->normaliseBody($body);
        $type      = $this->detectType($subject, $cleanBody);
        $br        = $this->extractBookingReference($subject, $cleanBody);

        $payload = match ($type) {
            ViatorInboundEmail::TYPE_NEW       => $this->parseNew($cleanBody),
            ViatorInboundEmail::TYPE_AMENDED   => $this->parseAmended($cleanBody),
            ViatorInboundEmail::TYPE_CANCELLED => $this->parseCancelled($cleanBody),
            default                             => [],
        };

        // Keep the booking reference at the top level so consumers can
        // index without descending into payload internals.
        $payload['booking_reference'] = $br;

        return [
            'email_type'         => $type,
            'external_reference' => $br,
            'parsed_payload'     => $payload,
        ];
    }

    // ──────────────────────────────────────────────
    // Type detection
    // ──────────────────────────────────────────────

    private function detectType(string $subject, string $body): string
    {
        $subjectLower = strtolower($subject);
        $bodyLower    = strtolower($body);

        if (str_starts_with($subjectLower, 'cancelled booking')
            || str_contains($bodyLower, 'booking canceled')
            || str_contains($bodyLower, 'booking cancelled')) {
            return ViatorInboundEmail::TYPE_CANCELLED;
        }

        if (str_starts_with($subjectLower, 'amended booking')
            || str_contains($bodyLower, 'booking amended')
            || str_contains($bodyLower, 'booking has been amended')) {
            return ViatorInboundEmail::TYPE_AMENDED;
        }

        if (str_starts_with($subjectLower, 'new booking')
            || str_contains($bodyLower, 'booking confirmation')) {
            return ViatorInboundEmail::TYPE_NEW;
        }

        return ViatorInboundEmail::TYPE_UNKNOWN;
    }

    // ──────────────────────────────────────────────
    // Reference extraction
    // ──────────────────────────────────────────────

    private function extractBookingReference(string $subject, string $body): ?string
    {
        // Subject form: "(#BR-1390901059)" — preferred (new + amended)
        if (preg_match('/#?(BR-\d{6,})/', $subject, $m)) {
            return $m[1];
        }
        // Body form: "Booking Reference: BR-..." or "#BR-..."
        if (preg_match('/Booking Reference:\s*#?(BR-\d{6,})/i', $body, $m)) {
            return $m[1];
        }
        return null;
    }

    // ──────────────────────────────────────────────
    // Per-type parsers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function parseNew(string $body): array
    {
        $fields = $this->extractLabelledFields($body);

        return [
            'lead_traveler_name'      => $fields['lead_traveler_name'] ?? null,
            'traveler_names'          => $this->splitTravelerNames($fields['traveler_names'] ?? null),
            'travelers_summary'       => $fields['travelers'] ?? null,
            'people_adults'           => $this->countAdults($fields['travelers'] ?? null),
            'people_children'         => $this->countChildren($fields['travelers'] ?? null),
            'product_code'            => $fields['product_code'] ?? null,
            'tour_grade'              => $fields['tour_grade'] ?? $fields['tour_option'] ?? null,
            'tour_grade_code'         => $fields['tour_grade_code'] ?? null,
            'tour_grade_description'  => $this->stripHtmlTags($fields['tour_grade_description'] ?? null),
            'tour_language'           => $fields['tour_language'] ?? null,
            'tour_name'               => $this->extractTourName($body),
            'travel_date'             => $this->parseTravelDate($fields['travel_date'] ?? null),
            'travel_date_raw'         => $fields['travel_date'] ?? null,
            'location'                => $fields['location'] ?? null,
            'net_rate_currency'       => $this->parseNetRateCurrency($fields['net_rate'] ?? null),
            'net_rate_amount'         => $this->parseNetRateAmount($fields['net_rate'] ?? null),
            'hotel_pickup'            => $fields['hotel_pickup'] ?? null,
            'meeting_point'           => $fields['meeting_point'] ?? null,
            'pickup_location'         => $fields['pick_up_location'] ?? null,
            'departure_airline'       => $fields['departure_airline'] ?? null,
            'departure_time'          => $fields['departure_time'] ?? null,
            'departure_date'          => $fields['departure_date'] ?? null,
            'departure_flight_no'     => $fields['departure_flight_no'] ?? null,
            'special_requirements'    => $fields['special_requirements'] ?? null,
            'phone'                   => $this->cleanPhoneField($fields['phone'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAmended(string $body): array
    {
        $fields = $this->extractLabelledFields($body);

        return [
            'lead_traveler_name'   => $fields['lead_traveler_name'] ?? null,
            'product_code'         => $fields['product_code'] ?? null,
            'tour_name'            => $this->extractTourName($body),
            'travel_date'          => $this->parseTravelDate($fields['travel_date'] ?? null),
            'travel_date_raw'      => $fields['travel_date'] ?? null,
            'location'             => $fields['location'] ?? null,
            'hotel_pickup'         => $fields['hotel_pickup'] ?? null,
            'special_requirements' => $fields['special_requirements'] ?? null,
            'phone'                => $this->cleanPhoneField($fields['phone'] ?? null),
            'amendment_delta'      => $this->extractAmendmentDelta($body),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCancelled(string $body): array
    {
        $fields = $this->extractLabelledFields($body);

        return [
            'lead_traveler_name' => $fields['lead_traveler_name'] ?? null,
            'tour_name'          => $this->extractTourName($body),
            'tour_grade'         => $fields['tour_grade'] ?? $fields['tour_option'] ?? null,
            'travel_date'        => $this->parseTravelDate($fields['travel_date'] ?? null),
            'travel_date_raw'    => $fields['travel_date'] ?? null,
            'location'           => $fields['location'] ?? null,
            'travelers_summary'  => $fields['travelers'] ?? null,
            'people_adults'      => $this->countAdults($fields['travelers'] ?? null),
            'people_children'    => $this->countChildren($fields['travelers'] ?? null),
        ];
    }

    // ──────────────────────────────────────────────
    // Field extraction helpers
    // ──────────────────────────────────────────────

    /**
     * Walks the body looking for "Label: Value" pairs and returns them
     * keyed by snake_case_label. Case-insensitive on the label side so
     * "Lead Traveler Name" and "Lead traveler name" land on the same key.
     *
     * Stops a value when the next "X: " label-shape appears OR when a
     * URL marker `(http` opens.
     *
     * @return array<string, string>
     */
    private function extractLabelledFields(string $body): array
    {
        $known = [
            'Booking Reference',
            'Lead Traveler Name',
            'Lead traveler name',
            'Traveler Names',
            'Travelers',
            'Product Code',
            'Tour Name',
            'Tour Grade',
            'Tour Grade Code',
            'Tour Grade Description',
            'Tour Option',
            'Tour Language',
            'Travel Date',
            'Location',
            'Net Rate',
            'Hotel Pickup',
            'Meeting Point',
            'Pick up Location',
            'Departure Airline',
            'Departure Time',
            'Departure Date',
            'Departure Flight No',
            'Special Requirements',
            'Phone',
        ];

        $out = [];
        foreach ($known as $label) {
            // Match label exactly (case-insensitive), capture up to the
            // next known-label OR ' (http' OR 2+ consecutive whitespace
            // followed by another label-shape "Word: ".
            $escaped = preg_quote($label, '/');
            $pattern = '/' . $escaped . ':\s*(.+?)(?='
                . '\s+(?:Booking Reference|Lead [Tt]raveler [Nn]ame|Traveler Names|Travelers|Product Code|Tour Name|Tour Grade|Tour Grade Code|Tour Grade Description|Tour Option|Tour Language|Travel Date|Location|Net Rate|Hotel Pickup|Meeting Point|Pick up Location|Departure Airline|Departure Time|Departure Date|Departure Flight No|Special Requirements|Phone|Optional|Have questions):'
                . '|\s+\(http'
                . '|\s+send the customer a message'
                . '|$)/iu';

            if (preg_match($pattern, $body, $m)) {
                $key = strtolower(str_replace([' ', '-'], '_', $label));
                $value = trim($m[1]);
                // Don't let two distinct labels overwrite each other —
                // keep the first non-empty match (case duplicates of
                // Lead Traveler Name fold to the same key).
                if (! isset($out[$key]) || $out[$key] === '') {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Tour Name appears as a free-text sentence right after one of:
     *   "You have a new reservation for "
     *   "The following booking for "
     *   "...has been amended."
     * No label, but always between known anchors.
     */
    private function extractTourName(string $body): ?string
    {
        // Canonical source: the labeled "Tour Name:" field in the
        // "Booking Details" section. Immune to the subject-preamble
        // collision that broke BR-1393592315 (audit 2026-05-05) — the email
        // repeats "New Booking for <date>..." near the top, which the old
        // "|booking for" alternative greedily matched before reaching the
        // real reservation phrase further down.
        if (preg_match('/Tour Name:\s+(.+?)\s+(?:Travel Date|Tour Grade|Lead Traveler|Location|Product Code):/u', $body, $m)) {
            return trim($m[1]);
        }
        // Fallback for older Viator templates without the explicit field.
        // Note: do NOT add "|booking for" — that alternation matched the
        // subject-line preamble and captured date/booking-ref junk.
        if (preg_match('/(?:You have a new reservation for|reservation for)\s+(.+?)(?:\.|This is an Instant)/iu', $body, $m)) {
            return trim($m[1]);
        }
        // Cancellation body has tour name as bare line right after
        // "Booking Reference: #BR-XXX     Canceled     <tour name>     Tour Option:..."
        if (preg_match('/Canceled\s+(.+?)\s+(?:Tour Option|Tour Grade|Location):/u', $body, $m)) {
            return trim($m[1]);
        }
        // Amendment body: "Amended     <tour name>     Location:..."
        if (preg_match('/Amended\s+(.+?)\s+Location:/u', $body, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Amendment delta block: "• Pickup point type changed from [X] to [Y]."
     * lines that appear before the "Booking Details" section.
     *
     * @return array<int, string>
     */
    private function extractAmendmentDelta(string $body): array
    {
        $lines = [];
        if (preg_match('/Booking Amended\s+The following booking.*?has been amended\.\s*Here are the changes:\s*(.+?)(?:Booking Details|$)/su', $body, $m)) {
            $block = $m[1];
            // Split on bullet chars
            foreach (preg_split('/\s*•\s*/u', $block) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $lines[] = $part;
                }
            }
        }
        return $lines;
    }

    private function splitTravelerNames(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn ($n) => $n !== '',
        ));
    }

    private function countAdults(?string $travelersSummary): int
    {
        if (! $travelersSummary) {
            return 0;
        }
        if (preg_match('/(\d+)\s+Adult/iu', $travelersSummary, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function countChildren(?string $travelersSummary): int
    {
        if (! $travelersSummary) {
            return 0;
        }
        if (preg_match('/(\d+)\s+Child/iu', $travelersSummary, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function parseTravelDate(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        // Format observed: "Sun, Sep 20, 2026"
        try {
            $cleaned = preg_replace('/^[A-Za-z]+,\s*/u', '', $raw) ?? $raw;
            return Carbon::createFromFormat('M d, Y', trim($cleaned))->toDateString();
        } catch (\Throwable) {
            try {
                return Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function parseNetRateCurrency(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        // "USD $97.50" → "USD"
        if (preg_match('/^([A-Z]{3})\s/', trim($raw), $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseNetRateAmount(?string $raw): ?float
    {
        if (! $raw) {
            return null;
        }
        if (preg_match('/[\d,]+\.\d{2}|[\d,]+/', $raw, $m)) {
            return (float) str_replace(',', '', $m[0]);
        }
        return null;
    }

    private function cleanPhoneField(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        // "(Alternate Phone)+1000000000  send the customer..." — strip
        // the prefix marker and any trailing call-to-action text.
        $cleaned = preg_replace('/\(Alternate Phone\)\s*/u', '', $raw) ?? $raw;
        $cleaned = preg_replace('/\s+send the customer.*$/u', '', $cleaned) ?? $cleaned;
        return trim($cleaned) ?: null;
    }

    private function stripHtmlTags(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }
        return trim(strip_tags($raw));
    }

    /**
     * Normalise himalaya's MIME wrappers + collapse whitespace so the
     * regex extraction works on a single uniform stream.
     */
    private function normaliseBody(string $body): string
    {
        // Drop the himalaya `<#part type=...>` markers.
        $clean = preg_replace('/<#\/?part[^>]*>/u', '', $body) ?? $body;
        // Collapse repeated whitespace to single spaces — Viator's HTML
        // emails come through with tabs and multiple newlines around
        // every value, which breaks naive regex bounds.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        return trim($clean);
    }
}
