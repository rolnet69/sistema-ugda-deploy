<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_boxes', function (Blueprint $table) {
            $table->string('box_code', 120)->nullable()->after('box_number');
            $table->unique('box_code');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_boxes', function (Blueprint $table) {
            $table->dropUnique('transfer_boxes_box_code_unique');
            $table->dropColumn('box_code');
        });
    }
};
