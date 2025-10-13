<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first hotel (or create one if none exists)
        $hotel = Hotel::first();

        if (!$hotel) {
            $hotel = Hotel::create([
                'name' => 'Grand Hotel',
                'address' => '123 Main Street',
                'phone' => '+998901234567',
            ]);
        }

        // Create sample locations
        $locations = [
            [
                'name' => 'Restaurant',
                'status' => 'active',
                'description' => 'Main hotel restaurant serving breakfast, lunch, and dinner',
            ],
            [
                'name' => 'Bar',
                'status' => 'active',
                'description' => 'Lobby bar open 24/7',
            ],
            [
                'name' => 'Front Desk',
                'status' => 'active',
                'description' => 'Reception and check-in area',
            ],
            [
                'name' => 'Pool Bar',
                'status' => 'inactive',
                'description' => 'Seasonal poolside bar (currently closed)',
            ],
            [
                'name' => 'Gift Shop',
                'status' => 'active',
                'description' => 'Souvenir and convenience store',
            ],
        ];

        foreach ($locations as $locationData) {
            Location::firstOrCreate(
                [
                    'hotel_id' => $hotel->id,
                    'name' => $locationData['name'],
                ],
                [
                    'status' => $locationData['status'],
                    'description' => $locationData['description'],
                ]
            );
        }

        // Assign sample users to locations (if users exist)
        $cashiers = User::whereHas('roles', function ($query) {
            $query->where('name', 'cashier');
        })->get();

        if ($cashiers->isNotEmpty()) {
            // Get locations
            $restaurant = Location::where('name', 'Restaurant')->first();
            $bar = Location::where('name', 'Bar')->first();
            $frontDesk = Location::where('name', 'Front Desk')->first();

            // Assign first cashier to Restaurant
            if ($restaurant && $cashiers->count() > 0) {
                $restaurant->users()->syncWithoutDetaching([$cashiers[0]->id]);
            }

            // Assign second cashier to Bar and Front Desk (multiple locations)
            if ($bar && $frontDesk && $cashiers->count() > 1) {
                $bar->users()->syncWithoutDetaching([$cashiers[1]->id]);
                $frontDesk->users()->syncWithoutDetaching([$cashiers[1]->id]);
            }

            // Assign third cashier to Front Desk
            if ($frontDesk && $cashiers->count() > 2) {
                $frontDesk->users()->syncWithoutDetaching([$cashiers[2]->id]);
            }
        }
    }
}
