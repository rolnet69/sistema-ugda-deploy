<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentary_series_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documentary_series_id')
                ->constrained('documentary_series')
                ->cascadeOnDelete();
            $table->foreignId('unit_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['documentary_series_id', 'unit_id'], 'doc_series_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentary_series_unit');
    }
};
