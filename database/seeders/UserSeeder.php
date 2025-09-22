<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default users for testing
        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'admin@jahongir.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Manager',
                'email' => 'manager@jahongir.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
            [
                'name' => 'Cashier',
                'email' => 'cashier@jahongir.com',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }

        // Assign roles to users
        $admin = User::where('email', 'admin@jahongir.com')->first();
        $manager = User::where('email', 'manager@jahongir.com')->first();
        $cashier = User::where('email', 'cashier@jahongir.com')->first();

        if ($admin) {
            $admin->assignRole('super_admin');
        }

        if ($manager) {
            $manager->assignRole('manager');
        }

        if ($cashier) {
            $cashier->assignRole('cashier');
        }
    }
}
