<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\User;

class CashManagementRolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $cashier = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);

        // Define all cash management permissions
        $cashManagementPermissions = [
            // Cash Drawer permissions
            'view_any_cash_drawer',
            'view_cash_drawer',
            'create_cash_drawer',
            'update_cash_drawer',
            'delete_cash_drawer',
            'restore_cash_drawer',
            'force_delete_cash_drawer',

            // Cashier Shift permissions
            'view_any_cashier_shift',
            'view_cashier_shift',
            'create_cashier_shift',
            'update_cashier_shift',
            'delete_cashier_shift',
            'restore_cashier_shift',
            'force_delete_cashier_shift',

            // Cash Transaction permissions
            'view_any_cash_transaction',
            'view_cash_transaction',
            'create_cash_transaction',
            'update_cash_transaction',
            'delete_cash_transaction',
            'restore_cash_transaction',
            'force_delete_cash_transaction',
        ];

        // Create permissions
        foreach ($cashManagementPermissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web'
            ]);
        }

        // Assign permissions to roles

        // Super Admin: All permissions
        $superAdmin->givePermissionTo(Permission::all());

        // Admin: All cash management permissions
        $admin->givePermissionTo($cashManagementPermissions);

        // Manager: Most permissions except delete/force delete
        $managerPermissions = array_filter($cashManagementPermissions, function($permission) {
            return !str_contains($permission, 'delete') && !str_contains($permission, 'restore');
        });
        $manager->givePermissionTo($managerPermissions);

        // Cashier: Limited permissions (view, create transactions, update own shifts)
        $cashierPermissions = [
            'view_any_cash_drawer',
            'view_cash_drawer',
            'view_any_cashier_shift',
            'view_cashier_shift',
            'create_cashier_shift',
            'update_cashier_shift',
            'view_any_cash_transaction',
            'view_cash_transaction',
            'create_cash_transaction',
            'update_cash_transaction',
            'delete_cash_transaction',
        ];
        $cashier->givePermissionTo($cashierPermissions);

        // Assign roles to users
        $this->assignRolesToUsers();

        $this->command->info('✅ Cash Management roles and permissions created successfully!');
        $this->command->info('📋 Role Hierarchy:');
        $this->command->info('   🔴 Super Admin: Full system access');
        $this->command->info('   🟠 Admin: Full cash management access');
        $this->command->info('   🟡 Manager: Manage shifts and transactions');
        $this->command->info('   🟢 Cashier: Basic cash operations');
    }

    private function assignRolesToUsers(): void
    {
        // Assign Super Admin role to admin user
        $adminUser = User::where('email', 'admin@example.com')->first();
        if ($adminUser) {
            $adminUser->assignRole('super_admin');
            $this->command->info("✅ Assigned 'super_admin' role to {$adminUser->name}");
        }

        // Assign Manager role to manager user
        $managerUser = User::where('email', 'manager@example.com')->first();
        if ($managerUser) {
            $managerUser->assignRole('manager');
            $this->command->info("✅ Assigned 'manager' role to {$managerUser->name}");
        }

        // Assign Cashier role to cashier user
        $cashierUser = User::where('email', 'cashier@example.com')->first();
        if ($cashierUser) {
            $cashierUser->assignRole('cashier');
            $this->command->info("✅ Assigned 'cashier' role to {$cashierUser->name}");
        }
    }
}
