<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GygEmailClassifier;
use App\Services\GygEmailParser;
use PHPUnit\Framework\TestCase;

/**
 * Anti-drift integration test for the GYG inbound-email pipeline.
 *
 *   Rule: any new GYG booking subject pattern accepted by GygEmailClassifier
 *   MUST add/update a fixture under tests/Fixtures/GygEmails/. This test
 *   then asserts the parser produces a complete extraction for that body —
 *   so classifier and parser cannot drift independently.
 *
 * The 2026-04-27 GYG48YVRXWBH incident was caused by exactly this drift:
 * the classifier was widened in one commit, but the parser regex still
 * anchored on the old wording, and a real booking landed in needs_review
 * with no observability. This test is the tripwire that prevents repeat.
 */
class GygPipelineDriftTest extends TestCase
{
    private GygEmailClassifier $classifier;

    private GygEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new GygEmailClassifier();
        $this->parser     = new GygEmailParser();
    }

    /**
     * @return iterable<string, array{0: array}>
     */
    public static function bookingFixtureProvider(): iterable
    {
        $dir = __DIR__ . '/../Fixtures/GygEmails';

        foreach (glob($dir . '/*.php') as $file) {
            $fixture = require $file;
            yield $fixture['name'] => [$fixture];
        }
    }

    /**
     * @dataProvider bookingFixtureProvider
     */
    public function test_classifier_accepted_booking_subjects_have_complete_parser_fixtures(array $fixture): void
    {
        $name     = $fixture['name'];
        $subject  = $fixture['subject'];
        $from     = $fixture['from'];
        $body     = $fixture['body'];
        $expected = $fixture['expected'];

        // 1. Classifier must accept this subject as a real booking type.
        $classification = $this->classifier->classify($subject, $from);
        $this->assertNotEquals(
            'unknown',
            $classification,
            "[{$name}] classifier returned 'unknown' — fixture subject must be an accepted booking pattern"
        );
        $this->assertEquals(
            $expected['classification'],
            $classification,
            "[{$name}] classifier returned wrong booking type"
        );

        // 2. Parser must produce a complete extraction for the matching body.
        //    Anything that's null/empty for a field the fixture promised
        //    means the parser drifted from the classifier — fail loud.
        $parsed = $this->parser->parseNewBooking($body, $subject);

        $requiredFields = [
            'gyg_booking_reference',
            'tour_name',
            'option_title',
            'travel_date',
            'pax',
        ];

        foreach ($requiredFields as $field) {
            $this->assertNotEmpty(
                $parsed[$field] ?? null,
                "[{$name}] parser returned empty {$field} — classifier accepted the subject but parser cannot extract this required field"
            );
        }

        // 3. Exact-value contract for the fields the fixture pins down.
        //    These catch silent regressions like the \w+ → first-word
        //    truncation of multi-word locales.
        $exactFields = [
            'gyg_booking_reference',
            'tour_name',
            'option_title',
            'guest_name',
            'guest_phone',
            'travel_date',
            'travel_time',
            'pax',
            'price',
            'currency',
            'language',
            'tour_type',
            'tour_type_source',
        ];

        foreach ($exactFields as $field) {
            if (! array_key_exists($field, $expected)) {
                continue;
            }
            $this->assertSame(
                $expected[$field],
                $parsed[$field] ?? null,
                "[{$name}] parser produced wrong {$field}"
            );
        }
    }
}
