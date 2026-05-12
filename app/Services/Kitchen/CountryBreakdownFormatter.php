<?php

declare(strict_types=1);

namespace App\Services\Kitchen;

/**
 * Render a guest-country breakdown block for the kitchen Telegram bot.
 *
 * Input:  ['CH' => 4, 'DE' => 4, '' => 2, 'GB' => 3, ...] — counts are
 *         bookings/rooms, not pax. Empty / null / whitespace ISO keys are
 *         treated as "unknown" and grouped under the dedicated bottom line.
 *
 * Output: a multi-line Uzbek block prefixed by the 🌍 header, or '' when
 *         the input is empty. Top-8 named countries are listed individually;
 *         any remainder rolls up into "🌐 Boshqa: N". Unknown is ALWAYS the
 *         last line so kitchen staff see the named countries first.
 *
 * Pure (no I/O, no time, no logging) — every test case here is deterministic.
 */
class CountryBreakdownFormatter
{
    public const TOP_N = 8;

    public function format(array $byCountry): string
    {
        $normalized = $this->normalize($byCountry);

        if ($normalized === []) {
            return '';
        }

        $unknown = $normalized['__unknown__'] ?? 0;
        unset($normalized['__unknown__']);

        // Sort by count desc, then ISO code asc — stable ordering so the
        // bot output doesn't visibly flicker between runs with identical
        // inputs (e.g. two ties in count).
        $pairs = [];
        foreach ($normalized as $iso => $count) {
            $pairs[] = ['iso' => $iso, 'count' => $count];
        }
        usort($pairs, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['iso'], $b['iso']);
        });

        $top = array_slice($pairs, 0, self::TOP_N);
        $rest = array_slice($pairs, self::TOP_N);
        $restTotal = array_sum(array_column($rest, 'count'));

        $lines = ['🌍 Mehmonlar millati:'];
        foreach ($top as $p) {
            $lines[] = sprintf('  %s %s: %d', $this->flag($p['iso']), $p['iso'], $p['count']);
        }
        if ($restTotal > 0) {
            $lines[] = sprintf('  🌐 Boshqa: %d', $restTotal);
        }
        if ($unknown > 0) {
            $lines[] = sprintf("  ❓ Noma'lum: %d", $unknown);
        }

        return implode("\n", $lines);
    }

    /**
     * Collapse null / empty / whitespace-only / single-letter ISO keys into
     * the internal '__unknown__' bucket. Trims + uppercases real ISO codes
     * so 'ch' and ' CH ' both land on 'CH'.
     *
     * @param  array<string|int, int|string|null>  $byCountry
     * @return array<string, int>
     */
    private function normalize(array $byCountry): array
    {
        $out = [];
        foreach ($byCountry as $iso => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }

            $iso = is_string($iso) ? trim(strtoupper($iso)) : '';

            if ($iso === '' || strlen($iso) !== 2 || ! ctype_alpha($iso)) {
                $out['__unknown__'] = ($out['__unknown__'] ?? 0) + $count;

                continue;
            }

            $out[$iso] = ($out[$iso] ?? 0) + $count;
        }

        return $out;
    }

    /**
     * Build a regional-indicator flag emoji from a 2-letter ISO code.
     * Caller has already guaranteed the input is 2 ASCII letters via normalize().
     */
    private function flag(string $iso): string
    {
        return mb_chr(0x1F1E6 + ord($iso[0]) - ord('A'))
             .mb_chr(0x1F1E6 + ord($iso[1]) - ord('A'));
    }
}
