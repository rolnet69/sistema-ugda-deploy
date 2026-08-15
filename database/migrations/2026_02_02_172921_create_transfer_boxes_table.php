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
        Schema::create('transfer_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->onDelete('cascade');
            // Aquí guardamos la info que configuraste en el lote
            $table->string('series_name'); 
            $table->integer('start_year');
            $table->integer('end_year');
            $table->integer('box_number'); // El número correlativo de caja (1, 2, 3...)
            $table->text('content_description')->nullable(); // Para detallar después
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_boxes');
    }
};
