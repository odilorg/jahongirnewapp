<?php

declare(strict_types=1);

namespace App\Services\Beds24;

use App\Models\Beds24Booking;
use App\Services\Beds24BookingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.7.0 — Canonical Group Resolver.
 *
 * Doctrine: never build payment orchestration on potentially stale
 * topology. This service is the single source of group truth that
 * payment flows consult before opening the bulk path.
 *
 * Strategy:
 *   1. Local lookup first (fast, free)
 *   2. On suspicion (master null OR <2 siblings with non-null master)
 *      → query Beds24 live for the canonical group
 *   3. Repair local DB to match Beds24 truth
 *   4. Return resolution with confidence state for caller telemetry
 *
 * Confidence states:
 *   - trusted_local   — local data already matches; no API call made
 *   - repaired_live   — Beds24 was queried and local rows updated
 *   - incomplete      — Beds24 returned partial data (rare)
 *   - failed_remote   — API unavailable; caller decides fail-safe path
 *
 * Performance: Beds24 API call only on suspicion, NOT on every
 * booking pick. Suspicion signals are master_booking_id=NULL OR
 * a non-null master with <2 local siblings (which would be invalid
 * group state).
 */
class Beds24GroupResolver
{
    public function __construct(
        private Beds24BookingService $beds24,
    ) {}

    public function resolve(Beds24Booking $booking): GroupResolution
    {
        $localMaster = $booking->master_booking_id;

        // Path A — local says "no group" (master is null).
        // Could be truly standalone, OR could be stale local data
        // where Beds24 has assigned a master since our last sync.
        // Trigger live verification.
        if ($localMaster === null) {
            return $this->verifyLive($booking);
        }

        // Path B — local says master is set. Verify sibling count is
        // sensible (a non-null master with 0 or 1 siblings is invalid
        // group state — should always be ≥2 for a real group).
        $localSiblings = Beds24Booking::where('master_booking_id', $localMaster)
            ->orderBy('beds24_booking_id')
            ->get();

        if ($localSiblings->count() >= 2) {
            return new GroupResolution(
                state: 'trusted_local',
                siblings: $localSiblings,
                masterBookingId: (string) $localMaster,
            );
        }

        // <2 siblings but non-null master — something off; live-verify.
        return $this->verifyLive($booking);
    }

    /**
     * Query Beds24 for the booking + its master, repair local DB if
     * needed, return the canonical group composition.
     */
    private function verifyLive(Beds24Booking $booking): GroupResolution
    {
        $bid = (string) $booking->beds24_booking_id;

        try {
            $singleResp = $this->beds24->getBooking($bid);
            $rows = $singleResp['data'] ?? [];
            $live = $rows[0] ?? null;
            if (! $live) {
                Log::warning('GroupResolver: Beds24 returned empty for booking', ['booking_id' => $bid]);
                return $this->failed($booking);
            }

            $liveMaster = $live['masterId'] ?? null;

            // Beds24 confirms standalone — update local if drifted, then return solo.
            if ($liveMaster === null) {
                if ($booking->master_booking_id !== null) {
                    Log::info('GroupResolver: local had master but Beds24 says standalone', [
                        'booking_id' => $bid,
                        'old_master' => $booking->master_booking_id,
                    ]);
                    $booking->forceFill(['master_booking_id' => null])->save();
                }
                return new GroupResolution(
                    state: 'trusted_local',
                    siblings: collect([$booking->refresh()]),
                    masterBookingId: null,
                );
            }

            // Beds24 says this booking belongs to a group. Fetch the full group + repair.
            return $this->repairFromMaster($booking, (string) $liveMaster);

        } catch (\Throwable $e) {
            Log::warning('GroupResolver: Beds24 unavailable on resolve', [
                'booking_id' => $bid,
                'error'      => $e->getMessage(),
            ]);
            return $this->failed($booking);
        }
    }

