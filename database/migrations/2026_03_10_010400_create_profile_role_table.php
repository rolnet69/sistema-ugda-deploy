<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('profile_role')) {
            Schema::create('profile_role', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['role_id', 'profile_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('profile_role')) {
            Schema::drop('profile_role');
        }
    }
};
