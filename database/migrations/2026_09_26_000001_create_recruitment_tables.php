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
        Schema::create('recruitment_stages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status');
            $table->string('color')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_stage_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('position');
            $table->string('portfolio_url')->nullable();
            $table->string('cv_url')->nullable();
            $table->string('status');
            $table->date('applied_date');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('candidate_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('author');
            $table->text('content');
            $table->timestamps();
        });

        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('from');
            $table->string('to');
            $table->string('changed_by');
            $table->timestamps();
        });

        Schema::create('application_forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_open')->default(false);
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_form_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('type'); // text | email | url | select | file | ...
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->string('placeholder')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_form_id')->constrained()->cascadeOnDelete();
            $table->json('data'); // answers keyed by field label
            $table->boolean('is_processed')->default(false);
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('application_forms');
        Schema::dropIfExists('status_histories');
        Schema::dropIfExists('candidate_notes');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('recruitment_stages');
    }
};
