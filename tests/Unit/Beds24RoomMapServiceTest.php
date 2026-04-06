<?php

namespace Tests\Unit;

use App\Services\Beds24BookingService;
use App\Services\Beds24RoomMapService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for Beds24RoomMapService (cache-first room number resolution).
 *
 * Tests run with CACHE_DRIVER=array (see phpunit.xml) so no Redis is needed.
 *
 * Scenarios:
 *  (1) Cache hit                     → correct room number, zero API calls
 *  (2) Cache miss + live success     → correct room number, cache populated
 *  (3) Cache miss + live empty       → null, existing cache NOT overwritten
 *  (4) Cache hit after live fill     → second call uses cache, no second API call
 *  (5) Unknown room/unit pair        → null (not a crash)
 *  (6) Malformed cache payload       → falls back to live API, no crash
 *  (7) Property isolation            → property A map does not affect property B
 *  (8) Notification path regression  → resolve() returns room number under cached conditions
 */
class Beds24RoomMapServiceTest extends TestCase
{
    // Fixtures -----------------------------------------------------------------

    /** Minimal room status list returned by Beds24BookingService::getRoomStatuses() */
    private function fakeRooms(): array
    {
        return [
            ['room_type_id' => 377303, 'unit_id' => 1, 'room_number' => '17', 'status' => 'dirty', 'room_type' => 'Standard'],
            ['room_type_id' => 377303, 'unit_id' => 2, 'room_number' => '18', 'status' => 'clean', 'room_type' => 'Standard'],
            ['room_type_id' => 377304, 'unit_id' => 1, 'room_number' => '10', 'status' => 'clean', 'room_type' => 'Deluxe'],
        ];
    }

    /** Property-scoped cache key produced by the service */
    private function cacheKey(int $propertyId): string
    {
        return "beds24:property:{$propertyId}:room_map";
    }

    // -------------------------------------------------------------------------
    // (1) Cache hit — no API call
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_returns_room_number_from_cache_without_calling_api(): void
    {
        Cache::put($this->cacheKey(172793), [
            '377303_1' => '17',
            '377303_2' => '18',
        ], now()->addHours(24));

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->never())->method('getRoomStatuses');

        $service = new Beds24RoomMapService($beds24);

        $result = $service->resolve(172793, 377303, 1);

