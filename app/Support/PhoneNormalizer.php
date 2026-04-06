<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalize phone numbers to digits-only for comparison and deduplication.
 *
 * Strips everything except digits. Does NOT reformat or add country codes —
 * the stored value keeps whatever the user typed; this is used only to
 * compare and detect duplicates consistently.
 */
final class PhoneNormalizer
{
    /**
     * Strip non-digit characters and return the digits-only string.
     * Returns an empty string if the input is null or whitespace-only.
     */
    public static function normalize(?string $phone): string
    {
        if ($phone === null || trim($phone) === '') {
            return '';
        }

        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /**
     * Return true if two phone strings are equivalent after normalization.
     */
    public static function samePhone(?string $a, ?string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        return $na !== '' && $na === $nb;
    }
}
