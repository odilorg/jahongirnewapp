<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convert the WhatsApp-flavored plain-text reminder body into safe HTML
 * for email rendering.
 *
 * The guest reminder is authored once (TourSendReminders::buildGuestMessage)
 * in WhatsApp markup: `*bold*`, leading emoji, bare URLs, `\n` line breaks.
 * Dumping that into an HTML email shows literal asterisks and collapses
 * newlines. This helper is a pure, side-effect-free transform so the same
 * source message renders correctly in both channels.
 *
 * Order matters: escape FIRST (so guest-supplied text can't inject markup),
 * then linkify, then apply *bold*, then convert newlines.
 */
final class WhatsAppMarkupToHtml
{
    public static function convert(string $waText): string
    {
        // 1. Escape everything — guest names / notes are untrusted.
        $html = htmlspecialchars($waText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 2. Linkify bare URLs (http/https). Trailing punctuation excluded.
        $html = preg_replace_callback(
            '#(https?://[^\s<]+[^\s<.,;:!?)\]])#u',
            static fn (array $m): string => '<a href="'.$m[1].'">'.$m[1].'</a>',
            $html,
        ) ?? $html;

        // 3. WhatsApp *bold* → <strong>. Non-greedy, single-line spans only
        //    (a `*` never spans a newline in WA markup).
        $html = preg_replace(
            '/\*([^*\n]+)\*/u',
            '<strong>$1</strong>',
            $html,
        ) ?? $html;

        // 4. Newlines → <br>. Collapse 3+ blank lines to a paragraph gap.
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return nl2br($html, false);
    }
}
