<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BookingBot\DeepSeekIntentParser;
use App\Services\BookingBot\IntentParseException;
use App\Services\BookingBot\LocalIntentParser;
use App\Services\BookingIntentParser;
use App\Support\BookingBot\DateRangeParser;
use App\Support\BookingBot\MessageNormalizer;
use Mockery;
use Tests\TestCase;

/**
 * Coordinator dispatch tests. Uses REAL LocalIntentParser and
 * MessageNormalizer (both pure helpers, trivial to construct) and
 * mocks DeepSeekIntentParser (the only class with external I/O).
 */
final class BookingIntentParserTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_local_hit_bypasses_deepseek(): void
    {
        $remote = Mockery::mock(DeepSeekIntentParser::class);
        $remote->shouldNotReceive('parse');

        $coord = new BookingIntentParser(
            new LocalIntentParser(new DateRangeParser()),
            $remote,
            new MessageNormalizer(),
        );

        $out = $coord->parse('BOOKINGS today');
        $this->assertSame('view_bookings', $out['intent']);
        $this->assertSame('today', $out['filter_type']);
    }

    public function test_local_miss_falls_through_to_deepseek_with_original_message(): void
    {
        $remote = Mockery::mock(DeepSeekIntentParser::class);
        // Coordinator must hand the ORIGINAL (case-preserved) string to
        // the LLM — the DeepSeek prompt examples are case-sensitive.
        $remote->shouldReceive('parse')
            ->once()
            ->with('book room 12 under John Walker jan 2-3 tel +1234567890')
            ->andReturn(['intent' => 'create_booking']);

        $coord = new BookingIntentParser(
            new LocalIntentParser(new DateRangeParser()),
            $remote,
            new MessageNormalizer(),
        );

        $out = $coord->parse('book room 12 under John Walker jan 2-3 tel +1234567890');
        $this->assertSame(['intent' => 'create_booking'], $out);
    }

    public function test_deepseek_throws_bubbles_intent_parse_exception(): void
    {
        $remote = Mockery::mock(DeepSeekIntentParser::class);
        $remote->shouldReceive('parse')
            ->once()
            ->andThrow(new IntentParseException('timed out'));

        $coord = new BookingIntentParser(
            new LocalIntentParser(new DateRangeParser()),
            $remote,
            new MessageNormalizer(),
        );

        $this->expectException(IntentParseException::class);
        // Deliberately nonsense that local won't match.
        $coord->parse('asdf asdf asdf');
    }

    public function test_validate_accepts_known_intents_rejects_garbage(): void
    {
        $coord = new BookingIntentParser(
            new LocalIntentParser(new DateRangeParser()),
            Mockery::mock(DeepSeekIntentParser::class),
            new MessageNormalizer(),
        );

        $this->assertTrue($coord->validate(['intent' => 'view_bookings']));
        $this->assertTrue($coord->validate(['intent' => 'cancel_booking']));
        $this->assertFalse($coord->validate(['intent' => 'gibberish']));
        $this->assertFalse($coord->validate([]));
    }
}
