<?php

declare(strict_types=1);

namespace App\Support\BookingBot;

/**
 * Normalize raw operator input before regex / LLM intent parsing.
 *
 *   - trim
 *   - lowercase (mb-safe)
 *   - unicode dashes (em, en, figure, minus, hyphen variants) → "-"
 *   - collapse runs of whitespace into single spaces
 *
 * Pure helper; no state. Lives in App\Support so both the coordinator
 * and tests can use it without dragging Services into unrelated places.
 */
final class MessageNormalizer
{
    public function normalize(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $s = mb_strtolower($s);
        // Unicode dashes → ASCII hyphen
        $s = preg_replace('/[\x{2012}\x{2013}\x{2014}\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}]/u', '-', $s) ?? $s;
        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
