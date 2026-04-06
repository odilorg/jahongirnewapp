<?php

namespace App\Services\Cashier;

use App\DTO\GroupAmountResolution;
use App\Exceptions\IncompleteGroupSyncException;
use App\Models\Beds24Booking;
use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the correct USD amount for a cashier payment, accounting for
 * Beds24 group bookings (one guest, multiple rooms, multiple booking IDs).
 *
 * WHY this is a separate service (not a method on Beds24Booking):
 *   - effectiveUsdAmount() is used in non-cashier contexts (print flow, nightly
 *     FX job) where per-room amounts are correct. Silently changing it would
 *     affect unrelated business logic.
 *   - This service is explicitly cashier-scoped and injected only where needed.
 *
 * Behavior:
 *   Standalone booking  → returns that booking's effectiveUsdAmount() unchanged.
 *   Grouped, all local  → sums effectiveUsdAmount() across all local siblings.
 *   Grouped, incomplete → attempts on-demand fetch from Beds24 API for missing
 *                         siblings; throws IncompleteGroupSyncException if fetch fails.
 */
class GroupAwareCashierAmountResolver
{
    public function __construct(
        private readonly Beds24BookingService $beds24Service,
    ) {}

    /**
     * @throws IncompleteGroupSyncException  when group is incomplete and on-demand
     *                                       fetch of missing siblings fails
     */
    public function resolve(Beds24Booking $booking): GroupAmountResolution
    {
        // ── Standalone booking ────────────────────────────────────────────────
        if ($booking->master_booking_id === null) {
            return GroupAmountResolution::standalone($booking->effectiveUsdAmount());
        }

        $masterBookingId = (string) $booking->master_booking_id;
        $expectedSize    = $booking->booking_group_size;  // may be null for legacy rows

        // ── Load all locally-synced siblings ──────────────────────────────────
        $siblings  = Beds24Booking::where('master_booking_id', $masterBookingId)->get();
        $localSize = $siblings->count();

        // ── Completeness check ────────────────────────────────────────────────
        if ($expectedSize && $localSize < $expectedSize) {
            Log::info('GroupAwareCashierAmountResolver: incomplete local sync — attempting on-demand fetch', [
                'master_booking_id' => $masterBookingId,
                'expected'          => $expectedSize,
                'local'             => $localSize,
                'entered_booking'   => $booking->beds24_booking_id,
            ]);

            // Identify which sibling IDs are missing locally
            $groupIds    = $this->extractGroupIds($booking);
            $knownIds    = $siblings->pluck('beds24_booking_id')->map(fn ($id) => (string) $id)->toArray();
            $missingIds  = array_values(array_diff(
                array_map('strval', $groupIds),
                $knownIds
            ));

            if (empty($missingIds)) {
                // booking_group_size is stale/wrong — trust local count
                Log::warning('GroupAwareCashierAmountResolver: missing IDs empty despite size mismatch; trusting local', [
                    'master_booking_id' => $masterBookingId,
                    'expected'          => $expectedSize,
                    'local'             => $localSize,
                ]);
            } else {
                [$fetchedTotal, $fetchedCount] = $this->fetchMissingAmounts($missingIds, $masterBookingId);

                if ($fetchedCount < count($missingIds)) {
                    // Could not retrieve all missing siblings — fail safe
                    throw new IncompleteGroupSyncException(
                        "Group booking master #{$masterBookingId}: expected {$expectedSize} rooms, " .
                        "only {$localSize} synced locally, fetched {$fetchedCount}/" . count($missingIds) .
                        " missing from Beds24 API. Cannot compute accurate group total."
                    );
                }

                $localTotal = $siblings->sum(fn (Beds24Booking $b) => $b->effectiveUsdAmount());

                return new GroupAmountResolution(
                    usdAmount:                $localTotal + $fetchedTotal,
                    isSingleBooking:          false,
                    effectiveMasterBookingId: $masterBookingId,
                    groupSizeExpected:        $expectedSize,
                    groupSizeLocal:           $localSize,
                    isGroupComplete:          false, // partial local sync — rest fetched on demand
                );
            }
        }

        // ── Full local sync (normal path) ─────────────────────────────────────
        $total = $siblings->sum(fn (Beds24Booking $b) => $b->effectiveUsdAmount());

        return new GroupAmountResolution(
            usdAmount:                $total,
            isSingleBooking:          false,
            effectiveMasterBookingId: $masterBookingId,
            groupSizeExpected:        $expectedSize ?? $localSize,
            groupSizeLocal:           $localSize,
            isGroupComplete:          true,
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Extract all booking IDs from the stored bookingGroup payload.
     * Returns empty array when raw data is missing or malformed.
     *
     * @return int[]
     */
    private function extractGroupIds(Beds24Booking $booking): array
    {
        $raw = $booking->beds24_raw_data;  // cast as array

        if (! is_array($raw)) {
            return [];
        }

        $ids = $raw['booking']['bookingGroup']['ids'] ?? [];

        return array_filter(array_map('intval', (array) $ids));
    }

    /**
     * Fetch USD amounts for sibling booking IDs that are not in the local DB.
     * Uses the same effectiveUsdAmount() logic: invoice_balance > 0 ? balance : price.
     *
     * @param  string[] $missingIds
     * @return array{float, int}  [totalAmount, successCount]
     */
    private function fetchMissingAmounts(array $missingIds, string $masterBookingId): array
    {
        $total   = 0.0;
        $fetched = 0;

        foreach ($missingIds as $siblingId) {
            try {
                $resp = $this->beds24Service->getBooking($siblingId);
                $raw  = $resp['data'][0] ?? null;

                if (! $raw) {
                    Log::warning('GroupAwareCashierAmountResolver: empty API response for sibling', [
                        'sibling_id'        => $siblingId,
                        'master_booking_id' => $masterBookingId,
                    ]);
                    continue;
                }

                $amount = $this->extractAmountFromApiResponse($raw);
                $total += $amount;
                $fetched++;
            } catch (\Throwable $e) {
                Log::warning('GroupAwareCashierAmountResolver: failed to fetch sibling from Beds24 API', [
                    'sibling_id'        => $siblingId,
                    'master_booking_id' => $masterBookingId,
                    'error'             => $e->getMessage(),
                ]);
                // Continue — caller checks fetched count
            }
        }

        return [$total, $fetched];
    }

    /**
     * Mirrors Beds24Booking::effectiveUsdAmount() for raw API responses.
     * invoice_balance > 0 → outstanding amount; otherwise → total price.
     */
    private function extractAmountFromApiResponse(array $raw): float
    {
        $price = (float) ($raw['booking']['price'] ?? 0);

        // Compute invoice balance: total minus sum of positive invoice payments
        $invoiceItems  = $raw['invoiceItems'] ?? [];
        $invoiceTotal  = 0.0;
        foreach ($invoiceItems as $item) {
            $amt = (float) ($item['amount'] ?? 0);
            if ($amt < 0) {
                // Negative items are payments in Beds24 convention
                $invoiceTotal += abs($amt);
            }
        }

        $balance = $price - $invoiceTotal;

        return $balance > 0 ? $balance : $price;
    }
}
