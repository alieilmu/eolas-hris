<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /** White label (one row): the names, logos and colours the app shows. Empty = the built-in default. */
    public function up(): void
    {
        Schema::create('brandings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name', 60)->nullable();
            $table->string('company_name', 120)->nullable();
            $table->string('tagline', 120)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            // Files on the private 'local' disk, served by /branding/{jenis}.
            $table->string('logo_path')->nullable();
            $table->string('logo_full_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->timestamps();
        });

        // This installation was set up for PT Riung Mitra Lestari: start from its brand, so nothing
        // changes on screen. Another company changes it all in Settings → White Label.
        $files = [];
        foreach (['logo_path' => 'images/riung-logo.webp', 'logo_full_path' => 'images/riung-logo-full.webp', 'favicon_path' => 'favicon.svg'] as $column => $source) {
            if (is_file(public_path($source))) {
                $files[$column] = 'branding/'.basename($source);
                Storage::disk('local')->put($files[$column], file_get_contents(public_path($source)));
            }
        }
        DB::table('brandings')->insert([
            'app_name' => 'HR Puspita',
            'company_name' => 'PT Riung Mitra Lestari',
            'tagline' => 'Mining Contractor',
            'brand_color' => '#586c9a',
            'accent_color' => '#fcd100',
            ...$files,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('brandings');
    }
};
