<?php

declare(strict_types=1);

namespace Tests\Unit\Support\BookingBot;

use App\Support\BookingBot\MessageNormalizer;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    private MessageNormalizer $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new MessageNormalizer();
    }

    public function test_empty_and_whitespace_returns_empty(): void
    {
        $this->assertSame('', $this->n->normalize(''));
        $this->assertSame('', $this->n->normalize("   \t\n  "));
    }

    public function test_lowercases_and_trims(): void
    {
        $this->assertSame('bookings today', $this->n->normalize('  BOOKINGS TODAY  '));
    }

    public function test_collapses_multiple_spaces(): void
    {
        $this->assertSame('bookings may 5-10', $this->n->normalize("bookings   may    5-10"));
    }

    public function test_normalizes_unicode_dashes_to_ascii_hyphen(): void
    {
        // em dash
        $this->assertSame('bookings may 5-10', $this->n->normalize("bookings may 5\u{2014}10"));
        // en dash
        $this->assertSame('bookings may 5-10', $this->n->normalize("bookings may 5\u{2013}10"));
        // minus sign
        $this->assertSame('bookings may 5-10', $this->n->normalize("bookings may 5\u{2212}10"));
    }

    public function test_combined_mixed_input(): void
    {
        $raw = "  BOOKINGS   MAY 5 \u{2014} 10  ";
        $this->assertSame('bookings may 5 - 10', $this->n->normalize($raw));
    }
}
