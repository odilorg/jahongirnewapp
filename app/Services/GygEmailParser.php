<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic field extractor for GYG booking emails.
 * Uses anchored regex patterns against the plain-text email body.
 * No AI. If a required field cannot be safely extracted, returns null
 * and lets the caller decide whether to mark as needs_review.
 */
class GygEmailParser
{
    /**
     * Extract fields from a new booking email.
     *
     * @return array{
     *   gyg_booking_reference: ?string,
     *   tour_name: ?string,
     *   option_title: ?string,
     *   guest_name: ?string,
     *   guest_email: ?string,
     *   guest_phone: ?string,
     *   tour_date: ?string,
     *   tour_time: ?string,
     *   number_of_guests: ?int,
     *   total_price: ?float,
     *   currency: ?string,
     *   language: ?string,
     *   tour_type: ?string,
     *   tour_type_source: ?string,
     *   guide_status: ?string,
     *   guide_status_source: ?string,
     *   parse_errors: string[],
     * }
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
            'tour_date'             => null,
            'tour_time'             => null,
            'number_of_guests'      => null,
            'total_price'           => null,
            'currency'              => null,
            'language'              => null,
            'tour_type'             => null,
            'tour_type_source'      => null,
            'guide_status'          => null,
            'guide_status_source'   => null,
            'parse_errors'          => [],
        ];

        // --- Booking reference ---
        // From subject: "Booking - S374926 - GYGZGZ5XLFNQ"
        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }
        // Fallback: from body "Reference numbergygzgz5xlfnq" (no space in plain text render)
        if (! $result['gyg_booking_reference'] && preg_match('/Reference\s*number\s*([a-z0-9]+)/i', $body, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        // --- Tour name + Option title ---
        // Pattern: "Your offer has been booked:\n\n<Tour Name>\n\n<Option Title>"
        // OR plain text renders as: "Your offer has been booked:\n\n<Tour>\n\n<Option>"
        if (preg_match('/(?:has been booked|great news!)[:\s]*\n+(.+?)(?:\n+(.+?))?\n+(?:Reference|Booking reference)/si', $body, $m)) {
            $result['tour_name'] = $this->cleanLine($m[1]);
            if (! empty($m[2])) {
                $result['option_title'] = $this->cleanLine($m[2]);
            }
        }

        // --- Date + Time ---
        // Pattern: "DateApril 19, 2026 9:00 AM" (no space after "Date" in plain text)
        // Or: "Date\nApril 19, 2026 9:00 AM"
        if (preg_match('/Date\s*\n?\s*([A-Z][a-z]+ \d{1,2},? \d{4}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM))?)/i', $body, $m)) {
            $dateStr = trim($m[1]);
            try {
                $parsed = Carbon::parse($dateStr);
                $result['tour_date'] = $parsed->format('Y-m-d');
                $result['tour_time'] = $parsed->format('H:i:s');
            } catch (\Exception $e) {
                $errors[] = "Could not parse date: {$dateStr}";
            }
        }

        // --- Participants ---
        // Pattern: "Number of participantsN x Adults (Age 0 - 99)"
        // Or: "Number of participants\n2 x Adults"
        if (preg_match('/Number of participants\s*\n?\s*(\d+)\s*x/i', $body, $m)) {
            $result['number_of_guests'] = (int) $m[1];
        }

        // --- Customer name ---
        // Pattern: "Main customerKatrine Arps Studskjær customer-..."
        // Or: "Main customer\nName Surname customer-..."
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
        // Pattern: "Price$ 330.00" or "Price€ 150.00" or "Price\n$ 330.00"
        if (preg_match('/Price\s*\n?\s*([€$£¥])\s*([\d,]+\.?\d*)/i', $body, $m)) {
            $currencyMap = ['$' => 'USD', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY'];
            $result['currency'] = $currencyMap[$m[1]] ?? $m[1];
            $result['total_price'] = (float) str_replace(',', '', $m[2]);
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
            'tour_date'             => null,
            'tour_name'             => null,
            'option_title'          => null,
            'parse_errors'          => [],
        ];

        // Reference from subject
        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }
        // Fallback from body: "Reference Number: GYGWZBBA7MMR"
        if (! $result['gyg_booking_reference'] && preg_match('/Reference\s*Number:\s*([A-Z0-9]+)/i', $body, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        // "Name: Søren Sørit"
        if (preg_match('/Name:\s*(.+?)(?:Date:|$)/si', $body, $m)) {
            $result['guest_name'] = $this->cleanLine($m[1]);
        }

        // "Date: April 29, 2026, 4:00 AM"
        if (preg_match('/Date:\s*([A-Z][a-z]+ \d{1,2},? \d{4})/i', $body, $m)) {
            try {
                $result['tour_date'] = Carbon::parse(trim($m[1]))->format('Y-m-d');
            } catch (\Exception $e) {
                $result['parse_errors'][] = "Could not parse cancellation date";
            }
        }

        // "Tour: From Samarkand: Shahrisabz Day Trip & Mountain Pass Tour"
        if (preg_match('/Tour:\s*(.+?)(?:Tour Option:|Please remove|$)/si', $body, $m)) {
            $result['tour_name'] = $this->cleanLine($m[1]);
        }

        // "Tour Option: Group Tour with Guide – Shahrisabz Day Trip"
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
            'tour_date'             => null,
            'parse_errors'          => [],
        ];

        // Reference from subject
        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $m)) {
            $result['gyg_booking_reference'] = strtoupper($m[1]);
        }

        // Tour name + option: lines after "the following booking has changed"
        if (preg_match('/has changed\.?\s*\n+(.+?)(?:\n+(.+?))?\n+(?:Booking reference|Date)/si', $body, $m)) {
            $result['tour_name'] = $this->cleanLine($m[1]);
            if (! empty($m[2])) {
                $result['option_title'] = $this->cleanLine($m[2]);
            }
        }

        // Date
        if (preg_match('/Date\s*\n?\s*([A-Z][a-z]+ \d{1,2},? \d{4}(?:\s+(?:at\s+)?\d{1,2}:\d{2}\s*(?:AM|PM))?)/i', $body, $m)) {
            try {
                $parsed = Carbon::parse(str_replace(' at ', ' ', trim($m[1])));
                $result['tour_date'] = $parsed->format('Y-m-d');
            } catch (\Exception $e) {
                $result['parse_errors'][] = "Could not parse amendment date";
            }
        }

        return $result;
    }

    /**
     * Validate required fields for a given email type.
     *
     * @return string[] List of missing required fields (empty = valid)
     */
    public function validateRequired(string $emailType, array $extracted): array
    {
        $rules = [
            'new_booking'  => ['gyg_booking_reference', 'guest_name', 'tour_date'],
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

    private function cleanLine(string $text): string
    {
        // Remove URLs, tracking links, excessive whitespace
        $text = preg_replace('/\(https?:\/\/[^\)]+\)/', '', $text);
        $text = preg_replace('/https?:\/\/\S+/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
