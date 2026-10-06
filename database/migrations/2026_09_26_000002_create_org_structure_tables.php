<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('divisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bod_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('departemens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('divisi_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('layer_jabatans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('level'); // 1 = top; levels <= 4 hold one active employee per jabatan
            $table->timestamps();
        });

        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        // Employee subgroup (like SAP's): layer 1–6, the higher the more senior.
        Schema::create('sub_groups', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->unique();
            $table->unsignedTinyInteger('layer');
            $table->timestamps();
        });

        // A position is not tied to a place: the work location belongs to each employee.
        Schema::create('jabatans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_jabatan_id')->constrained()->restrictOnDelete();
            $table->foreignId('sub_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('departemen_id')->constrained()->restrictOnDelete();
            // Kept for the organisation chart; not edited in Master Data.
            $table->foreignId('parent_jabatan_id')->nullable()->constrained('jabatans')->nullOnDelete();
            $table->string('name');
            $table->string('kode')->nullable()->unique();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jabatans');
        Schema::dropIfExists('sub_groups');
        Schema::dropIfExists('work_locations');
        Schema::dropIfExists('layer_jabatans');
        Schema::dropIfExists('departemens');
        Schema::dropIfExists('divisis');
        Schema::dropIfExists('bods');
    }
};
