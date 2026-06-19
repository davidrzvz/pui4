<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Ensure SUPER_ADMIN role exists
        $superAdminRole = Role::firstOrCreate(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);

        // 2. Create or update the admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@pui.local'],
            [
                'name' => 'Super Administrador',
                'password' => Hash::make('Admin123456!'),
                'institution_id' => null,
            ]
        );

        // 3. Assign SUPER_ADMIN role to the user
        // Use syncRoles to ensure they only have this role, or assignRole if just appending.
        $admin->syncRoles([$superAdminRole]);
    }
}
