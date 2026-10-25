<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace set up for a company's own data:
 *  - employee_number_formats: how the company's Nomor Induk (NRP) is built (one row);
 *    the default is the J LL YY NNN pattern every workspace had so far;
 *  - workspace_setups: progress of the first-run setup (sesi awal) of a blank demo workspace;
 *  - trial_accounts.industry: which industry the system demo data is made for.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every step checks what is there, so a run stopped halfway can simply run again.
        if (! Schema::hasTable('employee_number_formats')) {
            Schema::create('employee_number_formats', function (Blueprint $t) {
                $t->id();
                $t->string('mode', 10)->default('pattern'); // pattern | free
                $t->json('segments');
                $t->unsignedTinyInteger('max_length')->default(20); // free mode
                $t->timestamps();
            });
            DB::table('employee_number_formats')->insert([
                'mode' => 'pattern',
                'segments' => json_encode([
                    ['type' => 'category', 'length' => 1],
                    ['type' => 'point_of_hire', 'length' => 2],
                    ['type' => 'year', 'length' => 2],
                    ['type' => 'sequence', 'length' => 3],
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Codes may be letters and longer than one or two characters now (e.g. "TY").
        Schema::table('employee_categories', fn (Blueprint $t) => $t->string('code', 4)->change());
        Schema::table('points_of_hire', fn (Blueprint $t) => $t->string('code', 4)->change());

        if (! Schema::hasTable('workspace_setups')) {
            Schema::create('workspace_setups', function (Blueprint $t) {
                $t->id();
                $t->string('status', 12)->default('pending'); // pending | completed | dismissed
                $t->json('steps')->nullable(); // step => done | skipped
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
            });
        }

        // Demo accounts live in the main application's database only, not in a workspace's.
        if (Schema::hasTable('trial_accounts') && ! Schema::hasColumn('trial_accounts', 'industry')) {
            Schema::table('trial_accounts', function (Blueprint $t) {
                $t->string('industry', 20)->nullable()->after('data_source');
            });
            DB::table('trial_accounts')->where('data_source', 'demo')->update(['industry' => 'mining']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trial_accounts', 'industry')) {
            Schema::table('trial_accounts', fn (Blueprint $t) => $t->dropColumn('industry'));
        }
        Schema::dropIfExists('workspace_setups');
        Schema::table('employee_categories', fn (Blueprint $t) => $t->char('code', 1)->change());
        Schema::table('points_of_hire', fn (Blueprint $t) => $t->char('code', 2)->change());
        Schema::dropIfExists('employee_number_formats');
    }
};
