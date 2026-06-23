<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Crear Institución Cyborg
        $institution = Institution::updateOrCreate(
            ['rfc' => 'CYB2109104G4'],
            [
                'name' => 'Financiera Cyborg',
                'is_active' => true,
                'pui_credentials' => [
                    'environment' => 'testing',
                    'api_version' => '2.3.0',
                    'api_user' => 'PUI',
                    'api_password' => 'password',
                ],
            ]
        );

        // 2. Crear usuarios demo
        $adminCyborg = User::updateOrCreate(
            ['email' => 'admin@cyborg.com'],
            [
                'name' => 'Administrador Cyborg',
                'password' => Hash::make('password'),
                'institution_id' => $institution->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $adminCyborg->syncRoles(['ADMINISTRADOR']);

        $operadorCyborg = User::updateOrCreate(
            ['email' => 'operador@cyborg.com'],
            [
                'name' => 'Operador Cyborg',
                'password' => Hash::make('password'),
                'institution_id' => $institution->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $operadorCyborg->syncRoles(['OPERADOR']);

        $auditorCyborg = User::updateOrCreate(
            ['email' => 'auditor@cyborg.com'],
            [
                'name' => 'Auditor Cyborg',
                'password' => Hash::make('password'),
                'institution_id' => $institution->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $auditorCyborg->syncRoles(['AUDITOR']);
    }
}
