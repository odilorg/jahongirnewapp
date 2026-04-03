<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\BookingRoomRequest;
use Tests\TestCase;

class BookingRoomRequestTest extends TestCase
{
    // ──────────────────────────────────────────────
    // canonicalPropertyHint()
    // ──────────────────────────────────────────────

    /** @test */
    public function it_returns_null_for_null_hint(): void
    {
        $this->assertNull(BookingRoomRequest::canonicalPropertyHint(null));
    }

    /** @test */
    public function it_returns_null_for_empty_string(): void
    {
        $this->assertNull(BookingRoomRequest::canonicalPropertyHint(''));
        $this->assertNull(BookingRoomRequest::canonicalPropertyHint('   '));
    }

    /** @test */
    public function it_canonicalizes_premium_variants(): void
    {
        foreach (['premium', 'Premium', 'PREMIUM', 'jahongir_premium', 'Jahongir Premium'] as $raw) {
            $this->assertSame('premium', BookingRoomRequest::canonicalPropertyHint($raw), "Failed for: {$raw}");
        }
    }

    /** @test */
    public function it_canonicalizes_hotel_variants(): void
    {
        foreach (['hotel', 'Hotel', 'HOTEL', 'jahongir_hotel', 'Jahongir Hotel'] as $raw) {
            $this->assertSame('hotel', BookingRoomRequest::canonicalPropertyHint($raw), "Failed for: {$raw}");
        }
    }

    /** @test */
    public function it_returns_null_for_unknown_property(): void
    {
        $this->assertNull(BookingRoomRequest::canonicalPropertyHint('some_other_property'));
    }

    // ──────────────────────────────────────────────
    // fromParsed() — single room
    // ──────────────────────────────────────────────

    /** @test */
    public function it_parses_single_room(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'room' => ['unit_name' => '12'],
        ]);

        $this->assertCount(1, $requests);
        $this->assertSame('12', $requests[0]->unitName);
        $this->assertNull($requests[0]->propertyHint);
    }

    /** @test */
    public function it_parses_single_room_with_property_hint(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'room'     => ['unit_name' => '22'],
            'property' => 'jahongir_premium',
        ]);

        $this->assertCount(1, $requests);
        $this->assertSame('22', $requests[0]->unitName);
        $this->assertSame('premium', $requests[0]->propertyHint);
    }

    /** @test */
    public function it_trims_unit_names(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'room' => ['unit_name' => '  14  '],
        ]);

        $this->assertSame('14', $requests[0]->unitName);
    }

    // ──────────────────────────────────────────────
    // fromParsed() — multi-room
    // ──────────────────────────────────────────────

    /** @test */
    public function it_parses_multiple_rooms(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms' => [
                ['unit_name' => '12'],
                ['unit_name' => '14'],
            ],
        ]);

        $this->assertCount(2, $requests);
        $this->assertSame('12', $requests[0]->unitName);
        $this->assertSame('14', $requests[1]->unitName);
    }

    /** @test */
    public function it_uses_global_hint_when_per_room_hint_is_absent(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms'    => [['unit_name' => '12'], ['unit_name' => '14']],
            'property' => 'Premium',
        ]);

        $this->assertSame('premium', $requests[0]->propertyHint);
        $this->assertSame('premium', $requests[1]->propertyHint);
    }

    /** @test */
    public function per_room_hint_overrides_global_hint(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms' => [
                ['unit_name' => '12', 'property' => 'hotel'],
                ['unit_name' => '14'],
            ],
            'property' => 'premium',
        ]);

        $this->assertSame('hotel',   $requests[0]->propertyHint);
        $this->assertSame('premium', $requests[1]->propertyHint);
    }

    /** @test */
    public function it_skips_blank_unit_names(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms' => [
                ['unit_name' => '12'],
                ['unit_name' => ''],
                ['unit_name' => '   '],
                ['unit_name' => '14'],
            ],
        ]);

        $this->assertCount(2, $requests);
    }

    /** @test */
    public function it_deduplicates_identical_room_requests(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms' => [
                ['unit_name' => '12'],
                ['unit_name' => '12'],
                ['unit_name' => '14'],
            ],
        ]);

        $this->assertCount(2, $requests);
        $this->assertSame('12', $requests[0]->unitName);
        $this->assertSame('14', $requests[1]->unitName);
    }

    /** @test */
    public function it_falls_back_to_single_room_when_rooms_array_is_empty(): void
    {
        $requests = BookingRoomRequest::fromParsed([
            'rooms' => [],
            'room'  => ['unit_name' => '22'],
        ]);

        $this->assertCount(1, $requests);
        $this->assertSame('22', $requests[0]->unitName);
    }

    /** @test */
    public function it_returns_empty_array_when_no_room_info(): void
    {
        $requests = BookingRoomRequest::fromParsed([]);
        $this->assertEmpty($requests);
    }
}
