<?php

namespace Database\Seeders;

use App\Models\RoomUnitMapping;
use Illuminate\Database\Seeder;

class RoomUnitMappingSeeder extends Seeder
{
    public function run(): void
    {
        RoomUnitMapping::truncate();

        $rooms = [
            [
                'unit_name' => '14',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94982',
                'room_name' => 'Double Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 45,
            ],
            [
                'unit_name' => '11',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94982',
                'room_name' => 'Double Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 45,
            ],
            [
                'unit_name' => '4',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94984',
                'room_name' => 'Single Room',
                'room_type' => 'double',
                'max_guests' => 1,
                'base_price' => 0,
            ],
            [
                'unit_name' => '8',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94984',
                'room_name' => 'Single Room',
                'room_type' => 'double',
                'max_guests' => 1,
                'base_price' => 0,
            ],
            [
                'unit_name' => '9',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94984',
                'room_name' => 'Single Room',
                'room_type' => 'double',
                'max_guests' => 1,
                'base_price' => 0,
            ],
            [
                'unit_name' => '7',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94986',
                'room_name' => 'Twin Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '6',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94986',
                'room_name' => 'Twin Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '2',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94986',
                'room_name' => 'Twin Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '5',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94991',
                'room_name' => 'Large Double Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '3',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '94991',
                'room_name' => 'Large Double Room',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '15',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '97215',
                'room_name' => 'Family Room',
                'room_type' => 'double',
                'max_guests' => 4,
                'base_price' => 0,
            ],
            [
                'unit_name' => '10',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '144341',
                'room_name' => 'twin/double',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '13',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '144341',
                'room_name' => 'twin/double',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
            [
                'unit_name' => '12',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '144342',
                'room_name' => 'Junior Suite',
                'room_type' => 'double',
                'max_guests' => 3,
                'base_price' => 0,
            ],
            [
                'unit_name' => '1',
                'property_id' => '41097',
                'property_name' => 'Jahongir Hotel',
                'room_id' => '152726',
                'room_name' => '1 xona',
                'room_type' => 'double',
                'max_guests' => 2,
                'base_price' => 0,
            ],
        ];

        foreach ($rooms as $room) {
            RoomUnitMapping::create($room);
        }

        $this->command->info('✅ Created ' . count($rooms) . ' room mappings');
    }
}
