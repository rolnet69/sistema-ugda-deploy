<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminOnlySeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ProfileSeeder::class,
            ProfileRoleSeeder::class,
            EndpointRoleSeeder::class,
            DocumentarySeriesSeeder::class,
            RequestStatusCatalogSeeder::class,
        ]);

        $now = now();
        $ugdaUnit = Unit::updateOrCreate(
            ['code' => 'UGDA'],
            [
                'name' => 'Unidad de Gestion Documental y Archivos',
                'is_active' => true,
            ]
        );

        $adminProfileId = DB::table('profiles')
            ->where('name', 'Administrador')
            ->value('id');

        if ($adminProfileId === null) {
            throw new \RuntimeException('No existe el perfil Administrador.');
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin-ugda@yopmail.com'],
            [
                'password' => 'password',
                'is_active' => true,
                'two_factor_method' => 'email',
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'must_change_password' => false,
                'temporary_password_expires_at' => null,
            ]
        );

        DB::table('person')->updateOrInsert(
            ['user_id' => $admin->id],
            [
                'user_id' => $admin->id,
                'first_name' => 'Administrador',
                'second_name' => null,
                'first_last_name' => 'UGDA',
                'second_last_name' => null,
                'carnet' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('user_profile')->updateOrInsert(
            [
                'user_id' => $admin->id,
                'profile_id' => $adminProfileId,
            ],
            [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]
        );

        DB::table('user_unit')->updateOrInsert(
            [
                'user_id' => $admin->id,
                'unit_id' => $ugdaUnit->id,
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
