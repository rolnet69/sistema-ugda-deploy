<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dateTime('requested_at')->nullable()->after('request_date');
            $table->foreignId('authorization_status_id')
                ->nullable()
                ->after('status')
                ->constrained('request_status_catalogs');
            $table->foreignId('workflow_status_id')
                ->nullable()
                ->after('authorization_status_id')
                ->constrained('request_status_catalogs');
            $table->foreignId('authorized_by_user_id')
                ->nullable()
                ->after('workflow_status_id')
                ->constrained('users');
            $table->dateTime('authorized_at')->nullable()->after('authorized_by_user_id');
            $table->foreignId('completed_by_user_id')
                ->nullable()
                ->after('authorized_at')
                ->constrained('users');
            $table->dateTime('completed_at')->nullable()->after('completed_by_user_id');
            $table->dateTime('scheduled_for')->nullable()->after('completed_at');
            $table->string('view_mode', 20)->default('detail')->after('scheduled_for');
            $table->string('box_display_state', 20)->default('collapsed')->after('view_mode');
            $table->boolean('show_print_card')->default(false)->after('box_display_state');
            $table->text('description')->nullable()->after('show_print_card');
        });

        Schema::table('transfer_boxes', function (Blueprint $table) {
            $table->string('title')->nullable()->after('box_number');
            $table->string('period_label')->nullable()->after('title');
            $table->string('location_code')->nullable()->after('period_label');
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->after('location_code')
                ->constrained('users');
            $table->dateTime('assigned_at')->nullable()->after('assigned_by_user_id');
        });

        Schema::create('transfer_box_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_box_id')->constrained()->cascadeOnDelete();
            $table->string('code', 120);
            $table->string('name', 255);
            $table->string('series_label', 255)->nullable();
            $table->string('support_type', 30)->nullable();
            $table->string('year_label', 50)->nullable();
            $table->string('pages_label', 50)->nullable();
            $table->string('digital_file_name')->nullable();
            $table->string('digital_file_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_catalog_id')
                ->nullable()
                ->constrained('request_status_catalogs');
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users');
            $table->string('event_type', 30);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->json('context')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_events');
        Schema::dropIfExists('transfer_box_documents');

        Schema::table('transfer_boxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_user_id');
            $table->dropColumn([
                'title',
                'period_label',
                'location_code',
                'assigned_at',
            ]);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authorization_status_id');
            $table->dropConstrainedForeignId('workflow_status_id');
            $table->dropConstrainedForeignId('authorized_by_user_id');
            $table->dropConstrainedForeignId('completed_by_user_id');
            $table->dropColumn([
                'requested_at',
                'authorized_at',
                'completed_at',
                'scheduled_for',
                'view_mode',
                'box_display_state',
                'show_print_card',
                'description',
            ]);
        });
    }
};
