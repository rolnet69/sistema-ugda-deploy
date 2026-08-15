<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_location_offices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('physical_location_aisles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_location_office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['physical_location_office_id', 'code']);
        });

        Schema::create('physical_location_shelves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('physical_location_aisle_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['physical_location_aisle_id', 'code']);
        });

        $now = now();
        $officeId = DB::table('physical_location_offices')->insertGetId([
            'name' => 'Archivo Central',
            'code' => 'OF01',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $aisles = [
            ['name' => 'Pasillo A', 'code' => 'A'],
            ['name' => 'Pasillo B', 'code' => 'B'],
            ['name' => 'Pasillo C', 'code' => 'C'],
            ['name' => 'Area temporal', 'code' => 'TMP'],
        ];

        foreach ($aisles as $aisle) {
            $aisleId = DB::table('physical_location_aisles')->insertGetId([
                'physical_location_office_id' => $officeId,
                'name' => $aisle['name'],
                'code' => $aisle['code'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (range(1, 20) as $number) {
                DB::table('physical_location_shelves')->insert([
                    'physical_location_aisle_id' => $aisleId,
                    'name' => 'Estante ' . $number,
                    'code' => str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_location_shelves');
        Schema::dropIfExists('physical_location_aisles');
        Schema::dropIfExists('physical_location_offices');
    }
};
