<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Creates the `hr` Spatie role used to scope access to the HR job
 * candidate Filament resource (PII access).
 *
 * Phase 1, 2026-05-11. Run manually after deploy:
 *   php artisan db:seed --class=HrRoleSeeder
 *
 * Idempotent — safe to re-run.
 */
class HrRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate([
            'name' => 'hr',
            'guard_name' => 'web',
        ]);
    }
}
