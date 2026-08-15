<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('request_status_catalogs')->updateOrInsert(
            ['code' => 'loan_search_not_found'],
            [
                'request_type' => 'loan',
                'category' => 'search',
                'label' => 'Busqueda: No encontrados',
                'tone' => 'danger',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('request_status_catalogs')
            ->where('code', 'loan_search_not_found')
            ->delete();
    }
};
