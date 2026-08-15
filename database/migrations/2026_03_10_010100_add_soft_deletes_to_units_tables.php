<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('units') && !Schema::hasColumn('units', 'deleted_at')) {
            Schema::table('units', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('unit_dependencies') && !Schema::hasColumn('unit_dependencies', 'deleted_at')) {
            Schema::table('unit_dependencies', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('unit_dependencies') && Schema::hasColumn('unit_dependencies', 'deleted_at')) {
            Schema::table('unit_dependencies', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('units') && Schema::hasColumn('units', 'deleted_at')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
