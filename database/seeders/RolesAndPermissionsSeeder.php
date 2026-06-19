<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create permissions (Idempotent)
        $permissions = [
            // Instituciones
            'ver instituciones',
            'crear instituciones',
            'editar instituciones',
            'desactivar instituciones',
            
            // Usuarios
            'ver usuarios',
            'crear usuarios',
            'editar usuarios',
            'desactivar usuarios',
            
            // CSV
            'cargar csv',
            'consultar csv',
            
            // Reportes PUI
            'ver reportes',
            'gestionar reportes',
            'capturar coincidencia',
            'enviar coincidencia',
            
            // Auditoría
            'ver auditoria',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // 2. Create roles and assign created permissions (Idempotent)

        // SUPER_ADMIN
        $superAdmin = Role::firstOrCreate(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
        // SUPER_ADMIN gets all permissions
        $superAdmin->syncPermissions(Permission::all());

        // ADMINISTRADOR
        $admin = Role::firstOrCreate(['name' => 'ADMINISTRADOR', 'guard_name' => 'web']);
        $adminPermissions = Permission::where('name', '!=', 'crear instituciones')->get();
        $admin->syncPermissions($adminPermissions);

        // OPERADOR
        $operador = Role::firstOrCreate(['name' => 'OPERADOR', 'guard_name' => 'web']);
        $operador->syncPermissions([
            'ver reportes',
            'capturar coincidencia',
            'enviar coincidencia',
        ]);

        // AUDITOR
        $auditor = Role::firstOrCreate(['name' => 'AUDITOR', 'guard_name' => 'web']);
        $auditor->syncPermissions([
            'ver reportes',
            'consultar csv',
            'ver auditoria',
        ]);
    }
}
