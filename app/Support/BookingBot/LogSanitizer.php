<?php

declare(strict_types=1);

namespace App\Support\BookingBot;

use Throwable;

/**
 * Redact guest PII from booking-bot log context payloads.
 *
 * Phase 10.7 — hardens pre-existing INFO-level logs that previously
 * carried full guest phone / email / name / free-text. Defaults:
 *   - phone  → "+998****567"      (country prefix + mask + last 3)
 *   - email  → "j***@domain.com"  (first char + mask + domain)
 *   - name   → first token only   ("Karim Wahab" → "Karim")
 *   - long free-text → truncated to 60 chars with "…"
 *
 * Kept intentionally:
 *   booking ids, property ids, room ids, amounts, dates, counts,
 *   errors, status codes — operationally required.
 *
 * Escape hatch: when `config('logging.booking_bot.debug_payloads', false)`
 * is true, context() returns the input verbatim. Flip env
 * LOG_BOOKING_BOT_DEBUG_PAYLOADS=true for short-lived debug sessions.
 *
 * Design rules:
 *   - Pure static helper; no state, no I/O.
 *   - Allow-listed keys only. Unknown keys pass through — over-redacting
 *     is as harmful as under-redacting (see architect plan §10 / §12).
 *   - Must never throw. Any internal failure returns a minimal
 *     breadcrumb so the log call itself never dies.
 */
final class LogSanitizer
{
    /** Keys whose leaf string value should be phone-redacted (case-insensitive). */
    private const PHONE_KEYS = ['phone', 'mobile', 'guest_phone', 'guestphone', 'telephone'];

    /** Keys whose leaf string value should be email-redacted. */
    private const EMAIL_KEYS = ['email', 'guest_email', 'guestemail'];

    /**
     * Keys whose leaf string value is a FULL name that should be reduced
     * to its first token ("Karim Wahab" → "Karim"). Single-token values
     * pass through — those are assumed already short (first name only).
     */
    private const FULL_NAME_KEYS = ['guestname', 'guest_name', 'name'];

    /**
     * Keys whose leaf string value is already the SURNAME field. Always
     * masked opaquely — surname alone is the most identifying half of
     * a name and should never appear in INFO logs.
     */
    private const SURNAME_KEYS = ['lastname', 'guestlastname', 'surname'];

    /**
     * Keys whose leaf string value is already the FIRST NAME field.
     * Kept as-is per architect's first-name-only policy (operationally
     * useful for incident response, low sensitivity on its own).
     */
    private const FIRSTNAME_KEYS = ['firstname', 'guestfirstname'];

    /** Keys whose leaf string value should be truncated to FREE_TEXT_MAX chars. */
    private const FREE_TEXT_KEYS = ['text', 'message', 'comments', 'notes', 'content', 'grouppnote', 'groupnote'];

    /** Keys whose value is a JSON string that should be decoded, sanitized, re-encoded. */
    private const JSON_STRING_KEYS = ['content']; // DeepSeek returns JSON-in-content

    private const FREE_TEXT_MAX = 60;

    /**
     * Deep-walk a log context array and redact known PII keys. Returns
     * the original array unchanged when the debug flag is on. Never
     * throws — returns a `_sanitize_error` breadcrumb on internal
     * failure so callers never need a try/catch.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context): array
    {
        if ((bool) config('logging.booking_bot.debug_payloads', false)) {
            return $context;
        }

        try {
            return self::walk($context);
        } catch (Throwable $e) {
            // Breadcrumb carries the exception CLASS only, never the
            // message — `getMessage()` can echo the offending input
            // (malformed UTF-8, invalid regex, etc.), which defeats the
            // point of the sanitizer. Class name is enough to debug.
            return ['_sanitize_error' => get_class($e)];
        }
    }

    public static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $trimmed = trim($phone);
        if ($trimmed === '') {
            return $trimmed;
        }
        $digits = preg_replace('/\D+/u', '', $trimmed) ?? '';
        if ($digits === '') {
            // No digits present — opaque garbage; mask completely.
            return '***';
        }

        $len = strlen($digits);
        $leadsWithPlus = str_starts_with($trimmed, '+');
        $countryPrefix = '';

        if ($leadsWithPlus) {
            // Keep up to 3 leading digits as country code; lean to 3 for
            // Uzbekistan (+998). Fallback to whatever's available.
            $countryPrefix = '+' . substr($digits, 0, min(3, $len));
            $digits = substr($digits, min(3, $len));
            $len = strlen($digits);
        }

        if ($len < 4) {
            // Too short to meaningfully mask — keep only stars.
            return ($countryPrefix !== '' ? $countryPrefix : '') . '****';
        }

        $last = substr($digits, -3);
        return ($countryPrefix !== '' ? $countryPrefix : '') . '****' . $last;
    }

    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);
        if ($trimmed === '') {
            return $trimmed;
        }
        $at = strpos($trimmed, '@');
        if ($at === false || $at === 0) {
            // Malformed — no local part or no @; keep opaque.
            return '***';
        }
        $local  = substr($trimmed, 0, $at);
        $domain = substr($trimmed, $at); // includes "@"
        $first  = mb_substr($local, 0, 1);
        return $first . '***' . $domain;
    }

    public static function name(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            return $trimmed;
        }
        // First whitespace-separated token — operationally useful, drops
        // the more-identifying surname half. See architect plan §5.
        $parts = preg_split('/\s+/u', $trimmed, 2);
        return $parts[0] ?? $trimmed;
    }

    /**
     * Truncate a free-text value to at most $max characters, with PII
     * scrubbed BEFORE truncation so that a phone or email sitting in
     * the first N chars cannot leak into logs.
     *
     * Phase 10.7.1: scrub-before-truncate is now the default for every
     * free-text path. Previously `truncate()` was a raw substring cut
     * and only `commandPreview()` scrubbed — which meant nested keys
     * (e.g. webhook `data.message.text`) leaked raw phone digits when
     * the phone fell inside the 60-char head. One helper, one safe
     * behavior, no unsafe-by-default path left in the codebase.
     *
     *   "book room 12 tel +998901234567 email alice@x.com"
     *   → "book room 12 tel +*** email ***@***"
     */
    public static function truncate(?string $text, int $max = self::FREE_TEXT_MAX): ?string
    {
        if ($text === null) {
            return null;
        }
        $scrubbed = self::scrubEmbeddedPii($text);
        if (mb_strlen($scrubbed) <= $max) {
            return $scrubbed;
        }
        return mb_substr($scrubbed, 0, $max - 1) . '…';
    }

