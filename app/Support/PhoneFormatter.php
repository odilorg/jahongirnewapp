<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Display-only phone formatting. Raw phone stays in DB untouched.
 *
 * Rules:
 *   +998XXXXXXXXX → +998 XX XXX XX XX (Uzbek)
 *   +XXXXXXXXXXX  → +X XXX XXX XX XX  (generic international)
 *   anything else → returned unchanged
 */
class PhoneFormatter
{
    /**
     * Format a phone number for human-readable display.
     */
    public static function format(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $cleaned = preg_replace('/[^\d+]/', '', $raw);

        if ($cleaned === '' || ! str_starts_with($cleaned, '+')) {
            return $raw; // can't format, return original
        }

        $digits = ltrim($cleaned, '+');

        // Uzbek: +998 XX XXX XX XX
        if (str_starts_with($digits, '998') && strlen($digits) === 12) {
            return sprintf('+998 %s %s %s %s',
                substr($digits, 3, 2),
                substr($digits, 5, 3),
                substr($digits, 8, 2),
                substr($digits, 10, 2),
            );
        }

        // Generic international: try to group sensibly
        // Country codes: 1 digit (US/CA), 2 digits (most), 3 digits (some)
        $len = strlen($digits);

        if ($len < 7) {
            return $raw; // too short to format
        }

        // Detect country code length by first digits
        $ccLen = match (true) {
            str_starts_with($digits, '1')   => 1,  // North America
            str_starts_with($digits, '7')   => 1,  // Russia/Kazakhstan
            str_starts_with($digits, '20')  => 2,
            str_starts_with($digits, '27')  => 2,
            str_starts_with($digits, '30')  => 2,
            str_starts_with($digits, '31')  => 2,
            str_starts_with($digits, '32')  => 2,
            str_starts_with($digits, '33')  => 2,
            str_starts_with($digits, '34')  => 2,
            str_starts_with($digits, '36')  => 2,
            str_starts_with($digits, '39')  => 2,
            str_starts_with($digits, '40')  => 2,
            str_starts_with($digits, '41')  => 2,
            str_starts_with($digits, '43')  => 2,
            str_starts_with($digits, '44')  => 2,
            str_starts_with($digits, '45')  => 2,
            str_starts_with($digits, '46')  => 2,
            str_starts_with($digits, '47')  => 2,
            str_starts_with($digits, '48')  => 2,
            str_starts_with($digits, '49')  => 2,
            str_starts_with($digits, '51')  => 2,
            str_starts_with($digits, '52')  => 2,
            str_starts_with($digits, '54')  => 2,
            str_starts_with($digits, '55')  => 2,
            str_starts_with($digits, '56')  => 2,
            str_starts_with($digits, '57')  => 2,
            str_starts_with($digits, '58')  => 2,
            str_starts_with($digits, '60')  => 2,
            str_starts_with($digits, '61')  => 2,
            str_starts_with($digits, '62')  => 2,
            str_starts_with($digits, '63')  => 2,
            str_starts_with($digits, '64')  => 2,
            str_starts_with($digits, '65')  => 2,
            str_starts_with($digits, '66')  => 2,
            str_starts_with($digits, '81')  => 2,
            str_starts_with($digits, '82')  => 2,
            str_starts_with($digits, '84')  => 2,
            str_starts_with($digits, '86')  => 2,
            str_starts_with($digits, '90')  => 2,
            str_starts_with($digits, '91')  => 2,
            str_starts_with($digits, '92')  => 2,
            str_starts_with($digits, '93')  => 2,
            str_starts_with($digits, '94')  => 2,
            str_starts_with($digits, '95')  => 2,
            default                          => 3,
        };

        $cc   = substr($digits, 0, $ccLen);
        $rest = substr($digits, $ccLen);

        // Group the rest in chunks of 3 then 2 from left
        $groups = [];
        $pos = 0;
        $restLen = strlen($rest);
        while ($pos < $restLen) {
            $remaining = $restLen - $pos;
            // Use groups of 3 while > 4 chars left, then 2s
            $chunkSize = $remaining > 4 ? 3 : 2;
            if ($remaining <= 3) {
                $chunkSize = $remaining;
            }
            $groups[] = substr($rest, $pos, $chunkSize);
            $pos += $chunkSize;
        }

        return '+' . $cc . ' ' . implode(' ', $groups);
    }

    /**
     * Return the raw dialable phone for clipboard copy.
     * Strips formatting but keeps the + prefix.
     */
    public static function normalizeForCopy(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        return preg_replace('/[^\d+]/', '', $raw) ?: $raw;
    }
}
