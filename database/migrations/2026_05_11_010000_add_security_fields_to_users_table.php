<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_method')) {
                $table->string('two_factor_method', 30)->default('email')->after('two_factor_expires_at');
            }

            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            }

            if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            }

            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('two_factor_confirmed_at');
            }

            if (!Schema::hasColumn('users', 'temporary_password_expires_at')) {
                $table->dateTime('temporary_password_expires_at')->nullable()->after('must_change_password');
            }

            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->dateTime('password_changed_at')->nullable()->after('temporary_password_expires_at');
            }

            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->dateTime('last_login_at')->nullable()->after('password_changed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'two_factor_method',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'must_change_password',
                'temporary_password_expires_at',
                'password_changed_at',
                'last_login_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
