<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class BookingRoomRequest
{
    public function __construct(
        public string  $unitName,
        public ?string $propertyHint, // canonical: 'hotel' | 'premium' | null
    ) {}

    /**
     * Normalize a free-form property string into a canonical value.
     * Returns 'hotel', 'premium', or null — never a raw parser string.
     */
    public static function canonicalPropertyHint(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $lower = strtolower(trim($raw));

        if (str_contains($lower, 'premium')) {
            return 'premium';
        }

        if (str_contains($lower, 'hotel')) {
            return 'hotel';
        }

        return null;
    }

    /**
     * Build a deduplicated, trimmed list of BookingRoomRequest from a raw parsed intent array.
     *
     * Handles both:
     *   - parsed['room']   (single room)
     *   - parsed['rooms']  (multi-room)
     *
     * Per-room property hints take precedence over the global hint.
     */
    public static function fromParsed(array $parsed): array
    {
        $globalHint = self::canonicalPropertyHint($parsed['property'] ?? null);
        $requests   = [];

        // Multi-room path
        if (!empty($parsed['rooms']) && is_array($parsed['rooms'])) {
            foreach ($parsed['rooms'] as $r) {
                $unitName = trim($r['unit_name'] ?? '');
                if ($unitName === '') {
                    continue;
                }

                $hint = self::canonicalPropertyHint($r['property'] ?? null) ?? $globalHint;
                $requests[] = new self($unitName, $hint);
            }
        }

        // Single-room fallback when rooms[] is absent or empty
        if (empty($requests) && !empty($parsed['room']['unit_name'])) {
            $unitName = trim($parsed['room']['unit_name']);
            if ($unitName !== '') {
                $requests[] = new self($unitName, $globalHint);
            }
        }

        // Deduplicate by (unitName, propertyHint) key — reject silently because
        // CreateBookingRequest::validationError() will surface a clear message to the operator.
        $seen   = [];
        $unique = [];

        foreach ($requests as $req) {
            $key = $req->unitName . '|' . ($req->propertyHint ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $req;
            }
        }

        return $unique;
    }
}
