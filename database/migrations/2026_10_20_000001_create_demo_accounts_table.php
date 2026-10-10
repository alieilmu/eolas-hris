<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo accounts (Pengajuan Akun Demo): each one is a workspace with its own database
 * (hris_eloas_t{id}) and files. Kept in the main database only; a workspace skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->central()) {
            return;
        }
        Schema::create('demo_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 16)->unique();
            $table->string('nama');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('perusahaan');
            $table->string('jabatan')->nullable();
            $table->string('no_hp', 30)->nullable();
            $table->string('jumlah_karyawan', 20)->nullable();
            $table->text('kebutuhan')->nullable();
            // demo: the system's demo data; kosong: an empty workspace for the user's own data.
            $table->string('sumber_data', 10)->default('demo');
            // menunggu → menyiapkan → aktif; nonaktif (switched off), gagal (provisioning failed).
            $table->string('status', 12)->default('menunggu');
            $table->string('database', 64)->nullable()->unique();
            $table->text('catatan')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if ($this->central()) {
            Schema::dropIfExists('demo_accounts');
        }
    }

    private function central(): bool
    {
        return Schema::getConnection()->getDatabaseName() === config('database.connections.central.database');
    }
};
