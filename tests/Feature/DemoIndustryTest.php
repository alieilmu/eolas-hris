<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\WorkLocation;
use App\Support\Demo\Industry;
use App\Support\Demo\IndustrySkin;
use Database\Seeders\EolasDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** System demo data renamed to each industry: no mining terms left, positions composed again. */
class DemoIndustryTest extends TestCase
{
    use RefreshDatabase;

    /** What a mining demo says that no other industry should. */
    private const MINING = ['Murung Raya', 'Rantau', 'Puruk Cahu', 'OPERASI TAMBANG', 'Dump Truck', 'HD785', 'Nonom', 'roster 10:2', 'Alat Berat'];

    /** Every place the demo shows a name. */
    private function visibleText(): string
    {
        $text = [];
        foreach ([
            'work_locations' => ['name'], 'points_of_hire' => ['name'], 'divisions' => ['name'], 'departments' => ['name'], 'sections' => ['name'],
            'job_levels' => ['name', 'title_prefix'], 'positions' => ['name', 'discipline'], 'grades' => ['grade_group'], 'employee_categories' => ['name'],
            'job_postings' => ['title', 'position_title', 'description', 'location'], 'job_posting_fields' => ['label', 'options'], 'applicants' => ['data'],
            'candidates' => ['position'], 'candidate_notes' => ['content'], 'leave_travel_requests' => ['origin_city', 'description'],
            'employee_profiles' => ['residential_city', 'residential_province'], 'attendance_devices' => ['name'],
            'medical_benefit_limits' => ['position_label_site'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $text[] = DB::table($table)->whereNotNull($column)->pluck($column)->implode(' | ');
            }
        }

        return implode("\n", $text);
    }

    public function test_every_industry_renames_the_demo_data(): void
    {
        $this->seed(EolasDemoSeeder::class);
        $mining = $this->visibleText();
        $this->assertStringContainsString('Murung Raya', $mining);
        $positions = Position::count();

        foreach (array_keys(Industry::LABELS) as $industry) {
            if ($industry === Industry::DEFAULT) {
                continue;
            }
            DB::beginTransaction();
            IndustrySkin::apply($industry);
            $text = $this->visibleText();
            // A construction company keeps its heavy-equipment mechanics.
            foreach (array_diff(self::MINING, $industry === 'construction' ? ['Alat Berat'] : []) as $term) {
                $this->assertStringNotContainsString($term, $text, "{$industry}: \"{$term}\" left");
            }
            $profile = Industry::profile($industry);
            $this->assertSame(array_column($profile['locations'], 0), WorkLocation::orderBy('sort_order')->pluck('name')->all(), $industry);
            $this->assertSame($positions, Position::count(), "{$industry}: positions kept");
            $this->assertTrue(Position::whereNull('name')->doesntExist());
            DB::rollBack();
        }
    }

    public function test_the_hospital_reads_like_a_hospital(): void
    {
        $this->seed(EolasDemoSeeder::class);
        IndustrySkin::apply('hospital');

        $this->assertSame(['Kantor Manajemen Grup', 'RS Sehat Bandung', 'RS Sehat Cirebon'], WorkLocation::orderBy('sort_order')->pluck('name')->all());
        $this->assertContains('PERAWAT PELAKSANA', Position::pluck('name')->all());
        $this->assertContains('KEPALA LABORATORIUM', Position::pluck('name')->all()); // was SECTION HEAD WORKSHOP
        $this->assertSame(0.54, WorkLocation::where('code', 'BDG')->value('jkk_rate'));
        $this->assertSame('six_day', WorkLocation::where('code', 'BDG')->value('work_schedule_scheme'));
        $this->assertSame(['Rawat Inap', 'IGD', 'ICU', 'Kamar Operasi'], json_decode(DB::table('job_posting_fields')->where('label', 'Unit Pengalaman')->value('options'), true));
    }

    public function test_demo_certificates_follow_the_industry(): void
    {
        $this->seed(EolasDemoSeeder::class);
        $codes = fn () => \App\Models\EmployeeCertification::withoutGlobalScopes()->with('type')->get()->pluck('type.code')->unique()->values()->all();
        $this->assertContains('SIMPER', $codes());
        $this->assertNotContains('STR', $codes());
        $this->assertTrue(\App\Models\EmployeeCertification::withoutGlobalScopes()->get()->contains(fn ($c) => $c->status() === 'expired'));

        IndustrySkin::apply('hospital');
        \App\Support\Demo\DemoCertifications::seed('hospital');
        $this->assertContains('STR', $codes());
        $this->assertNotContains('SIMPER', $codes());
    }
}
