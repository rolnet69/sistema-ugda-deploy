<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60)->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->dateTime('requested_at');
            $table->foreignId('authorization_status_id')
                ->nullable()
                ->constrained('request_status_catalogs');
            $table->foreignId('workflow_status_id')
                ->nullable()
                ->constrained('request_status_catalogs');
            $table->foreignId('search_status_id')
                ->nullable()
                ->constrained('request_status_catalogs');
            $table->foreignId('authorized_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->dateTime('authorized_at')->nullable();
            $table->foreignId('ugda_authorized_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->dateTime('ugda_authorized_at')->nullable();
            $table->foreignId('search_started_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->dateTime('search_started_at')->nullable();
            $table->foreignId('search_completed_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->dateTime('search_completed_at')->nullable();
            $table->text('search_comments')->nullable();
            $table->string('view_mode', 20)->default('detail');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('document_kind', 20);
            $table->string('group_title', 120);
            $table->string('title', 255);
            $table->string('series_label', 255)->nullable();
            $table->string('box_code', 120)->nullable();
            $table->string('year_label', 60)->nullable();
            $table->string('unit_name_snapshot', 255)->nullable();
            $table->string('document_type_label', 60)->nullable();
            $table->string('document_type_tone', 20)->default('info');
            $table->string('quantity_label', 120)->nullable();
            $table->text('note')->nullable();
            $table->boolean('found_in_search')->default(false);
            $table->boolean('selected_for_loan')->default(false);
            $table->boolean('returned')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('loan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_catalog_id')
                ->nullable()
                ->constrained('request_status_catalogs');
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users');
            $table->string('actor_name_snapshot', 255)->nullable();
            $table->string('event_type', 30);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->json('context')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
        });

        Schema::create('loan_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('loan_date');
            $table->date('due_date');
            $table->string('received_by_name', 255);
            $table->foreignId('delivered_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('loan_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('return_date');
            $table->foreignId('received_by_user_id')
                ->nullable()
                ->constrained('users');
            $table->string('condition_label', 255);
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_document_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_return_items');
        Schema::dropIfExists('loan_returns');
        Schema::dropIfExists('loan_dispatch_items');
        Schema::dropIfExists('loan_dispatches');
        Schema::dropIfExists('loan_events');
        Schema::dropIfExists('loan_documents');
        Schema::dropIfExists('loans');
    }
};
