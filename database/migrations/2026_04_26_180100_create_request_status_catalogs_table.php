<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_status_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('request_type', 30);
            $table->string('category', 30);
            $table->string('code', 60)->unique();
            $table->string('label', 120);
            $table->string('tone', 20)->default('neutral');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_status_catalogs');
    }
};
