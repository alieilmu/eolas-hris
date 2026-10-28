<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 1, the Workflow engine (App\Support\Workflow\Workflow):
 *  - workflow_definitions: the approval flow of one kind of pengajuan, versioned (a running
 *    pengajuan keeps the version it started on);
 *  - workflow_steps: its steps in order, each naming who approves (approver_type/value) and,
 *    from week 2, when the step applies (conditions);
 *  - workflow_instances: one pengajuan going through a flow: the step it is at, who may decide
 *    it now, and how it ended;
 *  - workflow_actions: every decision on it (who, what, note, when).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workflow_definitions')) {
            Schema::create('workflow_definitions', function (Blueprint $t) {
                $t->id();
                $t->string('type', 40);
                $t->string('name', 120);
                $t->unsignedSmallInteger('version')->default(1);
                $t->boolean('is_active')->default(true);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['type', 'version']);
            });
        }

        if (! Schema::hasTable('workflow_steps')) {
            Schema::create('workflow_steps', function (Blueprint $t) {
                $t->id();
                $t->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
                $t->unsignedTinyInteger('sequence');
                $t->string('name', 120);
                $t->string('approver_type', 20);
                $t->string('approver_value', 60)->nullable();
                $t->json('conditions')->nullable();
                $t->timestamps();
                $t->unique(['workflow_definition_id', 'sequence']);
            });
        }

        if (! Schema::hasTable('workflow_instances')) {
            Schema::create('workflow_instances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('workflow_definition_id')->constrained()->restrictOnDelete();
                $t->string('subject_type', 120);
                $t->unsignedBigInteger('subject_id');
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->unsignedTinyInteger('current_step')->nullable();
                $t->json('current_approvers')->nullable();
                $t->string('status', 20)->default('pending');
                $t->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->index(['subject_type', 'subject_id']);
                $t->index('status');
            });
        }

        if (! Schema::hasTable('workflow_actions')) {
            Schema::create('workflow_actions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('workflow_instance_id')->constrained()->cascadeOnDelete();
                $t->unsignedTinyInteger('step')->nullable();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('user_name', 120)->nullable();
                $t->string('decision', 20);
                $t->text('notes')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_definitions');
    }
};
