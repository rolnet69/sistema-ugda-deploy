<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documentary_series ALTER COLUMN code TYPE VARCHAR(20)');
        DB::statement('ALTER TABLE documentary_subseries ALTER COLUMN code TYPE VARCHAR(20)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documentary_series ALTER COLUMN code TYPE VARCHAR(3)');
        DB::statement('ALTER TABLE documentary_subseries ALTER COLUMN code TYPE VARCHAR(2)');
    }
};
