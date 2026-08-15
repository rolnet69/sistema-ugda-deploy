<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE physical_location_offices ALTER COLUMN code TYPE VARCHAR(100)');
        DB::statement('ALTER TABLE physical_location_aisles ALTER COLUMN code TYPE VARCHAR(100)');
        DB::statement('ALTER TABLE physical_location_shelves ALTER COLUMN code TYPE VARCHAR(100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE physical_location_offices ALTER COLUMN code TYPE VARCHAR(20)');
        DB::statement('ALTER TABLE physical_location_aisles ALTER COLUMN code TYPE VARCHAR(20)');
        DB::statement('ALTER TABLE physical_location_shelves ALTER COLUMN code TYPE VARCHAR(20)');
    }
};
