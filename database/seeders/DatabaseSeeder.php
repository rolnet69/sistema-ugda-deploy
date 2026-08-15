<?php

namespace Database\Seeders;

use App\Models\DocumentarySeries;
use App\Models\Profile;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
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
                'name' => 'Unidad de Gestión Documental y Archivos',
                'is_active' => true,
            ]
        );

        $decanatoUnit = Unit::updateOrCreate(
            ['code' => 'DEC-FIA'],
            [
                'name' => 'Decanato FIA',
                'is_active' => true,
            ]
        );

        $this->seedUserWithProfileAndUnits(
            email: 'admin-ugda@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Administrador',
                'second_name' => null,
                'first_last_name' => 'UGDA',
                'second_last_name' => null,
                'carnet' => null,
            ],
            profileName: 'Administrador',
            unitIds: [$ugdaUnit->id],
            now: $now,
        );

        $this->seedUserWithProfileAndUnits(
            email: 'usuario.ugda@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Carlos',
                'second_name' => null,
                'first_last_name' => 'Rodriguez',
                'second_last_name' => null,
                'carnet' => 'UGDA2026',
            ],
            profileName: 'Usuario UGDA',
            unitIds: [$ugdaUnit->id],
            now: $now,
        );

        $this->seedUserWithProfileAndUnits(
            email: 'unidad.solicitante@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Patricia',
                'second_name' => 'Marlene',
                'first_last_name' => 'Callejas',
                'second_last_name' => 'de Velásquez',
                'carnet' => 'US2026',
            ],
            profileName: 'Unidad Solicitante',
            unitIds: [$decanatoUnit->id],
            now: $now,
        );

        $this->seedUserWithProfileAndUnits(
            email: 'director.unidad@yopmail.com',
            password: 'password',
            personData: [
                'first_name' => 'Luis',
                'second_name' => 'Salvador',
                'first_last_name' => 'Barrera',
                'second_last_name' => 'Mancia',
                'carnet' => 'DJ2026',
            ],
            profileName: 'Director/Jefe de Unidad',
            unitIds: [$decanatoUnit->id],
            now: $now,
        );

        $this->call([
            RequestWorkflowDemoSeeder::class,
        ]);
    }

    private function seedUserWithProfileAndUnits(
        string $email,
        string $password,
        array $personData,
        string $profileName,
        array $unitIds,
        $now
    ): void {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'password' => $password,
                'is_active' => true,
            ]
        );

        DB::table('person')->updateOrInsert(
            ['user_id' => $user->id],
            array_merge($personData, [
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        $profile = Profile::withTrashed()->firstOrCreate(
            ['name' => $profileName],
            [
                'description' => null,
                'is_active' => true,
            ]
        );

        if ($profile->trashed()) {
            $profile->restore();
        }

        if (!$profile->is_active) {
            $profile->is_active = true;
            $profile->save();
        }

        DB::table('user_profile')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);

        DB::table('user_profile')->updateOrInsert(
            [
                'user_id' => $user->id,
                'profile_id' => $profile->id,
            ],
            [
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
                'deleted_at' => null,
            ]
        );

        DB::table('user_unit')
            ->where('user_id', $user->id)
            ->update([
                'is_active' => false,
                'updated_at' => $now,
                'deleted_at' => $now,
            ]);

        foreach ($unitIds as $index => $unitId) {
            DB::table('user_unit')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'unit_id' => $unitId,
                ],
                [
                    'is_active' => $index === 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