    /**
     * Pull full group from Beds24 and upsert each row into local DB.
     * Logs every repair with old/new master + siblings added/updated for
     * audit traceability.
     */
    private function repairFromMaster(Beds24Booking $picked, string $masterId): GroupResolution
    {
        try {
            $resp = $this->beds24->apiCall('GET', '/bookings', [], ['masterId' => $masterId]);
            $remote = json_decode($resp->body(), true);
            $rows = $remote['data'] ?? [];

            if (empty($rows)) {
                Log::warning('GroupResolver: master query returned no rows', [
                    'master_id'  => $masterId,
                    'booking_id' => $picked->beds24_booking_id,
                ]);
                return $this->failed($picked);
            }

            $created = 0; $updated = 0; $unchanged = 0;
            foreach ($rows as $bk) {
                $bid = (string) ($bk['id'] ?? '');
                if ($bid === '' || empty($bk['arrival'])) continue;

                $existing = Beds24Booking::where('beds24_booking_id', $bid)->first();
                $thisMaster = (string) ($bk['masterId'] ?? $bid); // self-reference for masters

                $first = trim((string) ($bk['firstName'] ?? ''));
                $last  = trim((string) ($bk['lastName']  ?? ''));
                $name  = trim($first.' '.$last) ?: 'Guest';
                $price = (float) ($bk['price'] ?? 0);
                $deposit = (float) ($bk['deposit'] ?? 0);
                $balance = max(0.0, $price - $deposit);
                $rawStatus = (string) ($bk['status'] ?? 'confirmed');
                $status = in_array($rawStatus, ['confirmed', 'new'], true) ? $rawStatus : 'confirmed';

                $payload = [
                    'property_id'        => (string) ($bk['propertyId'] ?? '41097'),
                    'master_booking_id'  => $thisMaster,
                    'guest_name'         => $name,
                    'arrival_date'       => $bk['arrival'],
                    'departure_date'     => $bk['departure'] ?? null,
                    'status'             => $status,
                    'total_amount'       => $price,
                    'currency'           => 'USD',
                    'invoice_balance'    => $balance,
                    'beds24_raw_data'    => $bk,
                ];

                if (! $existing) {
                    Beds24Booking::create(array_merge(['beds24_booking_id' => $bid], $payload));
                    $created++;
                } elseif ((string) ($existing->master_booking_id ?? '') !== $thisMaster
                    || (float) $existing->total_amount !== $price) {
                    $existing->forceFill($payload)->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            // Audit log — every repair leaves an explicit trail.
            Log::info('GroupResolver: repaired from live Beds24', [
                'booking_id'      => (string) $picked->beds24_booking_id,
                'old_master'      => $picked->master_booking_id,
                'new_master'      => $masterId,
                'siblings_total'  => count($rows),
                'siblings_created'=> $created,
                'siblings_updated'=> $updated,
                'siblings_unchanged' => $unchanged,
                'repair_source'   => 'beds24_live',
            ]);

            $siblings = Beds24Booking::where('master_booking_id', $masterId)
                ->orderBy('beds24_booking_id')
                ->get();

            return new GroupResolution(
                state: 'repaired_live',
                siblings: $siblings,
                masterBookingId: $masterId,
                repairStats: [
                    'created'   => $created,
                    'updated'   => $updated,
                    'unchanged' => $unchanged,
                ],
            );

        } catch (\Throwable $e) {
            Log::warning('GroupResolver: repair failed mid-flight', [
                'master_id' => $masterId,
                'error'     => $e->getMessage(),
            ]);
            return $this->failed($picked);
        }
    }

    private function failed(Beds24Booking $booking): GroupResolution
    {
        return new GroupResolution(
            state: 'failed_remote',
            siblings: collect([$booking]),
            masterBookingId: $booking->master_booking_id ? (string) $booking->master_booking_id : null,
        );
    }
}

/**
 * Immutable result of a group resolution. Caller (bot/admin/audit)
 * uses $state for confidence-based UX decisions.
 */
final class GroupResolution
{
    public function __construct(
        public readonly string     $state, // trusted_local | repaired_live | incomplete | failed_remote
        public readonly Collection $siblings,
        public readonly ?string    $masterBookingId = null,
        public readonly array      $repairStats = [],
    ) {}

    public function isGroup(): bool
    {
        return $this->siblings->count() >= 2;
    }

    public function isTrustworthy(): bool
    {
        return in_array($this->state, ['trusted_local', 'repaired_live'], true);
    }

    public function wasRepaired(): bool
    {
        return $this->state === 'repaired_live';
    }
}
