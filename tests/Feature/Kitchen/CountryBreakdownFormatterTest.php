<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Services\Kitchen\CountryBreakdownFormatter;
use Tests\TestCase;

/**
 * Tests for the kitchen-bot country-breakdown formatter.
 *
 * Inputs are bookings-per-country counts (one entry per booking, not pax).
 * The formatter must:
 *   1. render countries sorted by count desc then ISO asc
 *   2. cap visible countries at TOP_N (8) and roll the rest into 'Boshqa'
 *   3. always render unknown (null/empty/invalid ISO) at the bottom row
 *   4. return '' on empty input so the caller can omit the whole section
 */
class CountryBreakdownFormatterTest extends TestCase
{
    public function test_renders_normal_breakdown_sorted_by_count_then_iso(): void
    {
        $formatter = new CountryBreakdownFormatter;

        // 4xCH, 4xDE, 3xGB, 2xAT, 2xCA, 2xRU, 1xFR, 0 unknown.
        // Within same count, ties resolve by ISO asc (AT, CA, RU).
        $out = $formatter->format([
            'GB' => 3, 'DE' => 4, 'AT' => 2, 'CA' => 2, 'CH' => 4, 'RU' => 2, 'FR' => 1,
        ]);

        $expected = implode("\n", [
            '🌍 Mehmonlar millati:',
            '  🇨🇭 CH: 4',
            '  🇩🇪 DE: 4',
            '  🇬🇧 GB: 3',
            '  🇦🇹 AT: 2',
            '  🇨🇦 CA: 2',
            '  🇷🇺 RU: 2',
            '  🇫🇷 FR: 1',
        ]);

        $this->assertSame($expected, $out);
    }

    public function test_collapses_null_empty_and_invalid_iso_into_unknown(): void
    {
        $formatter = new CountryBreakdownFormatter;

        // Mix of unknown-flavoured keys: empty string, whitespace, 1-letter,
        // 3-letter, and a numeric '00'. All should land on the Noma'lum line.
        $out = $formatter->format([
            'CH' => 3,
            '' => 1,
            '  ' => 1,
            'X' => 1,
            'USA' => 1,
            '00' => 1,
        ]);

        $this->assertStringContainsString('🇨🇭 CH: 3', $out);
        $this->assertStringContainsString("❓ Noma'lum: 5", $out);

        // Unknown line MUST be the last line — even when only one country is named.
        $lines = explode("\n", $out);
        $this->assertSame("  ❓ Noma'lum: 5", end($lines));
    }

    public function test_caps_at_top_8_and_rolls_remainder_into_boshqa(): void
    {
        $formatter = new CountryBreakdownFormatter;

        // 10 named countries with descending counts so the order is unambiguous.
        $out = $formatter->format([
            'AA' => 10, 'AB' => 9, 'AC' => 8, 'AD' => 7, 'AE' => 6,
            'AF' => 5, 'AG' => 4, 'AH' => 3,
            'AI' => 2, 'AJ' => 1,
        ]);

        // Top 8 (AA..AH) shown, AI+AJ rolled into Boshqa = 3.
        $this->assertStringContainsString('AA: 10', $out);
        $this->assertStringContainsString('AH: 3', $out);
        $this->assertStringNotContainsString('AI:', $out);
        $this->assertStringNotContainsString('AJ:', $out);
        $this->assertStringContainsString('🌐 Boshqa: 3', $out);

        // Boshqa appears BEFORE unknown (which is absent here) and after the 8 named lines.
        $lines = explode("\n", $out);
        $this->assertCount(10, $lines); // header + 8 countries + Boshqa
        $this->assertSame('  🌐 Boshqa: 3', end($lines));
    }

    public function test_unknown_renders_after_boshqa_when_both_present(): void
    {
        $formatter = new CountryBreakdownFormatter;

        $out = $formatter->format([
            'AA' => 10, 'AB' => 9, 'AC' => 8, 'AD' => 7, 'AE' => 6,
            'AF' => 5, 'AG' => 4, 'AH' => 3, 'AI' => 2,
            '' => 4,
        ]);

        $lines = explode("\n", $out);
        // 9 named + Boshqa + Noma'lum order
        $this->assertSame("  ❓ Noma'lum: 4", end($lines));
        $this->assertSame('  🌐 Boshqa: 2', $lines[count($lines) - 2]);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $formatter = new CountryBreakdownFormatter;

        $this->assertSame('', $formatter->format([]));
        // Zero counts are dropped — degenerate inputs render as if empty.
        $this->assertSame('', $formatter->format(['CH' => 0, 'DE' => 0]));
    }

    public function test_iso_codes_are_normalized_case_and_whitespace(): void
    {
        $formatter = new CountryBreakdownFormatter;

        $out = $formatter->format([
            'ch' => 2,
            ' CH ' => 1, // same country, merged into CH
            'De' => 3,
        ]);

        // Merged: CH=3, DE=3 — sorted by count desc then ISO asc → CH first.
        $this->assertStringContainsString('🇨🇭 CH: 3', $out);
        $this->assertStringContainsString('🇩🇪 DE: 3', $out);
    }

    // ── Controller-level guard: live counter / weekly views must NOT render the country block ──

    /**
     * Architectural assertion: the new CountryBreakdownFormatter is referenced
     * only by the two views that should display it (showTodayFull and
     * showTomorrow). Live counter / welcome / remaining / weekly views must
     * not import or render the formatter — that's the scope contract from
     * the operator spec.
     *
     * This is a cheap grep-based guard rather than a full UI test; it catches
     * the most likely regression vector (someone slips the formatter call into
     * the wrong view) without standing up Telegram fixtures.
     */
    public function test_formatter_only_referenced_in_today_and_tomorrow_handlers(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/KitchenBotController.php'));
        $this->assertIsString($controller);

        // Grab each method body so we can assert per-method.
        $methodBody = function (string $name) use ($controller): string {
            $pattern = '/protected function '.preg_quote($name, '/').'\(.*?\n    \}/s';
            preg_match($pattern, $controller, $m);

            return $m[0] ?? '';
        };

        $allowed = ['showTodayFull', 'showTomorrow'];
        $forbidden = ['showWelcome', 'showRemaining', 'showWeekly', 'incrementServed', 'decrementServed'];

        foreach ($allowed as $m) {
            $body = $methodBody($m);
            $this->assertNotEmpty($body, "method {$m} not found");
            $this->assertStringContainsString('CountryBreakdownFormatter', $body, "{$m} should render the country block");
        }

        foreach ($forbidden as $m) {
            $body = $methodBody($m);
            if ($body === '') {
                continue; // method may not exist; ok
            }
            $this->assertStringNotContainsString('CountryBreakdownFormatter', $body, "{$m} must NOT render the country block (operator scope)");
        }
    }
}
