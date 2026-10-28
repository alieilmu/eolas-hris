<?php

namespace Database\Seeders;

use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Candidate;
use App\Models\Position;
use App\Models\Employee;
use App\Models\RecruitmentStage;
use App\Models\CandidateStageHistory;
use App\Models\User;
use App\Support\Notifications\RequestNotifier;
use App\Support\Organization\PositionComposer;
use App\Support\Recruitment\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recruitment for the demo: applications to the open vacancies (each with a CV), candidates
 * spread over the stages with notes and history, a few not taken further, one hired.
 * Runs inside DemoSeeder, or on its own on a demo database:
 *
 *     php artisan db:seed --class=DemoRecruitmentSeeder
 */
class DemoRecruitmentSeeder extends Seeder
{
    /** [nama, email, HP, lahir, pendidikan, form (title), answer to its own question, days ago] */
    private const PELAMAR = [
        ['Rizal Fahrezi', 'rizal.fahrezi@example.com', '081351110021', '1997-02-14', 'SMA/SMK', 'Operator Alat Berat', 'Dump Truck (HD785 / HD465)', 58],
        ['Agung Wicaksono', 'agung.wicak@example.com', '081258890012', '1995-09-03', 'SMA/SMK', 'Operator Alat Berat', 'Excavator (PC2000 / PC1250)', 55],
        ['Hendro Pakpahan', 'hendro.pakpahan@example.com', '082160034411', '1993-11-21', 'SMA/SMK', 'Operator Alat Berat', 'Bulldozer (D375 / D155)', 52],
        ['Yusuf Maulana', 'yusuf.maulana@example.com', '085248871200', '1999-04-09', 'SMA/SMK', 'Operator Alat Berat', 'Dump Truck (HD785 / HD465)', 47],
        ['Dimas Prayoga', 'dimas.prayoga@example.com', '081349902317', '1996-07-30', 'D3', 'Mekanik Alat Berat', 'Hydraulic', 44],
        ['Bayu Firmansyah', 'bayu.firmansyah@example.com', '082255670098', '1994-01-17', 'SMA/SMK', 'Mekanik Alat Berat', 'Engine', 40],
        ['Arif Rahman Hakim', 'arif.rahman@example.com', '081250013388', '1992-12-02', 'D3', 'Mekanik Alat Berat', 'Electrical', 36],
        ['Fikri Haikal', 'fikri.haikal@example.com', '085654120987', '1998-03-26', 'SMA/SMK', 'Operator Alat Berat', 'Excavator (PC2000 / PC1250)', 33],
        ['Ilham Saputra', 'ilham.saputra@example.com', '081322457788', '2000-08-11', 'SMA/SMK', 'Operator Alat Berat', 'Grader', 29],
        ['Rudy Kurniawan', 'rudy.kurniawan@example.com', '081177760032', '1991-05-05', 'SMA/SMK', 'Mekanik Alat Berat', 'Powertrain', 25],
        ['Teguh Santoso', 'teguh.santoso@example.com', '082149906655', '1997-10-19', 'SMA/SMK', 'Operator Alat Berat', 'Dump Truck (HD785 / HD465)', 21],
        ['Wawan Setiadi', 'wawan.setiadi@example.com', '081255503214', '1996-06-27', 'D3', 'Mekanik Alat Berat', 'Hydraulic', 17],
        ['Nico Sembiring', 'nico.sembiring@example.com', '081370012299', '1995-02-08', 'SMA/SMK', 'Operator Alat Berat', 'Excavator (PC2000 / PC1250)', 12],
        ['Galih Pratama', 'galih.pratama@example.com', '085751239876', '1999-12-12', 'SMA/SMK', 'Operator Alat Berat', 'Dump Truck (HD785 / HD465)', 6],
        ['Rahmat Hidayat', 'rahmat.hidayat@example.com', '081254443210', '1994-09-15', 'D3', 'Mekanik Alat Berat', 'Engine', 4],
        ['Sinta Maharani', 'sinta.maharani@example.com', '081298764120', '2001-01-30', 'SMA/SMK', 'Operator Alat Berat', 'Dump Truck (HD785 / HD465)', 2],
        ['Andre Lumbantobing', 'andre.lumban@example.com', '082277651234', '1993-04-22', 'SMA/SMK', 'Mekanik Alat Berat', 'Powertrain', 1],
        ['Fajar Ramadhan', 'fajar.ramadhan@example.com', '081344556677', '1998-11-04', 'SMA/SMK', 'Operator Alat Berat', 'Bulldozer (D375 / D155)', 0],
    ];

