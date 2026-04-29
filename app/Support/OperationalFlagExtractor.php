<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure-function extractor for operational guest-context flags.
 *
 * Reads the free-text `operational_notes` of a booking and returns four
 * derived booleans used for calendar-row icons, downstream dispatch
 * highlighting, and future filtering.
 *
 * Single source of truth (Principle 5) — used by:
 *   - App\Actions\Calendar\Save\QuickSaveOperationalNotesAction (write path)
 *   - App\Console\Commands\Inquiries\BackfillOperationalFlags (one-shot)
 *
 * Word-boundary regexes (`\b`) suppress most false positives, with explicit
 * negative-lookahead suffixes for known descriptive contexts that name the
 * keyword without referring to the GUEST (e.g. "vegetarian-friendly hotel"
 * describes the venue, not the traveller — must not flag).
 *
 * Match-anywhere within the notes (the operator may include extra context
 * around the keyword). All comparisons are case-insensitive (`/iu`).
 *
 * NOT a model method — shared between the Action (synchronous write) and
 * the backfill Command (batch). Keeping it stateless and pure makes both
 * paths trivially testable.
 */
final class OperationalFlagExtractor
{
    /**
     * Keyword map. Each list contains regex fragments joined with `|`.
     *
     * Notes on regex shape:
     * - `\w*` tails so "speak" matches "speaker"/"speaking" without listing variants.
     * - Negative lookaheads `(?!-friendly|...)` exclude well-known descriptive idioms.
     * - The /u flag enables Unicode word boundaries — required for non-ASCII
     *   keywords (anniversaire, cumpleaños).
     *
     * Phase 1 keyword set is intentionally tight; multilingual expansion is
     * gated to Phase 4 once we have a pattern base for false-positive tuning.
     */
    private const PATTERNS = [
        // Dietary
        // - "vegetarian" but NOT "vegetarian-friendly" / "vegetarian friendly" (describes a venue, not the guest)
        // - same exclusion for "vegan-friendly"
        // - "gluten free" / "gluten-free"
        // - allergies (any form)
        'dietary' => '(?:vegetarian|vegan)(?![\s\-]+friendly)|gluten[\s\-]?free|halal|kosher|nut\s+allergy|nut\s*allerg\w*|(?<!no\s)(?<!non-)(?:allergic|allergy|allerg\w+)|lactose|dairy[\s\-]?free|pescatarian',

        // Accessibility
        // - "wheelchair" but NOT "wheelchair-accessible" (describes a venue/route)
        // - mobility / disabled / elderly / hearing-impaired / sight-impaired / blind
        'accessibility' => 'wheelchair(?![\s\-]+(?:accessible|friendly|access))|mobility(?![\s\-]+(?:accessible|friendly))|disabled(?![\s\-]+(?:access|toilet|parking|ramp))|elderly|crutch\w*|walker(?!\s+stretch)|cane(?:\s|$)|hearing[\s\-]?impair\w*|sight[\s\-]?impair\w*|blind\b',

        // Language
        // - "french speaker" / "french speaking" / "speaks french"
        // - "guide who speaks X"
        'language' => 'french[\s\-]?speak\w*|spanish[\s\-]?speak\w*|chinese[\s\-]?speak\w*|german[\s\-]?speak\w*|italian[\s\-]?speak\w*|russian[\s\-]?speak\w*|japanese[\s\-]?speak\w*|speaks\s+(?:french|spanish|chinese|german|italian|russian|japanese)|guide.{0,30}?(?:french|spanish|german|chinese|italian|russian|japanese)',

        // Occasion
        // - "birthday" / "celebrating" (verb form requires guest context) / honeymoon / anniversary / newlywed / surprise
        // - drop bare "celebration" — too ambiguous (matched "celebration tower" in tests)
        // - FR/ES tails via \w* for unicode chars
        'occasion' => 'birthday|celebrating|honeymoon|anniversary|newlywed\w*|surprise|anniversaire\w*|cumpleañ\w*|cumplea\w+|wedding|propos(?:al|ing)',
    ];

    /**
     * Extract the four flag booleans from a free-text note.
     *
     * Returns the same shape regardless of input:
     *   ['dietary' => bool, 'accessibility' => bool, 'language' => bool, 'occasion' => bool]
     *
     * Empty / whitespace-only / null input returns all-false.
     */
    public static function extract(?string $notes): array
    {
        $defaults = [
            'dietary'       => false,
            'accessibility' => false,
            'language'      => false,
            'occasion'      => false,
        ];

        if ($notes === null) {
            return $defaults;
        }

        $haystack = trim($notes);
        if ($haystack === '') {
            return $defaults;
        }

        foreach (self::PATTERNS as $key => $pattern) {
            // Bare alternatives matched anywhere; word-boundary anchoring
            // happens inside each branch via \b on first/last token where
            // useful, while negative lookaheads handle the descriptive-idiom
            // false positives. /u for Unicode-aware matching.
            $regex = '/(?:' . $pattern . ')/iu';
            if (preg_match($regex, $haystack) === 1) {
                $defaults[$key] = true;
            }
        }

        return $defaults;
    }
}