        $this->assertSame('17', $result);
    }

    // -------------------------------------------------------------------------
    // (2) Cache miss + live success — cache populated, correct room returned
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_calls_live_api_on_cache_miss_and_populates_cache(): void
    {
        Cache::flush(); // ensure cache is empty

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->with(172793)
            ->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);

        $result = $service->resolve(172793, 377303, 2);

        $this->assertSame('18', $result);

        // Verify cache was populated
        $cached = Cache::get($this->cacheKey(172793));
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('377303_1', $cached);
        $this->assertSame('17', $cached['377303_1']);
        $this->assertSame('18', $cached['377303_2']);
    }

    // -------------------------------------------------------------------------
    // (3) Cache miss + live empty — null returned, existing cache NOT overwritten
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_does_not_overwrite_cache_when_live_api_returns_empty(): void
    {
        // Pre-populate cache with good data
        $goodMap = ['377303_1' => '17', '377303_2' => '18'];
        Cache::put($this->cacheKey(172793), $goodMap, now()->addHours(24));

        // Simulate token expiry: getRoomStatuses() returns []
        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('getRoomStatuses')->willReturn([]);

        // Force a "cache miss" by using a different property for which the
        // cache is empty, so we exercise the live-API-returns-empty branch.
        $service = new Beds24RoomMapService($beds24);
        $result  = $service->resolve(99999, 377303, 1);

        $this->assertNull($result);

        // Original property 172793 cache must be intact
        $this->assertSame($goodMap, Cache::get($this->cacheKey(172793)));

        // Empty-returning property should NOT have an empty array cached
        $this->assertNull(Cache::get($this->cacheKey(99999)));
    }

    // -------------------------------------------------------------------------
    // (4) Cache hit after previous live fill — second call uses cache only
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_uses_cache_on_second_call_without_additional_api_call(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        // API must be called exactly once (the first call, which populates cache)
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);

        $first  = $service->resolve(172793, 377303, 1);
        $second = $service->resolve(172793, 377303, 2); // different room, same property

        $this->assertSame('17', $first);
        $this->assertSame('18', $second);
    }

    // -------------------------------------------------------------------------
    // (5) Unknown room/unit pair — null, no crash
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_returns_null_for_unknown_room_unit_pair_in_cache(): void
    {
        Cache::put($this->cacheKey(172793), ['377303_1' => '17'], now()->addHours(24));

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->never())->method('getRoomStatuses');

        $service = new Beds24RoomMapService($beds24);

        $result = $service->resolve(172793, 999999, 99);

        $this->assertNull($result);
    }

    /** @test */
    public function resolve_returns_null_when_live_api_does_not_contain_the_requested_pair(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('getRoomStatuses')->willReturn($this->fakeRooms()); // has 377303/1, /2 and 377304/1

        $service = new Beds24RoomMapService($beds24);

        // Ask for a pair that doesn't exist in the live response
        $result = $service->resolve(172793, 999999, 99);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // (6) Malformed cache payload — falls back to live API, no crash
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_falls_back_to_live_api_when_cache_contains_malformed_payload(): void
    {
        // Seed cache with a non-array value (e.g. a stale string from another key)
        Cache::put($this->cacheKey(172793), 'not-an-array', now()->addHours(1));

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);

        $result = $service->resolve(172793, 377303, 1);

        $this->assertSame('17', $result);
    }

    /** @test */
    public function resolve_falls_back_to_live_api_when_cache_contains_empty_array(): void
    {
        // An empty array is treated as a cache miss (count === 0)
        Cache::put($this->cacheKey(172793), [], now()->addHours(1));

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);

        $result = $service->resolve(172793, 377303, 1);

        $this->assertSame('17', $result);
    }

    // -------------------------------------------------------------------------
    // (7) Property isolation — maps are strictly per property
    // -------------------------------------------------------------------------

    /** @test */
    public function property_a_room_map_does_not_affect_property_b(): void
    {
        Cache::flush();

        // Warm property A (172793) — has room 17
        Cache::put($this->cacheKey(172793), ['377303_1' => '17'], now()->addHours(24));

        // Property B (41097) has a different room at the same roomId/unitId combo
        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->with(41097)
            ->willReturn([
                ['room_type_id' => 377303, 'unit_id' => 1, 'room_number' => '5', 'status' => 'clean', 'room_type' => 'Type B'],
            ]);

        $service = new Beds24RoomMapService($beds24);

        $roomA = $service->resolve(172793, 377303, 1); // must come from cache (property A)
        $roomB = $service->resolve(41097, 377303, 1);  // must come from live (property B)

        $this->assertSame('17', $roomA, 'Property A room must not be affected by property B lookup');
        $this->assertSame('5',  $roomB, 'Property B must resolve independently');
    }

    // -------------------------------------------------------------------------
    // (8) Notification regression — booking_ref forwarded, correct room returned
    // -------------------------------------------------------------------------

    /** @test */
    public function resolve_accepts_optional_booking_ref_and_returns_correct_room(): void
    {
        Cache::put($this->cacheKey(172793), ['377303_1' => '17'], now()->addHours(24));

        $beds24  = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->never())->method('getRoomStatuses');

        $service = new Beds24RoomMapService($beds24);

        // booking_ref is optional — passing it should not break the lookup
        $result = $service->resolve(172793, 377303, 1, '84109764');

        $this->assertSame('17', $result);
    }

    // -------------------------------------------------------------------------
    // warmCache — utility method
    // -------------------------------------------------------------------------

    /** @test */
    public function warm_cache_populates_redis_map_for_given_property(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('getRoomStatuses')->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);
        $service->warmCache(172793);

        $cached = Cache::get($this->cacheKey(172793));
        $this->assertIsArray($cached);
        $this->assertSame('17', $cached['377303_1']);
        $this->assertSame('18', $cached['377303_2']);
    }

    /** @test */
    public function warm_cache_does_nothing_when_live_api_returns_empty(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('getRoomStatuses')->willReturn([]);

        $service = new Beds24RoomMapService($beds24);
        $service->warmCache(172793);

        $this->assertNull(Cache::get($this->cacheKey(172793)));
    }

    // -------------------------------------------------------------------------
    // getMap — bulk room map retrieval used by daily plan builder
    // -------------------------------------------------------------------------

    /** @test */
    public function get_map_returns_full_map_from_cache_without_api_call(): void
    {
        $map = ['377303_1' => '17', '377303_2' => '18', '377304_1' => '10'];
        Cache::put($this->cacheKey(172793), $map, now()->addHours(24));

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->never())->method('getRoomStatuses');

        $service = new Beds24RoomMapService($beds24);
        $result  = $service->getMap(172793);

        $this->assertSame($map, $result);
    }

    /** @test */
    public function get_map_fetches_live_and_populates_cache_on_miss(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->expects($this->once())
            ->method('getRoomStatuses')
            ->willReturn($this->fakeRooms());

        $service = new Beds24RoomMapService($beds24);
        $result  = $service->getMap(172793);

        $this->assertArrayHasKey('377303_1', $result);
        $this->assertSame('17', $result['377303_1']);
        $this->assertSame('10', $result['377304_1']);

        // Cache should now be populated so a second call hits cache only
        $beds24NoCall = $this->createMock(Beds24BookingService::class);
        $beds24NoCall->expects($this->never())->method('getRoomStatuses');
        $service2 = new Beds24RoomMapService($beds24NoCall);
        $service2->getMap(172793); // must not call API
    }

    /** @test */
    public function get_map_returns_empty_array_when_live_api_returns_empty(): void
    {
        Cache::flush();

        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('getRoomStatuses')->willReturn([]);

        $service = new Beds24RoomMapService($beds24);
        $result  = $service->getMap(172793);

        $this->assertSame([], $result);
        // Must not have cached empty array
        $this->assertNull(Cache::get($this->cacheKey(172793)));
    }
}