    /** Where each application ended up: stage index (0 = first), "baru", "tolak" (at Pelamar) or [stage, reason] (not taken further). */
    private const NASIB = [
        'Rizal Fahrezi' => 10, 'Agung Wicaksono' => 'hired', 'Hendro Pakpahan' => [3, 'Tidak lolos interview manager'], 'Yusuf Maulana' => 7,
        'Dimas Prayoga' => 6, 'Bayu Firmansyah' => 5, 'Arif Rahman Hakim' => [1, 'Nilai psikotes di bawah standar'], 'Fikri Haikal' => 4,
        'Ilham Saputra' => 'reject', 'Rudy Kurniawan' => 3, 'Teguh Santoso' => 2, 'Wawan Setiadi' => 2, 'Nico Sembiring' => 1,
        'Galih Pratama' => 0, 'Rahmat Hidayat' => 'new', 'Sinta Maharani' => 'new', 'Andre Lumbantobing' => 'new', 'Fajar Ramadhan' => 'new',
    ];

    private const CATATAN = [
        'Psikotes' => 'Psikotes: nilai B, konsentrasi baik.',
        'Interview HC' => 'Komunikasi lancar, bersedia penempatan site dan roster 10:2.',
        'Interview MGR Func' => 'Paham prosedur P2H dan safety. Direkomendasikan lanjut.',
        'Medical Check Up' => 'MCU fit to work.',
        'Onsite' => 'Uji praktik unit di site: lulus.',
    ];

    public function run(): void
    {
        $today = CarbonImmutable::today();
        $stages = RecruitmentStage::orderBy('sort_order')->get()->values();
        $forms = JobPosting::with('fields')->get()->keyBy('title');
        $hr = User::where('role', User::ROLE_HR)->first() ?? User::where('role', User::ROLE_ADMIN)->first();
        if ($stages->isEmpty() || ! isset($forms['Operator Alat Berat'], $forms['Mekanik Alat Berat'])) {
            return; // not the seeded recruitment set-up
        }

        foreach (self::PELAMAR as [$name, $email, $hp, $birth, $education_level, $formTitle, $khusus, $hariLalu]) {
            $form = $forms[$formTitle];
            $submitted = $today->subDays($hariLalu)->setTime(8 + $hariLalu % 10, (7 * $hariLalu) % 60);
            $applicant = Applicant::create(['job_posting_id' => $form->id, 'data' => [], 'status' => 'new', 'submitted_at' => $submitted]);
            $applicant->forceFill(['created_at' => $submitted, 'updated_at' => $submitted])->saveQuietly();
            $data = [];
            foreach ($form->fields as $f) {
                $value = match (true) {
                    $f->field_key === 'name' => $name,
                    $f->field_key === 'email' => $email,
                    $f->field_key === 'phone' => $hp,
                    $f->field_key === 'cv' => $this->cv($applicant->id, $name, $email, $hp, $birth, $education_level, $formTitle, $khusus),
                    $f->label === 'Tanggal Lahir' => $birth,
                    $f->label === 'Pendidikan Terakhir' => $education_level,
                    $f->label === 'Domisili' => ['Puruk Cahu', 'Muara Teweh', 'Banjarmasin', 'Rantau', 'Palangka Raya'][strlen($name) % 5],
                    $f->type === 'select' => $khusus,
                    $f->type === 'number' => (string) (2 + strlen($name) % 6),
                    $f->label === 'Pengalaman Kerja Terakhir' => 'Mekanik di kontraktor tambang batubara, '.(2 + strlen($name) % 4).' tahun.',
                    default => null,
                };
                $data[(string) $f->id] = ['label' => $f->label, 'type' => $f->type, 'field_key' => $f->field_key, 'value' => $value];
            }
            $applicant->forceFill(['data' => $data])->saveQuietly();

            $nasib = self::NASIB[$name] ?? 'new';
            if ($nasib === 'new') {
                RequestNotifier::applicants($applicant);

                continue;
            }
            if ($nasib === 'reject') {
                $applicant->forceFill(['status' => 'rejected', 'notes' => 'Usia & pengalaman belum sesuai kualifikasi'])->saveQuietly();

                continue;
            }
            $this->candidate($applicant->fresh(), $nasib, $stages, $submitted, $hr, $today);
        }
    }

