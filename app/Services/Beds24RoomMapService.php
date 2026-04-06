<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache-first room number resolver for Beds24 properties.
 *
 * The checkout notification hot path reads from Redis first.  The live
 * Beds24 API is only called on a cache miss, so a stale/expired token
 * during a checkout window no longer causes the cleaning bot to display
 * "?" instead of the actual room number.
 *
 * Cache key scheme (per property):
 *   beds24:property:{propertyId}:room_map
 *
 * Payload shape (flat associative array):
 *   [ "{roomTypeId}_{unitId}" => "{roomNumber}", ... ]
 */
class Beds24RoomMapService
{
    public function __construct(private Beds24BookingService $beds24Service) {}

    /**
     * Resolve a human-readable room number from Beds24 roomTypeId + unitId.
     *
     * Lookup order:
     *   1. Redis cache (returns immediately on hit — no API call)
     *   2. Live Beds24 API on miss (populates cache on success)
     *   3. null if both fail (caller falls back to '?')
     *
     * @param  int         $propertyId  Beds24 property ID
     * @param  int         $roomId      Beds24 roomType ID  (booking.roomId in webhook payload)
     * @param  int         $unitId      Beds24 unit ID      (booking.unitId in webhook payload)
     * @param  string|null $bookingRef  Optional booking reference — included in log context only
     * @return string|null              Room number string, or null when unresolvable
     */
    public function resolve(int $propertyId, int $roomId, int $unitId, ?string $bookingRef = null): ?string
    {
        $cacheKey = $this->cacheKey($propertyId);
        $roomKey  = "{$roomId}_{$unitId}";

        // ── Step 1: cache-first hot path (no API call) ───────────────────────
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && count($cached) > 0) {
            return $cached[$roomKey] ?? null;
        }

        // ── Step 2: live fallback — cache miss only ───────────────────────────
        // getRoomStatuses() handles its own exceptions internally and returns []
        // on failure; we must therefore guard against an empty result explicitly
        // rather than relying on a thrown exception.
        $rooms = $this->beds24Service->getRoomStatuses($propertyId);

        if (empty($rooms)) {
            // API returned nothing — do NOT write an empty map; that would
            // overwrite any good data that might arrive in the next request.
            Log::warning('Beds24 room resolution: live API returned empty room list on cache miss', [
                'property_id' => $propertyId,
                'room_id'     => $roomId,
                'unit_id'     => $unitId,
                'booking_ref' => $bookingRef,
            ]);
            return null;
        }

        // Normalize: all IDs and room numbers stored as strings for consistent
        // array key comparison regardless of how Beds24 types them.
        $map = [];
        foreach ($rooms as $room) {
            $key       = ((string) $room['room_type_id']) . '_' . ((string) $room['unit_id']);
            $map[$key] = (string) ($room['room_number'] ?? '');
        }

        Cache::put($cacheKey, $map, now()->addHours(24));

        return $map[$roomKey] ?? null;
    }

    /**
     * Proactively warm (or refresh) the room map for a property.
     * Safe to call from scheduled tasks or after a successful token refresh.
     * Silently does nothing if the live call returns empty.
     */
    public function warmCache(int $propertyId): void
    {
        $rooms = $this->beds24Service->getRoomStatuses($propertyId);
        if (empty($rooms)) {
            return;
        }

        $map = [];
        foreach ($rooms as $room) {
            $key       = ((string) $room['room_type_id']) . '_' . ((string) $room['unit_id']);
            $map[$key] = (string) ($room['room_number'] ?? '');
        }

        Cache::put($this->cacheKey($propertyId), $map, now()->addHours(24));
    }

    private function cacheKey(int $propertyId): string
    {
        return "beds24:property:{$propertyId}:room_map";
    }
}
