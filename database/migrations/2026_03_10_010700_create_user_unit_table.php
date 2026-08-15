<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_unit')) {
            Schema::create('user_unit', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['user_id', 'unit_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_unit')) {
            Schema::drop('user_unit');
        }
    }
};
