<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentary_subseries_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documentary_subseries_id')
                ->constrained('documentary_subseries')
                ->cascadeOnDelete();
            $table->foreignId('unit_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['documentary_subseries_id', 'unit_id'], 'doc_subseries_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentary_subseries_unit');
    }
};
