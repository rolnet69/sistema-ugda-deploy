<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('profiles')) {
            return;
        }

        $now = now();
        $profiles = [
            ['name' => 'Administrador', 'description' => 'Acceso administrativo completo'],
            ['name' => 'Usuario UGDA', 'description' => 'Perfil operativo para consulta de reportes y gestión documental UGDA'],
            ['name' => 'Unidad Solicitante', 'description' => 'Perfil para crear y dar seguimiento a solicitudes de transferencia'],
            ['name' => 'Director/Jefe de Unidad', 'description' => 'Perfil para revisión y autorización de solicitudes de su unidad'],
        ];

        foreach ($profiles as $profile) {
            DB::table('profiles')->updateOrInsert(
                ['name' => $profile['name']],
                [
                    'description' => $profile['description'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