    private function candidate(Applicant $a, int|string|array $nasib, $stages, CarbonImmutable $submitted, ?User $hr, CarbonImmutable $today): void
    {
        $hired = $nasib === 'hired';
        $index = $hired ? $stages->count() - 1 : (is_array($nasib) ? $nasib[0] : $nasib);
        $stage = $stages[$index];
        $cv = Application::file($a);
        $c = Candidate::create([
            'recruitment_stage_id' => $stage->id,
            'applicant_id' => $a->id,
            'name' => $a->name(),
            'email' => (string) $a->answers('email'),
            'phone' => $a->answers('phone'),
            'position' => $a->form->position_title ?? $a->form->title,
            'cv_path' => $cv['path'] ?? null,
            'cv_filename' => $cv['name'] ?? null,
            'status' => $stage->title,
            'applied_date' => $submitted->toDateString(),
            'sort_order' => Candidate::where('recruitment_stage_id', $stage->id)->count(),
            'rejected_at' => is_array($nasib) ? $today->subDays(2) : null,
            'rejection_reason' => is_array($nasib) ? $nasib[1] : null,
        ]);
        $a->forceFill(['status' => 'in_process', 'is_processed' => true])->saveQuietly();

        // One step every few days from the application to where it is now.
        $by = $hr?->name ?? 'HR';
        $span = max(1, (int) $submitted->diffInDays($today) - 1);
        $from = 'Pelamar';
        for ($i = 0; $i <= $index; $i++) {
            $at = $submitted->addDays((int) round($span * ($i + 1) / ($index + 2)))->setTime(10, 15);
            CandidateStageHistory::create(['candidate_id' => $c->id, 'from' => $from, 'to' => $stages[$i]->title, 'changed_by' => $by])->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            if (isset(self::CATATAN[$stages[$i]->title]) && $i < $index) {
                $c->notes()->create(['author' => $by, 'content' => self::CATATAN[$stages[$i]->title]])->forceFill(['created_at' => $at->addHours(3)])->saveQuietly();
            }
            $from = $stages[$i]->title;
        }
        if (is_array($nasib)) {
            CandidateStageHistory::create(['candidate_id' => $c->id, 'from' => $stage->title, 'to' => 'Tidak dilanjutkan', 'changed_by' => $by]);
        }

        if ($hired) {
            $position = Position::whereHas('jobLevel', fn ($q) => $q->where('name', 'Operator'))->first();
            $prefix = '115'.$today->format('y');
            $next = (int) Employee::withoutGlobalScopes()->where('employee_number', 'like', "{$prefix}%")->max('employee_number');
            $employee_number = $next ? (string) ($next + 1) : "{$prefix}101";
            $k = Employee::create([
                'employee_number' => $employee_number, 'name' => $c->name, 'email' => null, 'status' => Employee::STATUS_ACTIVE, 'candidate_id' => $c->id,
                ...($position ? [...PositionComposer::employeePlacementOf($position), 'position_id' => $position->id] : []),
            ]);
            $k->forceFill(['created_at' => $today->subDays(5), 'updated_at' => $today->subDays(5)])->saveQuietly();
            $k->profile()->create(['hire_date' => $today->subDays(5)->toDateString(), 'employment_status' => 'PKWT', 'phone' => $c->phone, 'whatsapp_number' => $c->phone, 'personal_email' => $c->email,
                'birth_date' => Application::birthDate($a)]);
            CandidateStageHistory::create(['candidate_id' => $c->id, 'from' => $stage->title, 'to' => "Menjadi karyawan (NRP {$employee_number})", 'changed_by' => $by]);
        }
    }

    /** A one-page PDF CV (text only), saved where an uploaded one would be. */
    private function cv(int $applicantId, string $name, string $email, string $hp, string $birth, string $education_level, string $careers, string $khusus): array
    {
        $lines = [
            ['CURRICULUM VITAE', 18], ['', 12], [$name, 15], ["{$email}  |  {$hp}", 11], ['', 12],
            ['DATA PRIBADI', 12], ['Tanggal lahir   : '.CarbonImmutable::parse($birth)->format('d.m.Y'), 11], ["Pendidikan      : {$education_level}", 11], ['', 12],
            ['LOWONGAN DILAMAR', 12], [$careers, 11], ["Keahlian utama  : {$khusus}", 11], ['', 12],
            ['PENGALAMAN', 12], ['- Kontraktor tambang batubara, Kalimantan', 11], ['- Memahami P2H, safety talk dan pelaporan unit', 11], ['', 12],
            ['Dokumen contoh - data demo', 9],
        ];
        $text = 'BT 56 780 Td';
        foreach ($lines as [$line, $size]) {
            $text .= " /F1 {$size} Tf (".str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).') Tj 0 -'.($size + 7).' Td';
        }
        $text .= ' ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        $path = "recruitment/applicants/{$applicantId}/".Str::uuid().'.pdf';
        Storage::disk(Application::DISK)->put($path, $pdf);

        return ['path' => $path, 'name' => 'CV-'.Str::slug($name).'.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf)];
    }
}
