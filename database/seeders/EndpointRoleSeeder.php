<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EndpointRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('profiles') || !Schema::hasTable('profile_role')) {
            return;
        }

        $now = now();

        $endpointRoles = [
            'endpoint.api.user.read' => 'Permite consultar el usuario autenticado',
            'endpoint.api.profiles.read' => 'Permite listar perfiles disponibles',
            'endpoint.api.users.read' => 'Permite listar usuarios',
            'endpoint.api.users.create' => 'Permite crear usuarios',
            'endpoint.api.users.update' => 'Permite actualizar usuarios',
            'endpoint.api.users.delete' => 'Permite eliminar usuarios',
            'endpoint.api.units.read' => 'Permite listar unidades',
            'endpoint.api.units.create' => 'Permite crear unidades',
            'endpoint.api.units.update' => 'Permite actualizar unidades',
            'endpoint.api.units.delete' => 'Permite eliminar unidades',
            'endpoint.api.documentary-series.read' => 'Permite listar series y subseries documentales',
            'endpoint.api.documentary-series.create' => 'Permite crear series y subseries documentales',
            'endpoint.api.documentary-series.update' => 'Permite actualizar series y subseries documentales',
            'endpoint.api.documentary-series.delete' => 'Permite eliminar series y subseries documentales',
            'endpoint.ui.reports.read' => 'Permite acceder al modulo de reportes dinamicos',
        ];

        foreach ($endpointRoles as $name => $description) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'description' => $description,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }

        $permissionsByProfile = [
            'Administrador' => array_keys($endpointRoles),
            'Unidad Solicitante' => [
                'endpoint.api.user.read',
                'endpoint.api.units.read',
                'endpoint.api.documentary-series.read',
            ],
            'Director/Jefe de Unidad' => [
                'endpoint.api.user.read',
                'endpoint.api.units.read',
            ],
            'Usuario UGDA' => [
                'endpoint.api.user.read',
                'endpoint.api.units.read',
                'endpoint.api.documentary-series.read',
                'endpoint.ui.reports.read',
            ],
        ];

        foreach ($permissionsByProfile as $profileName => $permissions) {
            $profileId = DB::table('profiles')->where('name', $profileName)->value('id');

            if ($profileId === null) {
                continue;
            }

            $roleIds = DB::table('roles')
                ->whereIn('name', $permissions)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('profile_role')->updateOrInsert(
                    [
                        'profile_id' => $profileId,
                        'role_id' => $roleId,
                    ],
                    [
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]
                );
            }
        }
    }
}
