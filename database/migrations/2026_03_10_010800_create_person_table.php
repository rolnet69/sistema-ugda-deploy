<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('person')) {
            Schema::create('person', function (Blueprint $table) {
                $table->id();
                $table->string('first_name')->nullable();
                $table->string('second_name')->nullable();
                $table->string('first_last_name')->nullable();
                $table->string('second_last_name')->nullable();
                $table->string('carnet')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('person')) {
            Schema::drop('person');
        }
    }
};
