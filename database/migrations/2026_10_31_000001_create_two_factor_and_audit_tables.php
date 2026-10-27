<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in (TOTP, an authenticator app) for accounts, required for those with the
 * payroll menus; and the audit log of sensitive actions (salary data, payroll runs, exports,
 * passwords and 2FA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $t->text('two_factor_secret')->nullable();
                $t->text('two_factor_recovery_codes')->nullable();
                $t->timestamp('two_factor_confirmed_at')->nullable();
                $t->unsignedBigInteger('two_factor_last_step')->nullable(); // a code is accepted once
            }
        });

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('user_name', 150)->nullable();
                $t->string('action', 50);
                $t->string('subject_type', 50)->nullable();
                $t->unsignedBigInteger('subject_id')->nullable();
                $t->string('description', 500)->nullable();
                $t->json('meta')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['action', 'created_at']);
                $t->index(['user_id', 'created_at']);
                $t->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'two_factor_secret')) {
                $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
            }
        });
    }
};
