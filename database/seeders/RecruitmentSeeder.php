<?php

namespace Database\Seeders;

use App\Http\Controllers\Recruitment\FormController;
use App\Models\ApplicationForm;
use App\Models\RecruitmentStage;
use Illuminate\Database\Seeder;

/** Default Kanban stages and three vacancy forms — no candidates or applicants. */
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
        self::forms();
    }

    /** Vacancies of a mining contractor: the standard questions, plus what each role asks. */
    public static function forms(): void
    {
        $common = FormController::defaultFields();
        foreach ([
            ['Operator Alat Berat', 'Operator Dump Truck / Excavator', 'Site Murung Raya', true,
                'Dibutuhkan operator berpengalaman untuk unit HD785, PC2000 dan PC1250. Penempatan di site, roster 10:2.',
                [['label' => 'Unit yang Dikuasai', 'type' => 'select', 'required' => true, 'options' => ['Dump Truck (HD785 / HD465)', 'Excavator (PC2000 / PC1250)', 'Bulldozer (D375 / D155)', 'Grader']],
                    ['label' => 'Pengalaman Mengoperasikan (tahun)', 'type' => 'number', 'required' => true],
                    ['label' => 'SIMPER / SIO', 'type' => 'file', 'required' => false]]],
            ['Mekanik Alat Berat', 'Mekanik Heavy Equipment', 'Site Rantau', true,
                'Perawatan dan perbaikan unit Komatsu & CAT di workshop site. Diutamakan lulusan SMK/D3 Teknik Alat Berat.',
                [['label' => 'Spesialisasi', 'type' => 'select', 'required' => true, 'options' => ['Engine', 'Powertrain', 'Hydraulic', 'Electrical']],
                    ['label' => 'Pengalaman Kerja Terakhir', 'type' => 'textarea', 'required' => false]]],
            ['Staff HRGA', 'Staff HRGA', 'Head Office', false,
                'Administrasi personalia, payroll dan general affair di kantor pusat Jakarta.',
                [['label' => 'Portofolio / LinkedIn', 'type' => 'url', 'kunci' => 'portofolio', 'required' => false]]],
        ] as [$title, $posisi, $lokasi, $open, $description, $extra]) {
            $form = ApplicationForm::create(['title' => $title, 'description' => $description, 'posisi' => $posisi, 'lokasi' => $lokasi, 'is_open' => $open]);
            $cv = array_pop($common);
            $fields = [...$common, ...array_map(fn ($f) => $f + ['kunci' => null], $extra), $cv];
            $common[] = $cv;
            $form->fields()->createMany(array_map(fn (array $f, int $i) => [...$f, 'order' => $i], $fields, array_keys($fields)));
        }
    }
}
