<?php

namespace Database\Seeders;

use App\Models\CashDrawer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CashDrawerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $drawers = [
            [
                'name' => 'Jahongir Hotel',
                'location' => 'Main Reception Area',
                'is_active' => true,
            ],
            [
                'name' => 'Jahongir Premium',
                'location' => 'Premium Services Desk',
                'is_active' => true,
            ],
        ];

        // Clear existing drawers (force delete to avoid unique constraints with soft deletes)
        CashDrawer::withTrashed()->whereIn('name', array_column($drawers, 'name'))->forceDelete();

        foreach ($drawers as $drawer) {
            CashDrawer::create($drawer);
        }
    }
}