<?php

namespace Database\Seeders;

use App\Models\AuthorizedStaff;
use Illuminate\Database\Seeder;

class AuthorizedStaffSeeder extends Seeder
{
    public function run(): void
    {
        // Add your phone number here
        AuthorizedStaff::create([
            'phone_number' => '+998901234567', // CHANGE THIS
            'full_name' => 'Admin User',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
