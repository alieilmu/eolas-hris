<?php

namespace Database\Seeders;

use App\Models\ApplicationForm;
use App\Models\RecruitmentStage;
use Illuminate\Database\Seeder;

/** Default Kanban stages and two application forms — no candidates or applicants. */
class RecruitmentSeeder extends Seeder
{
    private const STAGES = [
        ['title' => 'Filter Candidat', 'color' => '#64748b'],
        ['title' => 'Psikotes', 'color' => '#f43f5e'],
        ['title' => 'Interview HC', 'color' => '#f59e0b'],
        ['title' => 'Interview MGR Func', 'color' => '#facc15'],
        ['title' => 'Interview GM Func', 'color' => '#84cc16'],
        ['title' => 'Interview BOD', 'color' => '#10b981'],
        ['title' => 'Medical Check Up', 'color' => '#0ea5e9'],
        ['title' => 'Pembekalan Func', 'color' => '#3b82f6'],
        ['title' => 'Onsite', 'color' => '#f87171'],
        ['title' => 'Offering Letter', 'color' => '#22c55e'],
        ['title' => 'Sign Contract', 'color' => '#475569'],
    ];

    public function run(): void
    {
        foreach (self::STAGES as $order => $stage) {
            RecruitmentStage::create([
                'title' => $stage['title'],
                'status' => $stage['title'],
                'color' => $stage['color'],
                'order' => $order,
            ]);
        }

        $tech = ApplicationForm::create([
            'title' => 'Lamaran Technical Roles',
            'description' => 'Backend, Frontend, and Fullstack positions',
            'is_open' => true,
        ]);
        $tech->fields()->createMany([
            ['label' => 'Nama Lengkap', 'type' => 'text', 'required' => true, 'order' => 0],
            ['label' => 'Email', 'type' => 'email', 'required' => true, 'order' => 1],
            ['label' => 'Posisi', 'type' => 'select', 'required' => true, 'order' => 2, 'options' => ['Frontend', 'Backend', 'Fullstack']],
            ['label' => 'Portofolio', 'type' => 'url', 'required' => false, 'order' => 3],
            ['label' => 'CV (PDF)', 'type' => 'file', 'required' => true, 'order' => 4],
        ]);

        $creative = ApplicationForm::create([
            'title' => 'Lamaran Creative Roles',
            'description' => 'UI/UX, Graphic Design, and Content Creator',
            'is_open' => false,
        ]);
        $creative->fields()->createMany([
            ['label' => 'Nama Lengkap', 'type' => 'text', 'required' => true, 'order' => 0],
            ['label' => 'Email', 'type' => 'email', 'required' => true, 'order' => 1],
            ['label' => 'Portfolio Link', 'type' => 'url', 'required' => true, 'order' => 2],
        ]);
    }
}
