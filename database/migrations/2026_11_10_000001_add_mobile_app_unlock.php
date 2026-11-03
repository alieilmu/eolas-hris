<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 6 (PWA): how long a sign-in in the installed app lasts (Settings → White Label),
 * and how it is opened again — a PIN, or the phone's fingerprint / face (a WebAuthn passkey).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brandings', function (Blueprint $table) {
            // Days a sign-in in the installed app stays; 0 = as in the browser.
            $table->unsignedSmallInteger('mobile_session_days')->default(30)->after('favicon_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('app_pin')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::create('user_passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 255)->unique();
            $table->text('public_key');
            $table->integer('algorithm');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_passkeys');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('app_pin'));
        Schema::table('brandings', fn (Blueprint $table) => $table->dropColumn('mobile_session_days'));
    }
};
