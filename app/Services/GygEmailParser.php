<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Deterministic field extractor for GYG booking emails.
 * Uses anchored regex patterns against the plain-text email body.
 * No AI. If a required field cannot be safely extracted, returns null
 * and lets the caller decide whether to mark as needs_review.
 *
 * Canonical field names (PM-approved):
 *   travel_date, travel_time, pax, price, currency
 */
class GygEmailParser
{
    /** Metadata keywords that disqualify a line from being option_title */
    private const METADATA_KEYWORDS = [
        'reference', 'date', 'number of', 'main customer', 'phone',
        'price', 'language', 'open booking', 'we\'re here', 'contact',
        'participants', 'booking reference',
    ];

    /**
     * Extract fields from a new booking email.
     */
    public function parseNewBooking(string $body, string $subject): array
    {
        $errors = [];
        $result = [
            'gyg_booking_reference' => null,
            'tour_name'             => null,
            'option_title'          => null,
            'guest_name'            => null,
            'guest_email'           => null,
            'guest_phone'           => null,
            'travel_date'           => null,
            'travel_time'           => null,
            'pax'                   => null,
            'price'                 => null,
            'currency'              => null,
            'language'              => null,
            'tour_type'             => null,
            'tour_type_source'      => null,
            'guide_status'          => null,
            'guide_status_source'   => null,
            'parse_errors'          => [],
        ];

        // --- Booking reference ---
        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }
        if (! $result['gyg_booking_reference'] && preg_match('/Reference\s*number\s*([a-z0-9]+)/i', $body, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        // --- Tour name + Option title ---
        // GYG booking emails have this structure in the body:
        //   "Your offer has been booked:" or "great news!"
        //   <blank line>
        //   <Tour Product Title>
        //   <blank line>
        //   <Option/Variant Title>
        //   <blank line>
        //   "Reference number..." or "Booking reference..."
        //
        // We extract both lines and then validate the second line is truly
        // an option title, not a metadata label that ran together.
        // Use [^\r\n]+ (single-line capture) and explicit \r?\n\r?\n (blank line) to handle
        // both CRLF (real GYG emails) and LF (test fixtures). Avoids the (.+?) + s-flag
        // backtracking bug where "Your offer has been booked:" was swallowed as tour_name
        // instead of the actual tour title when the email had two header lines before it.
        if (preg_match('/has been booked[:\s]*\r?\n\r?\n([^\r\n]+)\r?\n\r?\n([^\r\n]+)\r?\n\r?\n(?:Reference|Booking reference)/i', $body, $m)) {
            $line1 = $this->cleanLine($m[1]);
            $line2 = $this->cleanLine($m[2]);

            $result['tour_name'] = $line1;

            // Validate line2 is a real option title, not metadata
            if ($this->isValidOptionTitle($line2)) {
                $result['option_title'] = $line2;
            } else {
                $errors[] = "option_title candidate rejected (looks like metadata): " . mb_substr($line2, 0, 60);
            }
        } elseif (preg_match('/has been booked[:\s]*\r?\n\r?\n([^\r\n]+)\r?\n\r?\n(?:Reference|Booking reference)/i', $body, $m)) {
            // Only tour_name found, no option_title line
            $result['tour_name'] = $this->cleanLine($m[1]);
            $errors[] = "option_title not found in expected position";
        }

        // --- Date + Time ---
        if (preg_match('/Date\s*\n?\s*([A-Z][a-z]+ \d{1,2},? \d{4}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM))?)/i', $body, $m)) {
            try {
                $parsed = Carbon::parse(trim($m[1]));
                $result['travel_date'] = $parsed->format('Y-m-d');
                $result['travel_time'] = $parsed->format('H:i:s');
            } catch (\Exception $e) {
                $errors[] = "Could not parse date: " . trim($m[1]);
            }
        }

        // --- Participants ---
        if (preg_match('/Number of participants\s*\n?\s*(\d+)\s*x/i', $body, $m)) {
            $result['pax'] = (int) $m[1];
        }

        // --- Customer name ---
        if (preg_match('/Main\s*customer\s*\n?\s*(.+?)\s*(?:customer-|[a-z0-9._+-]+@)/i', $body, $m)) {
            $result['guest_name'] = $this->cleanLine($m[1]);
        }

        // --- Customer email ---
        if (preg_match('/(customer-[a-z0-9]+@reply\.getyourguide\.com)/i', $body, $m)) {
            $result['guest_email'] = strtolower($m[1]);
        }

        // --- Phone ---
        if (preg_match('/Phone:\s*(\+[\d\s]+)/i', $body, $m)) {
            $result['guest_phone'] = preg_replace('/\s+/', '', trim($m[1]));
        }

        // --- Price + Currency ---
        if (preg_match('/Price\s*\n?\s*([€$£¥])\s*([\d,]+\.?\d*)/i', $body, $m)) {
            $currencyMap = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY'];
            $result['currency'] = $currencyMap[$m[1]] ?? $m[1];
            $result['price'] = (float) str_replace(',', '', $m[2]);
        }

        // --- Language ---
        if (preg_match('/Language:\s*(\w+)/i', $body, $m)) {
            $result['language'] = trim($m[1]);
        }

        // --- Tour type (group vs private) ---
        $titleContext = strtolower(($result['tour_name'] ?? '') . ' ' . ($result['option_title'] ?? ''));
        if (preg_match('/\bgroup\b/i', $titleContext)) {
            $result['tour_type'] = 'group';
            $result['tour_type_source'] = 'explicit';
        } else {
            $result['tour_type'] = 'private';
            $result['tour_type_source'] = 'defaulted';
        }

        // --- Guide status ---
        if (preg_match('/\b(?:guide|guided|with guide|local guide)\b/i', $titleContext)) {
            $result['guide_status'] = 'with_guide';
            $result['guide_status_source'] = 'explicit';
        } else {
            $result['guide_status'] = 'no_guide';
            $result['guide_status_source'] = 'defaulted';
        }

        $result['parse_errors'] = $errors;
        return $result;
    }

    /**
     * Extract fields from a cancellation email.
     */
    public function parseCancellation(string $body, string $subject): array
    {
        $result = [
            'gyg_booking_reference' => null,
            'guest_name'            => null,
            'travel_date'           => null,
            'tour_name'             => null,
            'option_title'          => null,
            'parse_errors'          => [],
        ];

        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }
        if (! $result['gyg_booking_reference'] && preg_match('/Reference\s*Number:\s*([A-Z0-9]+)/i', $body, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        if (preg_match('/Name:\s*(.+?)(?:Date:|$)/si', $body, $m)) {
            $result['guest_name'] = $this->cleanLine($m[1]);
        }

        if (preg_match('/Date:\s*([A-Z][a-z]+ \d{1,2},? \d{4})/i', $body, $m)) {
            try {
                $result['travel_date'] = Carbon::parse(trim($m[1]))->format('Y-m-d');
            } catch (\Exception $e) {
                $result['parse_errors'][] = "Could not parse cancellation date";
            }
        }

        if (preg_match('/Tour:\s*(.+?)(?:Tour Option:|Please remove|$)/si', $body, $m)) {
            $result['tour_name'] = $this->cleanLine($m[1]);
        }

        if (preg_match('/Tour Option:\s*(.+?)(?:Please remove|Best regards|$)/si', $body, $m)) {
            $result['option_title'] = $this->cleanLine($m[1]);
        }

        return $result;
    }

    /**
     * Extract fields from an amendment email.
     */
    public function parseAmendment(string $body, string $subject): array
    {
        $result = [
            'gyg_booking_reference' => null,
            'tour_name'             => null,
            'option_title'          => null,
            'travel_date'           => null,
            'parse_errors'          => [],
        ];

        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        if (preg_match('/has changed\.?\s*\n+(.+?)\n+(.+?)\n+(?:Booking reference|Date)/si', $body, $m)) {
            $result['tour_name'] = $this->cleanLine($m[1]);
            $line2 = $this->cleanLine($m[2]);
            if ($this->isValidOptionTitle($line2)) {
                $result['option_title'] = $line2;
            }
        }

        if (preg_match('/Date\s*\n?\s*([A-Z][a-z]+ \d{1,2},? \d{4}(?:\s+(?:at\s+)?\d{1,2}:\d{2}\s*(?:AM|PM))?)/i', $body, $m)) {
            try {
                $parsed = Carbon::parse(str_replace(' at ', ' ', trim($m[1])));
                $result['travel_date'] = $parsed->format('Y-m-d');
            } catch (\Exception $e) {
                $result['parse_errors'][] = "Could not parse amendment date";
            }
        }

        return $result;
    }

    /**
     * Validate required fields for a given email type.
     *
     * new_booking required: gyg_booking_reference, tour_name, option_title, travel_date, pax
     * cancellation required: gyg_booking_reference
     * amendment required: gyg_booking_reference
     *
     * @return string[] List of missing required fields (empty = valid)
     */
    public function validateRequired(string $emailType, array $extracted): array
    {
        $rules = [
            'new_booking'  => ['gyg_booking_reference', 'tour_name', 'option_title', 'travel_date', 'pax'],
            'cancellation' => ['gyg_booking_reference'],
            'amendment'    => ['gyg_booking_reference'],
        ];

        $required = $rules[$emailType] ?? [];
        $missing  = [];

        foreach ($required as $field) {
            if (empty($extracted[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Check if a candidate line is a valid option/variant title,
     * not a metadata label that ran together in plain text.
     */
    private function isValidOptionTitle(string $line): bool
    {
        $lower = strtolower($line);

        // Reject if it contains metadata keywords
        foreach (self::METADATA_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return false;
            }
        }

        // Reject if too short (likely a parsing artifact)
        if (mb_strlen($line) < 5) {
            return false;
        }

        // Reject if it looks like a URL
        if (str_starts_with($lower, 'http')) {
            return false;
        }

        return true;
    }

    private function cleanLine(string $text): string
    {
        $text = preg_replace('/\(https?:\/\/[^\)]+\)/', '', $text);
        $text = preg_replace('/https?:\/\/\S+/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
