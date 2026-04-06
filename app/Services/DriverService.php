<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Driver;
use App\Models\StaffAuditLog;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD and lifecycle management for Driver records.
 *
 * All mutations are persisted to staff_audit_logs.
 * Callers are responsible for authorization before invoking mutating methods.
 */
class DriverService
{
    /**
     * Create a new driver record.
     *
     * Throws if another driver already has the same normalized phone01.
     *
     * @param  array{first_name: string, last_name: string, phone01: string, email: string, fuel_type: string, phone02?: string|null, address_city?: string|null, extra_details?: string|null}  $data
     * @throws \RuntimeException  on duplicate phone
     */
    public function create(array $data, string $actor): Driver
    {
        $this->assertNoDuplicatePhone(phone: trim($data['phone01']), exceptId: null);

        $driver = Driver::create([
            'first_name'    => trim($data['first_name']),
            'last_name'     => trim($data['last_name']),
            'phone01'       => trim($data['phone01']),
            'email'         => trim($data['email']),
            'fuel_type'     => trim($data['fuel_type']),
            'phone02'       => isset($data['phone02']) ? trim($data['phone02']) : null,
            'address_city'  => isset($data['address_city']) ? trim($data['address_city']) : null,
            'extra_details' => isset($data['extra_details']) ? trim($data['extra_details']) : null,
            'is_active'     => true,
        ]);

        StaffAuditLog::record(
            entityType: 'driver',
            entityId:   $driver->id,
            action:     'created',
            changes:    [
                'first_name' => $driver->first_name,
                'last_name'  => $driver->last_name,
                'phone01'    => $driver->phone01,
                'email'      => $driver->email,
                'fuel_type'  => $driver->fuel_type,
            ],
            actor:      $actor,
        );

        return $driver;
    }

    /**
     * Update one or more editable fields on an existing driver.
     * Only fields present in $data are touched; unchanged values are skipped.
     *
     * Throws if the new phone01 value is already used by a different driver.
     *
     * @param  array<string, mixed>  $data  Keys from: first_name, last_name, phone01, phone02,
     *                                       email, fuel_type, address_city, extra_details
     * @throws \RuntimeException  on duplicate phone
     */
    public function update(Driver $driver, array $data, string $actor): void
    {
        if (array_key_exists('phone01', $data) && $data['phone01'] !== null) {
            $this->assertNoDuplicatePhone(phone: trim((string) $data['phone01']), exceptId: $driver->id);
        }

        $allowed = ['first_name', 'last_name', 'phone01', 'phone02', 'email', 'fuel_type', 'address_city', 'extra_details'];
        $changes = [];

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $new = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            if ($new !== $driver->$field) {
                $changes[$field] = ['old' => $driver->$field, 'new' => $new];
            }
        }

        if (empty($changes)) {
            return;
        }

        $driver->update(array_map(fn ($v) => $v['new'], $changes));

        StaffAuditLog::record(
            entityType: 'driver',
            entityId:   $driver->id,
            action:     'updated',
            changes:    $changes,
            actor:      $actor,
        );
    }

    /**
     * Activate or deactivate a driver.
     * Deactivated drivers are hidden from the booking assignment UI.
     */
    public function setActive(Driver $driver, bool $active, string $actor): void
    {
        if ($driver->is_active === $active) {
            return;
        }

        $driver->update(['is_active' => $active]);

        StaffAuditLog::record(
            entityType: 'driver',
            entityId:   $driver->id,
            action:     $active ? 'activated' : 'deactivated',
            changes:    null,
            actor:      $actor,
        );
    }

    /**
     * Hard-delete a driver.
     *
     * Only allowed when the driver has no booking references.
     * Intended for admin use only — not exposed in the Telegram bot.
     *
     * @throws \RuntimeException  if the driver is referenced by bookings
     */
    public function delete(Driver $driver, string $actor): void
    {
        if ($driver->bookings()->exists()) {
            throw new \RuntimeException(
                "Driver #{$driver->id} cannot be deleted: they are referenced by one or more bookings."
            );
        }

        $snapshot = [
            'first_name' => $driver->first_name,
            'last_name'  => $driver->last_name,
            'phone01'    => $driver->phone01,
            'email'      => $driver->email,
            'is_active'  => $driver->is_active,
        ];

        $driverId = $driver->id;
        $driver->delete();

        StaffAuditLog::record(
            entityType: 'driver',
            entityId:   $driverId,
            action:     'deleted',
            changes:    $snapshot,
            actor:      $actor,
        );
    }

    /**
     * List drivers, optionally filtered to active-only.
     * Results are ordered by first_name.
     */
    public function list(bool $onlyActive = false): Collection
    {
        $query = Driver::orderBy('first_name');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Search drivers by normalized phone or partial name (case-insensitive).
     * Returns active and inactive records.
     */
    public function search(string $query): Collection
    {
        $normalizedQuery = PhoneNormalizer::normalize($query);

        return Driver::orderBy('first_name')
            ->where(function ($q) use ($query, $normalizedQuery) {
                $q->where('first_name', 'LIKE', "%{$query}%")
                  ->orWhere('last_name', 'LIKE', "%{$query}%");

                if ($normalizedQuery !== '') {
                    // Match phone by digits — compare normalized storage via REGEXP_REPLACE
                    $q->orWhereRaw(
                        "REGEXP_REPLACE(phone01, '[^0-9]', '') LIKE ?",
                        ["%{$normalizedQuery}%"]
                    );
                }
            })
            ->get();
    }

    /**
     * Return workload stats for a collection of drivers in two batched queries.
     *
     * Result keyed by driver_id:
     *   ['trips_today' => int, 'last_assigned_at' => string|null]
     *
     * No N+1 queries — uses GROUP BY over the bookings table.
     */
    public function getAssignmentStats(Collection $drivers): array
    {
        if ($drivers->isEmpty()) {
            return [];
        }

        $ids   = $drivers->pluck('id')->all();
        $today = Carbon::today()->toDateString();

        $rows = DB::table('bookings')
            ->selectRaw(
                'driver_id,'
                . ' SUM(CASE WHEN DATE(booking_start_date_time) = ? AND booking_status NOT IN (\'cancelled\') THEN 1 ELSE 0 END) AS trips_today,'
                . ' MAX(updated_at) AS last_assigned_at',
                [$today]
            )
            ->whereIn('driver_id', $ids)
            ->groupBy('driver_id')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row->driver_id] = [
                'trips_today'     => (int) $row->trips_today,
                'last_assigned_at' => $row->last_assigned_at,
            ];
        }

        return $stats;
    }

    /**
     * Find a driver by ID or throw if not found.
     *
     * @throws \RuntimeException
     */
    public function find(int $id): Driver
    {
        $driver = Driver::find($id);

        if (! $driver) {
            throw new \RuntimeException("Driver #{$id} not found.");
        }

        return $driver;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Throw if another driver already has the same normalized phone.
     *
     * @param  int|null  $exceptId  When updating, the current driver's own ID — skip self-match
     * @throws \RuntimeException
     */
    private function assertNoDuplicatePhone(string $phone, ?int $exceptId): void
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === '') {
            return; // empty/invalid phone — let model validation handle it
        }

        $existing = Driver::all(['id', 'phone01'])
            ->first(function (Driver $d) use ($normalized, $exceptId) {
                return $d->id !== $exceptId
                    && PhoneNormalizer::normalize($d->phone01) === $normalized;
            });

        if ($existing) {
            throw new \RuntimeException(
                "A driver with phone number {$phone} already exists (Driver #{$existing->id})."
            );
        }
    }
}
