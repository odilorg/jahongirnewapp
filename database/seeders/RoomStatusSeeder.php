<?php

namespace Database\Seeders;

use App\Models\RoomStatus;
use Illuminate\Database\Seeder;

class RoomStatusSeeder extends Seeder
{
    private array $rooms = [
        [41097, 94982,  'Double Room',        2],
        [41097, 94984,  'Single Room',        3],
        [41097, 94986,  'Twin Room',          3],
        [41097, 94991,  'Large Double Room',  2],
        [41097, 97215,  'Family Room',        1],
        [41097, 144341, 'Twin/Double',        2],
        [41097, 144342, 'Junior Suite',       1],
        [41097, 152726, '1 xona',             1],
        [172793, 377291, 'Double or Twin',        2],
        [172793, 377298, 'Deluxe Single',         1],
        [172793, 377299, 'Standard Queen',        2],
        [172793, 377300, 'Standard Double',       2],
        [172793, 377301, 'Deluxe Double/Twin',    2],
        [172793, 377302, 'Superior Double',       4],
        [172793, 377303, 'Superior Double/Twin',  4],
        [172793, 377304, 'Deluxe Triple',         2],
    ];

    public function run(): void
    {
        foreach ($this->rooms as [$propertyId, $roomId, $roomName, $unitCount]) {
            for ($unit = 1; $unit <= $unitCount; $unit++) {
                RoomStatus::updateOrCreate(
                    [
                        'beds24_property_id' => $propertyId,
                        'beds24_room_id'     => $roomId,
                        'unit_number'        => $unit,
                    ],
                    [
                        'room_name' => $roomName,
                        'status'    => 'dirty',
                    ]
                );
            }
        }

        $total = RoomStatus::count();
        $this->command->info('RoomStatusSeeder: ' . $total . ' room units seeded.');
    }
}
