<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 4:
 *  - workflow_steps.due_days: working days a step may wait before its approvers are reminded,
 *    then (a working day later) the step is passed up to their superiors; null = no limit;
 *  - workflow_instances.step_started_at / reminded_at / escalated_at: that clock, per step;
 *    employee_id nullable: a job offer is for a candidate, not yet an employee;
 *  - workflow_actions.on_behalf_of: decided by a delegate for this approver;
 *  - approval_delegations: an approver's substitute, for a range of dates or whenever the
 *    approver is on approved leave;
 *  - job_offers: the offer to a candidate (position, salary, start date) that goes through the
 *    "job-offer" Workflow before the candidate is hired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $t) {
            if (! Schema::hasColumn('workflow_steps', 'due_days')) {
                $t->unsignedTinyInteger('due_days')->nullable()->after('mode');
            }
        });

        Schema::table('workflow_instances', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->change();
            if (! Schema::hasColumn('workflow_instances', 'step_started_at')) {
                $t->timestamp('step_started_at')->nullable()->after('current_approvers');
                $t->timestamp('reminded_at')->nullable()->after('step_started_at');
                $t->timestamp('escalated_at')->nullable()->after('reminded_at');
            }
        });

        Schema::table('workflow_actions', function (Blueprint $t) {
            if (! Schema::hasColumn('workflow_actions', 'on_behalf_of')) {
                $t->foreignId('on_behalf_of')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('approval_delegations')) {
            Schema::create('approval_delegations', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
                $t->string('mode', 20); // range | on_leave
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('notes', 255)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->index(['delegate_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('job_offers')) {
            Schema::create('job_offers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('candidate_id')->constrained()->cascadeOnDelete();
                $t->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
                $t->unsignedBigInteger('salary')->nullable();
                $t->date('start_date')->nullable();
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('pending');
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('decided_at')->nullable();
                $t->string('decision_notes', 500)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
        Schema::dropIfExists('approval_delegations');
        Schema::table('workflow_actions', fn (Blueprint $t) => $t->dropConstrainedForeignId('on_behalf_of'));
        Schema::table('workflow_instances', fn (Blueprint $t) => $t->dropColumn(['step_started_at', 'reminded_at', 'escalated_at']));
        Schema::table('workflow_steps', fn (Blueprint $t) => $t->dropColumn('due_days'));
    }
};
