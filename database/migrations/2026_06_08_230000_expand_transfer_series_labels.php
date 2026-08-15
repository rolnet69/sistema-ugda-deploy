<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transfer_boxes ALTER COLUMN series_name TYPE TEXT');
        DB::statement('ALTER TABLE transfer_box_documents ALTER COLUMN series_label TYPE TEXT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transfer_boxes ALTER COLUMN series_name TYPE VARCHAR(255)');
        DB::statement('ALTER TABLE transfer_box_documents ALTER COLUMN series_label TYPE VARCHAR(255)');
    }
};
