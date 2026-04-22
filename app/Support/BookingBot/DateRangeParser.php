<?php

declare(strict_types=1);

namespace App\Support\BookingBot;

use Carbon\CarbonImmutable;

/**
 * Parse human / ISO date expressions into a [check_in, check_out] pair
 * for the LocalIntentParser. Pure helper — no state, no I/O.
 *
 * Supports:
 *   - ISO: "2026-05-05", "2026-05-05 to 2026-05-10"
 *   - Named single: "5 may", "may 5", "may 5 2026"
 *   - Named range:  "may 5-10", "5-10 may", "5 may - 10 may",
 *                   "may 5 to may 10", "5 may - 10"
 *   - Month-without-year → next occurrence (past years never chosen),
 *     matching the LLM prompt rule.
 *
 * Strict-match: returns null on anything ambiguous so the LocalIntentParser
 * can fall through to the LLM. NEVER guesses.
 */
final class DateRangeParser
{
    /**
     * @return array{check_in: string, check_out: string}|null
     */
    public function parse(string $rest): ?array
    {
        $rest = trim($rest);
        if ($rest === '') {
            return null;
        }

        // ISO single-or-range fast path.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*(?:-|to)\s*(\d{4}-\d{2}-\d{2}))?$/', $rest, $m) === 1) {
            $from = $this->carbon($m[1]);
            $to   = isset($m[2]) && $m[2] !== '' ? $this->carbon($m[2]) : $from;
            return $from !== null && $to !== null
                ? ['check_in' => $from, 'check_out' => $to]
                : null;
        }

        // Split on "-" or "to" (at most once).
        $parts = preg_split('/\s*(?:-|to)\s*/', $rest, 2);
        if ($parts === false || $parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            $single = $this->parseHumanDate($parts[0]);
            return $single !== null
                ? ['check_in' => $single, 'check_out' => $single]
                : null;
        }

        $left  = trim($parts[0]);
        $right = trim($parts[1]);

        // "5-10 may" — left is just a day, right carries the month.
        if (preg_match('/^\d{1,2}$/', $left) === 1) {
            $rightDate = $this->parseHumanDate($right);
            if ($rightDate === null) {
                return null;
            }
            $rightC = CarbonImmutable::parse($rightDate);
            $leftC  = $rightC->day((int) $left);
            if ($leftC->greaterThan($rightC)) {
                return null;
            }
            return ['check_in' => $leftC->format('Y-m-d'), 'check_out' => $rightDate];
        }

        // "5 may - 10" — right is just a day.
        if (preg_match('/^\d{1,2}$/', $right) === 1) {
            $leftDate = $this->parseHumanDate($left);
            if ($leftDate === null) {
                return null;
            }
            $leftC  = CarbonImmutable::parse($leftDate);
            $rightC = $leftC->day((int) $right);
            if ($rightC->lessThan($leftC)) {
                $rightC = $rightC->addMonth();
            }
            return ['check_in' => $leftDate, 'check_out' => $rightC->format('Y-m-d')];
        }

        // Fully-formed on both sides.
        $from = $this->parseHumanDate($left);
        $to   = $this->parseHumanDate($right);
        return ($from !== null && $to !== null)
            ? ['check_in' => $from, 'check_out' => $to]
            : null;
    }

    /**
     * Parse a single human-date expression. Requires an explicit month
     * name when no ISO format is present — bare digits without a month
     * are ambiguous and return null per strict-match rule.
     */
    public function parseHumanDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $this->carbon($raw);
        }

        $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec|january|february|march|april|june|july|august|september|october|november|december';
        if (preg_match('/(' . $months . ')/i', $raw) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        // Month without an explicit 4-digit year that fell into the past
        // → bump to next year. Matches LLM prompt rule H.
        if (! preg_match('/\b\d{4}\b/', $raw)
            && $parsed->lessThan(CarbonImmutable::today())) {
            $parsed = $parsed->addYear();
        }

        return $parsed->format('Y-m-d');
    }

    private function carbon(string $ymd): ?string
    {
        try {
            return CarbonImmutable::parse($ymd)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
