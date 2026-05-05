<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\GygProcessEmails;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for the body-missing safety guard added on 2026-05-05.
 *
 * The guard exists to prevent the silent-drop pattern documented in the
 * 2026-05-05 audit: when Gmail body read times out, the row is stored with
 * body_text=NULL. The classifier works on subject only, so a real
 * booking/cancellation/amendment subject MUST be marked 'failed' rather
 * than auto-applied (no body to parse) or silently skipped (operator never
 * sees the loss).
 */
class GygProcessEmailsBodyGuardTest extends TestCase
{
    /**
     * @dataProvider actionableEmptyBodyProvider
     */
    public function test_actionable_type_with_empty_body_is_flagged(string $type, ?string $body, bool $expected): void
    {
        $this->assertSame(
            $expected,
            GygProcessEmails::isActionableTypeWithEmptyBody($type, $body),
            "type={$type}, body=".var_export($body, true),
        );
    }

    public static function actionableEmptyBodyProvider(): array
    {
        return [
            // Actionable types with empty body — MUST be flagged
            'new_booking + null body' => ['new_booking', null, true],
            'new_booking + empty string' => ['new_booking', '', true],
            'new_booking + whitespace' => ['new_booking', "   \n  ", true],
            'cancellation + null body' => ['cancellation', null, true],
            'cancellation + empty string' => ['cancellation', '', true],
            'amendment + null body' => ['amendment', null, true],
            'amendment + empty string' => ['amendment', '', true],

            // Actionable types with real body — must NOT trigger the guard
            'new_booking + real body' => ['new_booking', 'Hi Supply Partner...', false],
            'cancellation + real body' => ['cancellation', 'Reference Number: GYGX', false],
            'amendment + real body' => ['amendment', 'has changed.', false],

            // Non-actionable types — never trigger the guard, even if body empty
            'unknown + null body' => ['unknown', null, false],
            'unknown + real body' => ['unknown', 'random', false],
            'guest_reply + null body' => ['guest_reply', null, false],
            'guest_reply + real body' => ['guest_reply', 'thanks!', false],
        ];
    }

    public function test_actionable_types_constant_matches_expected_set(): void
    {
        // Pin the contract: only these three types are protected by the guard.
        // Adding a new actionable type requires a deliberate change here.
        $this->assertSame(
            ['new_booking', 'cancellation', 'amendment'],
            GygProcessEmails::ACTIONABLE_TYPES,
        );
    }
}