    /**
     * Bounded 40-char preview of a user-supplied command string.
     * Thin alias over truncate() kept for readability at error-path
     * call sites ("what did the operator type?").
     */
    public static function commandPreview(?string $text, int $max = 40): ?string
    {
        return self::truncate($text, $max);
    }

    private static function scrubEmbeddedPii(string $text): string
    {
        // Phone-like: "+" followed by 8+ digits, allowing spaces/dashes.
        $text = preg_replace(
            '/\+(?:[\d][\s\-]?){8,}/u',
            '+***',
            $text,
        ) ?? $text;
        // Email-like: local@domain.tld
        $text = preg_replace(
            '/\S+@\S+\.\S+/u',
            '***@***',
            $text,
        ) ?? $text;
        return $text;
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private static function walk(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $lower = is_string($key) ? strtolower((string) $key) : null;
            $out[$key] = self::sanitizeValue($lower, $value);
        }
        return $out;
    }

    private static function sanitizeValue(?string $lowerKey, mixed $value): mixed
    {
        if (is_array($value)) {
            return self::walk($value);
        }

        // Non-string scalars (ints, bools, floats, null) are safe.
        if (! is_string($value)) {
            return $value;
        }

        if ($lowerKey === null) {
            return $value;
        }

        if (in_array($lowerKey, self::PHONE_KEYS, true)) {
            return self::phone($value);
        }
        if (in_array($lowerKey, self::EMAIL_KEYS, true)) {
            return self::email($value);
        }
        if (in_array($lowerKey, self::SURNAME_KEYS, true)) {
            return self::maskSurname($value);
        }
        if (in_array($lowerKey, self::FIRSTNAME_KEYS, true)) {
            // First-name fields are already short + safe per policy.
            return $value;
        }
        if (in_array($lowerKey, self::FULL_NAME_KEYS, true)) {
            return self::name($value);
        }
        if (in_array($lowerKey, self::JSON_STRING_KEYS, true)) {
            return self::sanitizeJsonString($value);
        }
        if (in_array($lowerKey, self::FREE_TEXT_KEYS, true)) {
            return self::truncate($value);
        }

        return $value;
    }

    /**
     * Surname fields are always opaque in logs — a surname alone is the
     * most identifying half of a name and carries no incident-response
     * value that `firstName` doesn't already provide. Returns the mask
     * `'***'` for any non-empty string; preserves null/empty.
     */
    private static function maskSurname(string $value): string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? $trimmed : '***';
    }

    /**
     * Handle JSON-encoded strings (e.g. DeepSeek response body). Decode,
     * sanitize as a nested context, re-encode. If it doesn't decode or
     * isn't an array, fall back to truncation.
     */
    private static function sanitizeJsonString(string $value): string
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $clean = self::walk($decoded);
            $reencoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($reencoded === false) {
                // Fail CLOSED. Falling back to the original string would
                // leak PII that was already redacted in $clean — we must
                // not ship the raw input just because re-encoding failed.
                return '***';
            }
            return $reencoded;
        }
        // Not JSON — truncate as plain free-text.
        return self::truncate($value) ?? '***';
    }
}
