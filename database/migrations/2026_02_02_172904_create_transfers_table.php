<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Ej: TR-2026-001
            $table->foreignId('user_id')->constrained(); // Solicitante
            $table->foreignId('unit_id')->constrained(); // Unidad productora
            $table->date('request_date');
            $table->enum('status', ['Borrador', 'Enviada', 'Aprobada', 'Rechazada'])->default('Borrador');
            $table->text('observation')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
