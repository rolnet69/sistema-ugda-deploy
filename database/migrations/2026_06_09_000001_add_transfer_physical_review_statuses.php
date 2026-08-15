<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = [
            [
                'request_type' => 'transfer',
                'category' => 'workflow',
                'code' => 'transfer_status_physical_review',
                'label' => 'En Revisión',
                'tone' => 'warning',
                'sort_order' => 35,
            ],
            [
                'request_type' => 'transfer',
                'category' => 'workflow',
                'code' => 'transfer_status_physical_observed',
                'label' => 'Obs. en Revisión',
                'tone' => 'warning',
                'sort_order' => 36,
            ],
            [
                'request_type' => 'transfer',
                'category' => 'workflow',
                'code' => 'transfer_status_subsanated',
                'label' => 'Subsanada',
                'tone' => 'info',
                'sort_order' => 37,
            ],
        ];

        foreach ($statuses as $status) {
            DB::table('request_status_catalogs')->updateOrInsert(
                ['code' => $status['code']],
                $status + [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('request_status_catalogs')
            ->whereIn('code', [
                'transfer_status_physical_review',
                'transfer_status_physical_observed',
                'transfer_status_subsanated',
            ])
            ->delete();
    }
};
