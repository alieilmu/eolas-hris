<?php

namespace Database\Seeders;

use App\Http\Controllers\Compensation\BenefitClaimController;
use App\Http\Controllers\Settings\UserController;
use App\Models\LongServiceLeave;
use App\Models\FieldLeaveEntitlement;
use App\Models\BenefitClaim;
use App\Models\PublicHoliday;
use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Time\LeaveTravelWriter;
use App\Support\Compensation\AccountingMonth;
use App\Support\Compensation\BenefitClaimCheck;
use App\Support\Leave\LeaveLedger;
use App\Support\Notifications\RequestNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A full demo: the base seeders, 25 more employees, and a few months of activity relative
 * to today — roster and absensi, dinas and cuti (with Cuti Lapangan and Cuti Besar), the leave
 * history, deklarasi with their nota, and pengajuan from the portal still waiting for HR
 * (which also fill the admins' notifications).
 *
 *     php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Every name, number and document is fictional. Employees sign in to the portal with their
 * NRP and their birth date (ddmmyyyy); admin accounts come from UserSeeder (.env).
 */
class DemoSeeder extends Seeder
{
    private CarbonImmutable $today;

    private User $admin;

    /** @var array<string, Karyawan> */
    private array $people = [];

    /** @var array<string, int> */
    private array $codes = [];

    public function run(): void
    {
        $this->call([DatabaseSeeder::class, DemoEmployeeSeeder::class]);

        mt_srand(2026);
        $this->today = CarbonImmutable::today();
        $this->admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->codes = ShiftCode::pluck('id', 'code')->all();
        $this->people = Employee::with(['profile.children', 'position', 'jobLevel', 'department', 'section.department', 'workLocation'])
            ->where('status', Employee::STATUS_ACTIVE)->get()->keyBy('name')->all();
        // Files of an earlier demo / database are not ours any more.
        Storage::disk('local')->deleteDirectory('attachment');
        Storage::disk('local')->deleteDirectory('recruitment');

        $this->accounts();
        $this->masters();
        $this->scheduleAndAttendance();
        $this->leaveHistory();
        $this->longServiceLeave();
        $this->leaveTravelRequests();
        $this->claims();
        $this->updateData();
        $this->call(DemoRecruitmentSeeder::class);
        $this->call(DemoWorkforcePlanSeeder::class);
        $this->call(DemoFieldLeaveAllowanceSeeder::class);
    }

    /** Portal accounts for every active employee, ready to use (no forced password change in the demo). */
    private function accounts(): void
    {
        $now = now();
        $rows = array_map(fn (Employee $k) => [
            'name' => $k->name, 'email' => null, 'password' => UserController::initialPasswordHash($k), 'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $k->id, 'is_active' => true, 'must_change_password' => false, 'created_at' => $now, 'updated_at' => $now,
        ], array_values($this->people));
        User::insert($rows);
    }

    /** Field leave allowance per site, and the public holidays of the year. */
    private function masters(): void
    {
        foreach (WorkLocation::where('type', WorkLocation::TYPE_SITE)->get() as $site) {
            FieldLeaveEntitlement::create(['work_location_id' => $site->id, 'sub_location_id' => null, 'leave_days' => 14, 'outbound_travel_days' => 2, 'return_travel_days' => 2]);
        }
        $y = $this->today->year;
        foreach (["{$y}-01-01" => 'Tahun Baru Masehi', "{$y}-05-01" => 'Hari Buruh Internasional', "{$y}-06-01" => 'Hari Lahir Pancasila',
            "{$y}-08-17" => 'Hari Kemerdekaan RI', "{$y}-12-25" => 'Hari Raya Natal'] as $date => $nama) {
            PublicHoliday::create(['date' => $date, 'name' => $nama]);
        }
    }

    /**
     * Roster from two months back to the end of this month; Absensi up to yesterday, as the
     * roster with a few sick days, permits and absences. Head Office works Monday to Friday,
     * the sites 10 days on (day then night shift) and 2 off, each crew starting elsewhere.
     */
    private function scheduleAndAttendance(): void
    {
        $from = $this->today->subMonths(2)->startOfMonth();
        $to = $this->today->endOfMonth()->startOfDay();
        $public_holidays = PublicHoliday::pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();
        $now = now();
        $schedule = [];
        $attendance = [];
        foreach (array_values($this->people) as $i => $k) {
            $site = $k->workLocation?->type === WorkLocation::TYPE_SITE;
            $hired = $k->profile?->hire_date;
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                if ($hired && $d->lt($hired)) {
                    continue;
                }
                if ($site) {
                    $step = ((int) $from->diffInDays($d) + $i * 3) % 12;
                    $code = $step < 5 ? 'D' : ($step < 10 ? 'N' : 'OFF');
                } else {
                    $code = $d->isWeekend() || isset($public_holidays[$d->toDateString()]) ? 'OFF' : 'D';
                }
                $row = ['employee_id' => $k->id, 'date' => $d->toDateString(), 'created_at' => $now, 'updated_at' => $now];
                $schedule[] = $row + ['shift_code_id' => $this->codes[$code]];
                if ($d->lt($this->today)) {
                    $actual = $code !== 'OFF' ? match (true) {
                        ($r = mt_rand(1, 1000)) <= 18 => 'S',
                        $r <= 28 => 'I',
                        $r <= 33 => 'A',
                        default => $code,
                    } : 'OFF';
                    $attendance[] = $row + ['shift_code_id' => $this->codes[$actual], 'source' => $actual === $code ? 'schedule' : 'manual'];
                }
            }
        }
        foreach (array_chunk($schedule, 500) as $chunk) {
            DB::table('shift_schedules')->insert($chunk);
        }
        foreach (array_chunk($attendance, 500) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }
    }

    /** Annual leave taken before this app (as imported from the old Excel), the last three years. */
    private function leaveHistory(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->people as $k) {
            $hired = $k->profile?->hire_date;
            if (! $hired || $hired->gt($this->today->subYears(2))) {
                continue;
            }
            foreach ([$this->today->year - 2, $this->today->year - 1, $this->today->year] as $year) {
                $last = $year === $this->today->year ? 6 : 12; // this year: only before the trips below
                foreach (range(1, mt_rand(1, 3)) as $n) {
                    $initial = CarbonImmutable::create($year, mt_rand(1, $last), mt_rand(1, 20));
                    $days = mt_rand(2, 5);
                    $rows[] = [
                        'employee_id' => $k->id, 'type' => 'annual', 'year' => $year,
                        'start_date' => $initial->toDateString(), 'end_date' => $initial->addDays($days - 1)->toDateString(),
                        'days' => $days, 'source' => 'import', 'notes' => $n === 1 && $year < $this->today->year ? 'Riwayat dari Excel lama' : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }
        DB::table('leave_transactions')->insert($rows);
    }

    /** Choices for the Cuti Besar reached: cashed in, taken, and one sent from the portal. */
    private function longServiceLeave(): void
    {
        $decided = ['status' => 'approved', 'source' => 'hr', 'verified_by' => $this->admin->id, 'verified_at' => now()];
        // Hendra Wijaya: the first three cashed in, the 4th passed its deadline, the 5th (this year) taken.
        foreach ([1, 2, 3] as $n) {
            LongServiceLeave::create(['employee_id' => $this->k('Hendra Wijaya')->id, 'sequence_no' => $n, 'decision' => 'cashed_out'] + $decided);
        }
        $this->reached('Hendra Wijaya', 'taken', $decided);
        $this->reached('Dewi Lestari', 'cashed_out', $decided);
        // Eko Prasetyo chose from the portal; HR has not checked it yet.
        if ($choice = $this->reached('Eko Prasetyo', 'taken', ['status' => 'pending', 'source' => 'self_service'])) {
            RequestNotifier::longServiceLeave($choice);
        }
    }

    /** Records $keputusan for the employee's open Cuti Besar (reached, before its deadline). */
    private function reached(string $name, string $decision, array $attributes): ?LongServiceLeave
    {
        $k = $this->k($name);
        $open = collect(LeaveLedger::summary($k)['long_service']['period'])->firstWhere('status', 'not_chosen');

        return $open ? LongServiceLeave::create(['employee_id' => $k->id, 'sequence_no' => $open['sequence_no'], 'decision' => $decision] + $attributes) : null;
    }

    /** Dinas and cuti around today: done, running, coming, and waiting for HR. */
    private function leaveTravelRequests(): void
    {
        $t = $this->today;
        $day = fn (int $offset) => $t->addDays($offset)->toDateString();
        $hr = ['status' => 'approved', 'source' => 'hr'];
        $self_service = ['status' => 'pending', 'source' => 'self_service'];

        // Dinas
        $this->leaveTravel('Bambang Susilo', 'business_trip', $day(-51), $day(-48), 'Puruk Cahu', 'Jakarta', $hr + [
            'description' => 'Rapat evaluasi produksi kuartal III di Head Office',
            'needs_flight' => true, 'departure_airport' => 'BDJ', 'arrival_airport' => 'CGK', 'is_round_trip' => true,
            'return_date' => $day(-48), 'return_departure_airport' => 'CGK', 'return_arrival_airport' => 'BDJ', 'cash_advance_status' => 'Selesai',
            'needs_cash_advance' => true, 'cash_advance_amount' => 4500000,
        ]);
        $this->leaveTravel('Siti Rahmawati', 'business_trip', $day(-31), $day(-27), 'Jakarta', 'Puruk Cahu', $hr + [
            'description' => 'Sosialisasi peraturan perusahaan & audit administrasi personalia site',
            'needs_flight' => true, 'departure_airport' => 'CGK', 'arrival_airport' => 'BDJ', 'is_round_trip' => true,
            'return_date' => $day(-27), 'return_departure_airport' => 'BDJ', 'return_arrival_airport' => 'CGK',
            'needs_cash_advance' => true, 'cash_advance_amount' => 3500000, 'cash_advance_status' => 'Selesai',
        ]);
        $this->leaveTravel('Joko Santoso', 'business_trip', $day(-16), $day(-15), 'Rantau', 'Banjarmasin', $hr + ['description' => 'Pengambilan sparepart & koordinasi vendor']);
        $this->leaveTravel('Agus Setiawan', 'business_trip', $day(-2), $day(2), 'Rantau', 'Balikpapan', $hr + [
            'description' => 'Training overhaul engine di workshop principal',
            'needs_flight' => true, 'departure_airport' => 'BDJ', 'arrival_airport' => 'BPN', 'needs_cash_advance' => true, 'cash_advance_amount' => 2000000, 'cash_advance_status' => 'Dicairkan',
        ]);
        $this->leaveTravel('Dewi Lestari', 'business_trip', $day(11), $day(13), 'Jakarta', 'Surabaya', $hr + [
            'description' => 'Gambir → Surabaya Pasar Turi; audit pajak cabang', 'needs_train' => true,
            'needs_cash_advance' => true, 'cash_advance_amount' => 2500000, 'cash_advance_status' => 'Disetujui',
        ]);
        // Cuti
        $this->leaveTravel('Ahmad Fauzi', 'leave', $day(-59), $day(-42), 'Puruk Cahu', 'Banjarmasin', $hr + ['leave_category' => 'field']);
        $this->leaveTravel('Rendi Gunawan', 'leave', $day(-45), $day(-28), 'Rantau', 'Tanjung', $hr + ['leave_category' => 'field']);
        $this->leaveTravel('Hendra Wijaya', 'leave', $day(-66), $day(-47), 'Puruk Cahu', 'Samarinda', $hr + ['leave_category' => 'long_service', 'description' => 'Cuti Besar periode ke-5']);
        $this->leaveTravel('Lukman Hakim', 'leave', $day(-24), $day(-5), 'Puruk Cahu', 'Kandangan', $hr + ['leave_category' => 'field', 'description' => '2 hari tambahan dari cuti tahunan']);
        $this->leaveTravel('Winda Kartika', 'leave', $day(-10), $day(-8), 'Jakarta', 'Jakarta', $hr + ['leave_category' => 'annual', 'description' => 'Keperluan keluarga']);
        $this->leaveTravel('Eko Prasetyo', 'leave', $day(-7), $day(10), 'Puruk Cahu', 'Surabaya', $hr + ['leave_category' => 'field']);
        $this->leaveTravel('Yudi Hermawan', 'leave', $day(4), $day(6), 'Jakarta', 'Bandung', $hr + ['leave_category' => 'annual']);

        // Sent from the portal, waiting for HR.
        foreach ([
            ['Gilang Ramadhan', 'leave', 18, 35, 'Puruk Cahu', 'Palangka Raya', $self_service + ['leave_category' => 'field']],
            ['Mario Tambunan', 'leave', 25, 29, 'Rantau', 'Medan', $self_service + ['leave_category' => 'annual', 'description' => 'Pernikahan adik',
                'needs_flight' => true, 'departure_airport' => 'BDJ', 'arrival_airport' => 'KNO', 'is_round_trip' => true,
                'return_date' => $day(29), 'return_departure_airport' => 'KNO', 'return_arrival_airport' => 'BDJ']],
            ['Fajar Nugroho', 'business_trip', 19, 21, 'Jakarta', 'Surabaya', $self_service + ['description' => 'Rekonsiliasi piutang dengan customer',
                'needs_flight' => true, 'departure_airport' => 'CGK', 'arrival_airport' => 'SUB', 'is_round_trip' => true,
                'return_date' => $day(21), 'return_departure_airport' => 'SUB', 'return_arrival_airport' => 'CGK',
                'needs_cash_advance' => true, 'cash_advance_amount' => 1500000, 'cash_advance_status' => 'Diajukan']],
            ['Rudi Hartono', 'business_trip', 7, 8, 'Rantau', 'Banjarmasin', $self_service + ['description' => 'Pengujian alat ukur di laboratorium kalibrasi']],
        ] as [$name, $type, $a, $b, $origin, $destination, $extra]) {
            RequestNotifier::leaveTravel($this->leaveTravel($name, $type, $day($a), $day($b), $origin, $destination, $extra));
        }
    }

    private function leaveTravel(string $name, string $type, string $initial, string $akhir, string $origin, string $destination, array $extra): LeaveTravelRequest
    {
        $k = $this->k($name);
        $approved = $extra['status'] === 'approved';
        $days = (int) CarbonImmutable::parse($initial)->diffInDays(CarbonImmutable::parse($akhir)) + 1;
        $leave = $type === 'leave' ? ['leave_days' => $days, ...LeaveLedger::potongan($k, $extra['leave_category'], $days)] : [];
        $user = User::where('employee_id', $k->id)->first();

        $leave_travel = LeaveTravelRequest::create([
            'employee_id' => $k->id, 'type' => $type, 'start_date' => $initial, 'end_date' => $akhir, 'origin_city' => $origin, 'destination_city' => $destination,
            'cash_advance_status' => $type === 'business_trip' ? ($extra['cash_advance_status'] ?? 'Belum diajukan') : null,
            'created_by' => $approved ? $this->admin->id : $user?->id,
            'verified_by' => $approved ? $this->admin->id : null,
            'verified_at' => $approved ? now() : null,
            ...$leave,
            ...$extra,
        ]);
        $this->attach($leave_travel, 'leave_travel', $type === 'leave' ? 'FORMULIR PERMOHONAN CUTI' : 'SURAT PERINTAH PERJALANAN DINAS', [
            "Nama      : {$k->name}",
            "NRP       : {$k->employee_number}",
            'Jabatan   : '.($k->position?->name ?? '-'),
            'Tanggal   : '.CarbonImmutable::parse($initial)->format('d.m.Y').' s/d '.CarbonImmutable::parse($akhir)->format('d.m.Y')." ({$days} hari)",
            "Dari      : {$origin}",
            "Tujuan    : {$destination}",
            'Keperluan : '.Str::limit($extra['description'] ?? ($type === 'leave' ? LeaveLedger::KATEGORI[$extra['leave_category']] : 'Perjalanan dinas'), 46),
            '',
            $approved ? '[ DISETUJUI ]  Atasan & HR' : '[ Ditandatangani atasan langsung ]',
        ], $user?->id ?? $this->admin->id);
        LeaveTravelWriter::fill($leave_travel);

        return $leave_travel;
    }

    /**
     * Deklarasi of this plafon year: claims typed by HR from the signed form, a few glasses,
     * and three sent from the portal that HR has to check. Each nota is an image.
     */
    private function claims(): void
    {
        $provider_name = ['Head Office' => ['Klinik Medika Jakarta', 'Apotek Sehat Jakarta'], 'Site Murung Raya' => ['Klinik Site Murung Raya', 'Apotek Puruk Cahu Farma'], 'Site Rantau' => ['Klinik Rantau Sehat', 'Apotek Tapin Farma']];
        $start = CarbonImmutable::create($this->today->year, 1, 9);
        foreach ($this->people as $k) {
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $date = $start->addDays(mt_rand(0, (int) $start->diffInDays($this->today->subDays(3))));
                $beneficiary = $this->beneficiary($k);
                $items = [['type' => mt_rand(1, 6) === 1 ? 'treatment' : 'medical', ...$beneficiary, 'amount' => mt_rand(8, 60) * 10000]];
                $this->claim($k, $date, $items, $provider_name[$k->workLocation?->name][mt_rand(0, 1)] ?? null, false);
            }
        }
        // Glasses.
        foreach (['Joko Santoso' => [650000, 600000], 'Siti Rahmawati' => [800000, 750000], 'Mario Tambunan' => [450000, 400000]] as $name => [$frame, $lens]) {
            $this->claim($this->k($name), $this->today->subDays(mt_rand(20, 200)), [
                ['type' => 'frame', 'beneficiary' => 'employee', 'employee_child_id' => null, 'amount' => $frame],
                ['type' => 'lens', 'beneficiary' => 'employee', 'employee_child_id' => null, 'amount' => $lens],
            ], 'Optik Mata Prima', false);
        }
        // From the portal, waiting for HR.
        foreach ([
            ['Yoga Pratama', [['type' => 'medical', 'beneficiary' => 'children', 'amount' => 275000]], 'Klinik Site Murung Raya'],
            ['Rina Marlina', [['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 185000]], 'Klinik Medika Jakarta'],
            ['Deni Kurniawan', [['type' => 'medical', 'beneficiary' => 'spouse', 'amount' => 320000], ['type' => 'medical', 'beneficiary' => 'employee', 'amount' => 95000]], 'Apotek Puruk Cahu Farma'],
        ] as [$name, $items, $tempatPortal]) {
            $k = $this->k($name);
            $items = array_map(fn (array $i) => $i + ['employee_child_id' => $i['beneficiary'] === 'children' ? $k->profile?->children->first()?->id : null], $items);
            if ($d = $this->claim($k, $this->today->subDays(mt_rand(1, 4)), $items, $tempatPortal, true)) {
                RequestNotifier::claim($d);
            }
        }
    }

    /** Who a claim is for: the employee mostly, sometimes the spouse or a child. */
    private function beneficiary(Employee $k): array
    {
        $children = $k->profile?->children ?? collect();
        $r = mt_rand(1, 10);
        if ($r <= 2 && $k->profile?->spouse_name) {
            return ['beneficiary' => 'spouse', 'employee_child_id' => null];
        }
        if ($r <= 4 && $children->isNotEmpty()) {
            return ['beneficiary' => 'children', 'employee_child_id' => $children->random()->id];
        }

        return ['beneficiary' => 'employee', 'employee_child_id' => null];
    }

    /** One deklarasi checked against the plafon (skipped when it would go over), one nota image per amount. */
    private function claim(Employee $k, CarbonImmutable $date, array $items, ?string $provider_name, bool $self_service): ?BenefitClaim
    {
        $month = AccountingMonth::of($date);
        $year = AccountingMonth::year($month);
        if (BenefitClaimCheck::errors($k, $items, $year)) {
            return null;
        }
        $user = User::where('employee_id', $k->id)->first();
        $d = BenefitClaim::create([
            'claim_number' => BenefitClaimController::nextClaimNumber($month), 'employee_id' => $k->id, 'date' => $date->toDateString(), 'provider_name' => $provider_name,
            'receipt_count' => count($items), 'accounting_month' => $month, 'benefit_year' => $year,
            'created_by' => $self_service ? $user?->id : $this->admin->id,
            'status' => $self_service ? 'pending' : 'approved', 'source' => $self_service ? 'self_service' : 'hr',
            'verified_by' => $self_service ? null : $this->admin->id, 'verified_at' => $self_service ? null : now(),
        ]);
        $d->items()->createMany($items);
        foreach ($items as $n => $item) {
            $untuk = match ($item['beneficiary']) {
                'spouse' => $k->profile?->spouse_name ?? 'Istri / Suami',
                'children' => $k->profile?->children->firstWhere('id', $item['employee_child_id'])?->name ?? 'Anak',
                default => $k->name,
            };
            $this->attach($d, 'claim', 'KWITANSI / NOTA', [
                strtoupper($provider_name ?? 'Klinik'),
                'No.        : KW-'.$date->format('ymd').'-'.sprintf('%03d', $d->id * 3 + $n),
                'Tanggal    : '.$date->format('d.m.Y'),
                "Pasien     : {$untuk}",
                'Untuk      : '.match ($item['type']) {
                    'frame' => 'Bingkai kacamata', 'lens' => 'Lensa kacamata', 'treatment' => 'Biaya perawatan', default => 'Konsultasi dokter & obat',
                },
                '',
                'JUMLAH     : Rp '.number_format($item['amount'], 0, ',', '.'),
                '',
                '[ LUNAS ]',
            ], $self_service ? ($user?->id ?? $this->admin->id) : $this->admin->id);
        }

        return $d;
    }

    /** Two portal requests to change own data, and one HR already approved. */
    private function updateData(): void
    {
        $now = now();
        foreach ([
            ['Ferdi Saputra', ['phone' => '081351234567', 'residential_address' => 'Jl. Tjilik Riwut Km. 5 No. 12'], 'Ganti nomor HP & pindah kontrakan', 'pending'],
            ['Vina Oktaviani', ['personal_email' => 'vina.oktaviani@gmail.com', 'whatsapp_number' => '085712349876'], null, 'pending'],
            ['Kevin Sitorus', ['phone' => '082154329876'], 'Nomor lama hilang', 'approved'],
        ] as [$name, $after, $notes, $status]) {
            $k = $this->k($name);
            $changes = [];
            foreach ($after as $field => $value) {
                $changes[$field] = ['before' => $k->profile?->{$field}, 'after' => $value];
            }
            $p = EmployeeChangeRequest::create([
                'employee_id' => $k->id, 'changes' => $changes, 'children' => [], 'notes' => $notes, 'status' => $status,
                'created_by' => User::where('employee_id', $k->id)->value('id'),
                'verified_by' => $status === 'approved' ? $this->admin->id : null, 'verified_at' => $status === 'approved' ? $now : null,
            ]);
            if ($status === 'approved') {
                $k->profile->update($after);
            } else {
                RequestNotifier::updateData($p);
            }
        }
    }

    private function k(string $name): Employee
    {
        return $this->people[$name] ?? throw new \RuntimeException("Demo: karyawan {$name} tidak ada.");
    }

    /** A simple scanned-looking document (PNG) as the attachment of $owner. */
    private function attach(Model $owner, string $folder, string $title, array $lines, int $by): void
    {
        $w = 640;
        $h = 120 + count($lines) * 34 + 80;
        $im = imagecreatetruecolor($w, $h);
        $paper = imagecolorallocate($im, 252, 251, 247);
        $ink = imagecolorallocate($im, 40, 48, 70);
        $muted = imagecolorallocate($im, 140, 146, 160);
        $accent = imagecolorallocate($im, 88, 108, 154);
        $stamp = imagecolorallocate($im, 30, 140, 90);
        imagefill($im, 0, 0, $paper);
        imagefilledrectangle($im, 0, 0, $w, 10, $accent);
        imagerectangle($im, 16, 26, $w - 17, $h - 17, $muted);
        imagestring($im, 5, 36, 46, $title, $ink);
        imageline($im, 36, 72, $w - 37, 72, $muted);
        foreach ($lines as $n => $line) {
            $color = str_starts_with($line, '[') ? $stamp : $ink;
            imagestring($im, str_starts_with($line, 'JUMLAH') || str_starts_with($line, '[') ? 5 : 4, 36, 92 + $n * 34, $line, $color);
        }
        imagestring($im, 2, 36, $h - 40, 'Dokumen contoh - data demo', $muted);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        $path = "attachment/{$folder}/{$owner->getKey()}/".Str::uuid().'.png';
        Storage::disk('local')->put($path, $png);
        $owner->attachments()->create(['path' => $path, 'name' => Str::slug($title).'.png', 'mime' => 'image/png', 'size' => strlen($png), 'page_count' => 1, 'created_by' => $by]);
    }
}
