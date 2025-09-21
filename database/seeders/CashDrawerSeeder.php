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
        // Clear existing drawers first (soft delete)
        CashDrawer::query()->delete();

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

        foreach ($drawers as $drawer) {
            CashDrawer::create($drawer);
        }
    }
}