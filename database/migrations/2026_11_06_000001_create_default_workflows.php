<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 week 3: cuti, dinas and lembur move to Workflow. Each gets its default flow now, the
 * way they were approved before (one step: HR with "ubah" on the menu and the employee's
 * location), so nothing changes for a company until HR changes the flow. Its creation time
 * marks the move: overtime recorded before it still finishes the old way.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'leave' => ['Alur Cuti', 'travel-requests'],
        'business_trip' => ['Alur Perjalanan Dinas', 'travel-requests'],
        'overtime' => ['Alur Lembur', 'overtime-approval'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $type => [$name, $menu]) {
            if (DB::table('workflow_definitions')->where('type', $type)->exists()) {
                continue;
            }
            $id = DB::table('workflow_definitions')->insertGetId(['type' => $type, 'name' => $name, 'version' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('workflow_steps')->insert(['workflow_definition_id' => $id, 'sequence' => 1, 'name' => 'Verifikasi HR', 'approver_type' => 'menu',
                'approver_value' => $menu, 'mode' => 'any', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // The flows stay: pengajuan point at them.
    }
};
