<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 9: notifications pushed to the phone (Web Push). Each phone / browser that
 * allowed them gives an address at its push service and two keys to encrypt for it; an
 * account chooses which kinds reach the phone (the bell keeps them all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // sha256 of the endpoint: one row per phone / browser.
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 120);
            $table->string('auth_token', 40);
            $table->string('name', 120)->nullable();
            $table->timestamp('last_used_at')->nullable();
            // Sends that failed in a row; removed when the push service says it is gone.
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Kind => false for the kinds not pushed to the phone (none: all of them).
            $table->json('push_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('push_preferences'));
    }
};
