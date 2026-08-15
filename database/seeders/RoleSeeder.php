<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $baseRoles = [
            'Administrador',
            'Unidad Solicitante',
            'Director/Jefe de Unidad',
        ];

        foreach ($baseRoles as $roleName) {
            DB::table('roles')->updateOrInsert(
                ['name' => $roleName],
                [
                    'description' => null,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
