<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DocumentarySeriesSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('documentary_series') || !Schema::hasTable('documentary_subseries')) {
            return;
        }

        $now = now();
        $catalog = [
            [
                'code' => '010',
                'name' => 'ACTAS',
                'subseries' => [
                    ['code' => '01', 'name' => 'Actas Asamblea General Universitaria'],
                    ['code' => '02', 'name' => 'Actas Consejo Superior Universitario'],
                    ['code' => '03', 'name' => 'Actas Junta Directiva de Facultad'],
                ],
            ],
            [
                'code' => '020',
                'name' => 'CORRESPONDENCIA',
                'subseries' => [
                    ['code' => '01', 'name' => 'Correspondencia Interna'],
                    ['code' => '02', 'name' => 'Correspondencia Externa'],
                ],
            ],
            [
                'code' => '030',
                'name' => 'INFORMES',
                'subseries' => [
                    ['code' => '01', 'name' => 'Informes Anuales'],
                    ['code' => '02', 'name' => 'Informes Financieros'],
                ],
            ],
            [
                'code' => '040',
                'name' => 'EXPEDIENTES',
                'subseries' => [
                    ['code' => '01', 'name' => 'Expedientes de Personal'],
                    ['code' => '02', 'name' => 'Expedientes Académicos'],
                ],
            ],
        ];

        foreach ($catalog as $seriesData) {
            DB::table('documentary_series')->updateOrInsert(
                ['code' => $seriesData['code']],
                [
                    'name' => $seriesData['name'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]
            );

            $seriesId = DB::table('documentary_series')
                ->where('code', $seriesData['code'])
                ->value('id');

            foreach ($seriesData['subseries'] as $subseriesData) {
                DB::table('documentary_subseries')->updateOrInsert(
                    [
                        'documentary_series_id' => $seriesId,
                        'code' => $subseriesData['code'],
                    ],
                    [
                        'name' => $subseriesData['name'],
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
