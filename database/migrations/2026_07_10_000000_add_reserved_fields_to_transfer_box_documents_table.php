<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_box_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_box_documents', 'is_reserved')) {
                $table->boolean('is_reserved')->default(false)->after('sort_order');
            }

            if (!Schema::hasColumn('transfer_box_documents', 'reserved_by_user_id')) {
                $table->foreignId('reserved_by_user_id')
                    ->nullable()
                    ->after('is_reserved')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('transfer_box_documents', 'reserved_at')) {
                $table->dateTime('reserved_at')->nullable()->after('reserved_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfer_box_documents', function (Blueprint $table) {
            if (Schema::hasColumn('transfer_box_documents', 'reserved_by_user_id')) {
                $table->dropConstrainedForeignId('reserved_by_user_id');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('transfer_box_documents', 'reserved_at') ? 'reserved_at' : null,
                Schema::hasColumn('transfer_box_documents', 'is_reserved') ? 'is_reserved' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
