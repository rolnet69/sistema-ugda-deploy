<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentary_subseries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documentary_series_id')
                ->constrained('documentary_series');
            $table->string('code', 2);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['documentary_series_id', 'code'], 'doc_subseries_series_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentary_subseries');
    }
};
