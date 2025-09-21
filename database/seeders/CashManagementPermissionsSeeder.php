<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class CashManagementPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions for cash management resources
        $permissions = [
            'view_any_cash_drawer',
            'view_cash_drawer',
            'create_cash_drawer',
            'edit_cash_drawer',
            'delete_cash_drawer',
            'view_any_cashier_shift',
            'view_cashier_shift',
            'create_cashier_shift',
            'edit_cashier_shift',
            'delete_cashier_shift',
            'view_any_cash_transaction',
            'view_cash_transaction',
            'create_cash_transaction',
            'edit_cash_transaction',
            'delete_cash_transaction',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Assign all permissions to super_admin role
        $superAdmin = Role::findByName('super_admin');
        $superAdmin->givePermissionTo($permissions);

        // Assign basic permissions to cashier role
        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->givePermissionTo([
            'view_any_cash_drawer',
            'view_cash_drawer',
            'view_any_cashier_shift',
            'view_cashier_shift',
            'create_cashier_shift',
            'edit_cashier_shift',
            'view_any_cash_transaction',
            'view_cash_transaction',
            'create_cash_transaction',
        ]);

        // Assign all permissions to manager role
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $manager->givePermissionTo($permissions);

        $this->command->info('Cash Management permissions created and assigned successfully!');
    }
}