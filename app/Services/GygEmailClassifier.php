<?php

namespace App\Services;

/**
 * Classify inbound GYG emails by subject pattern.
 * No AI, no body inspection — subject-only classification.
 */
class GygEmailClassifier
{
    /**
     * @return 'new_booking'|'cancellation'|'amendment'|'guest_reply'|'unknown'
     */
    public function classify(string $subject, string $fromAddress): string
    {
        $subject = trim($subject);

        // Order matters: most specific first

        // "A booking has been canceled - S374926 - GYGWZBBA7MMR"
        if (preg_match('/\bbooking has been cancel/i', $subject)) {
            return 'cancellation';
        }

        // "Booking detail change: - S374926 - GYG6H8GK23WV"
        if (preg_match('/\bbooking detail change/i', $subject)) {
            return 'amendment';
        }

        // "Booking - S374926 - GYGZGZ5XLFNQ"
        if (preg_match('/^Booking\s*-\s*S\d+\s*-\s*GYG/i', $subject)) {
            return 'new_booking';
        }

        // Guest replies via GYG messaging: "Re: ..." from reply.getyourguide.com
        if (
            preg_match('/^Re:/i', $subject) &&
            str_contains(strtolower($fromAddress), 'reply.getyourguide.com')
        ) {
            return 'guest_reply';
        }

        return 'unknown';
    }

    /**
     * Extract GYG booking reference from email subject.
     * Works for all email types that include it in the subject line.
     */
    public function extractReferenceFromSubject(string $subject): ?string
    {
        // Match "GYG" followed by alphanumeric characters (e.g., GYGZGZ5XLFNQ)
        if (preg_match('/\b(GYG[A-Z0-9]{8,})\b/i', $subject, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
