<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Driver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * CRUD and lifecycle management for Driver records.
 *
 * All mutations are logged to the application log channel with actor context.
 * Callers are responsible for authorization before invoking these methods.
 */
class DriverService
{
    /**
     * Create a new driver record.
     *
     * @param  array{first_name: string, last_name: string, phone01: string, email: string, fuel_type: string, phone02?: string|null, address_city?: string|null, extra_details?: string|null}  $data
     */
    public function create(array $data, string $actor): Driver
    {
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

        Log::info('DriverService: driver created', [
            'driver_id' => $driver->id,
            'name'      => $driver->full_name,
            'actor'     => $actor,
        ]);

        return $driver;
    }

    /**
     * Update one or more editable fields on an existing driver.
     * Only fields present in $data are touched; unchanged values are skipped.
     *
     * @param  array<string, mixed>  $data  Keys from: first_name, last_name, phone01, phone02,
     *                                       email, fuel_type, address_city, extra_details
     */
    public function update(Driver $driver, array $data, string $actor): void
    {
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

        Log::info('DriverService: driver updated', [
            'driver_id' => $driver->id,
            'actor'     => $actor,
            'changes'   => $changes,
        ]);
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

        Log::info('DriverService: driver ' . ($active ? 'activated' : 'deactivated'), [
            'driver_id' => $driver->id,
            'name'      => $driver->full_name,
            'actor'     => $actor,
        ]);
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
}
