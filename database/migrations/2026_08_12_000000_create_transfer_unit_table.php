<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')
                ->constrained('transfers')
                ->cascadeOnDelete();
            $table->foreignId('unit_id')
                ->constrained('units')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['transfer_id', 'unit_id'], 'transfer_unit_unique');
        });

        DB::table('transfers')
            ->select(['id', 'unit_id'])
            ->orderBy('id')
            ->each(function (object $transfer): void {
                DB::table('transfer_unit')->insert([
                    'transfer_id' => $transfer->id,
                    'unit_id' => $transfer->unit_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_unit');
    }
};
