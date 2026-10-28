<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 2, Workflow:
 *  - workflow_steps.mode: "any" (one approver is enough) or "all" (every approver of the step);
 *  - workflow_instances.facts: what the step conditions look at (amount, days, location,
 *    category, job level), fixed when the pengajuan starts;
 *  - workflow_instances.summary / url: one line about the pengajuan and its page, for
 *    Persetujuan Saya and the notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $t) {
            if (! Schema::hasColumn('workflow_steps', 'mode')) {
                $t->string('mode', 10)->default('any');
            }
        });
        Schema::table('workflow_instances', function (Blueprint $t) {
            if (! Schema::hasColumn('workflow_instances', 'facts')) {
                $t->json('facts')->nullable();
                $t->string('summary', 255)->nullable();
                $t->string('url', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', fn (Blueprint $t) => $t->dropColumn(['facts', 'summary', 'url']));
        Schema::table('workflow_steps', fn (Blueprint $t) => $t->dropColumn('mode'));
    }
};
