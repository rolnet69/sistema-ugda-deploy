<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileRoleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('profile_role') || !Schema::hasTable('profiles') || !Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $profiles = DB::table('profiles')->select('id', 'name')->get();

        foreach ($profiles as $profile) {
            $roleId = DB::table('roles')->where('name', $profile->name)->value('id');

            if ($roleId === null) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $profile->name,
                    'description' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            }

            DB::table('profile_role')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'profile_id' => $profile->id,
                ],
                [
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ]
            );
        }
    }
}
