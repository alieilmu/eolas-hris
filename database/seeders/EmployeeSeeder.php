<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Support\Organization\PositionComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 15 fictional employees, spread so every dashboard list has something to show.
 * Contract, retirement and birthday dates are relative to the day the seeder
 * runs, so the scenarios stay valid whenever it is re-run.
 *
 * NRPs follow J LL YY NNN (see App\Support\Karyawan\Nrp): each spec names its
 * jenis (default 1) and lokasi penerimaan; the year comes from Tanggal Masuk.
 * Status asal then follows from comparing lokasi penerimaan with the position's
 * work location — Agus, Indah and Dimas come out as Kiriman.
 *
 * All identity numbers are generated in the right format but are fictional.
 */
class EmployeeSeeder extends Seeder
{
    /** Numbering per J LL YY prefix starts here, so demo NRPs read like real ones. */
    private const FIRST_URUT = 101;

    /** [provinsi, kabupaten/kota, BPS region code, kode pos] */
    private const REGIONS = [
        'jakarta' => ['DKI Jakarta', 'Kota Jakarta Timur', '3175', '13220'],
        'murung_raya' => ['Kalimantan Tengah', 'Kabupaten Murung Raya', '6212', '73911'],
        'tapin' => ['Kalimantan Selatan', 'Kabupaten Tapin', '6305', '71111'],
        'surabaya' => ['Jawa Timur', 'Kota Surabaya', '3578', '60111'],
        'makassar' => ['Sulawesi Selatan', 'Kota Makassar', '7371', '90111'],
        'medan' => ['Sumatera Utara', 'Kota Medan', '1275', '20111'],
    ];

    /** Work location -> region the job is based in. */
    private const WORK_REGION = ['Head Office' => 'jakarta', 'Site Murung Raya' => 'murung_raya', 'Site Rantau' => 'tapin'];

    /** Lokasi penerimaan code -> the work location it stands for (mirrors MasterDataSeeder). */
    private const LOKASI_WORK = ['01' => 'Head Office', '11' => 'Head Office', '03' => 'Site Rantau', '15' => 'Site Murung Raya'];

    /** @var array<string, int> last nomor urut per J LL YY prefix */
    protected array $sequence = [];

    /** @var array<string, int> work location name => id */
    protected array $workLocations = [];

    /** @var array<int, string> jabatan id => demo key from OrgStructureSeeder::JABATANS */
    protected array $keyOf = [];

    private function workLocationOf(Position $position): string
    {
        // A position belongs to one work location; its holder works there.
        return $position->workLocation->name;
    }

    /** Golongan (Master Data Comben) by SubGroup layer, inside each layer's plafon range. */
    private const GOLONGAN = [6 => '5D', 5 => '5A', 4 => '4E', 3 => '4C', 2 => '3C', 1 => '1C'];

    /** SubGroups whose plafon row expects other golongan than their layer's default. */
    private const GOLONGAN_OF = ['Officer HO/ Group Leader' => '4A', 'Operator' => 'D2J', 'Mekanik' => 'G2J', 'Welder' => 'W2J'];

    private const STREETS = [
        'Jl. Jenderal Sudirman', 'Jl. MT Haryono', 'Jl. Ahmad Yani', 'Jl. Diponegoro', 'Jl. Gajah Mada',
        'Jl. Pahlawan', 'Jl. Merdeka', 'Jl. Soekarno Hatta', 'Jl. Imam Bonjol', 'Jl. Pattimura',
        'Jl. Veteran', 'Jl. Kartini', 'Jl. Hasanuddin', 'Jl. Mulawarman', 'Jl. Cendrawasih',
    ];

    private const BANKS = ['Bank Mandiri', 'BRI', 'BNI', 'BCA'];

    private const AYAH = [
        'Suparman', 'Sutrisno', 'Mulyadi', 'Suryanto', 'Sugiarto', 'Budiman', 'Wahyudi', 'Slamet',
        'Darmawan', 'Kusnadi', 'Rahmat', 'Supriadi', 'Hasan', 'Salim', 'Jumadi',
    ];

    private const IBU = [
        'Sumiati', 'Suryani', 'Kartini', 'Wartini', 'Sri Wahyuni', 'Aminah', 'Rukmini', 'Nurhayati',
        'Siti Aminah', 'Endang', 'Yuliani', 'Parmi', 'Murni', 'Rohani', 'Fatimah',
    ];

    /** Groups kept for the deliberately incomplete profile. */
    private const INCOMPLETE_KEEP = [
        'sequence_no', 'sap_employee_number', 'employee_class', 'technical_class',
        'birth_place', 'birth_date', 'hire_date',
        'employment_status', 'marital_status', 'is_permanent',
        'grade',
        'religion', 'gender', 'ethnicity', 'phone', 'whatsapp_number',
    ];

    public function run(): void
    {
        $today = CarbonImmutable::today();
        $positions = collect(OrgStructureSeeder::$created)->map(fn (int $id) => Position::with(['jobLevel', 'workLocation'])->findOrFail($id));
        $this->keyOf = $positions->mapWithKeys(fn (Position $j, string $key) => [$j->id => $key])->all();
        $this->workLocations = WorkLocation::pluck('id', 'name')->all();

        foreach ($this->employees($today) as $i => $e) {
            $this->createEmployee($this->firstIndex() + $i, $e, $positions[$e['position']], $today);
        }
    }

    /** Index of the first employee here (names, addresses and identity numbers follow it). */
    protected function firstIndex(): int
    {
        return 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function employees(CarbonImmutable $t): array
    {
        return [
            [ // Permanent, retires in 5 months, married with 2 children
                'name' => 'Hendra Wijaya', 'gender' => 'L', 'position' => 'KDV-OPS',
                'birth' => $t->subYears(55)->addMonths(5), 'birth_place' => 'Samarinda', 'city' => 'murung_raya', 'location' => '15',
                'hired' => '2001-03-01', 'appointment_letter' => '2002-03-01',
                'religion' => 'Islam', 'ethnic_group' => 'Banjar', 'education_level' => ['S1', 'Teknik Mesin', 'Universitas Mulawarman', 1994],
                'is_married' => ['Ratna Sari Dewi', '1996-05-18', 'Ibu Rumah Tangga'], 'children' => ['Rizky Aditya', 'Nadia Putri'],
            ],
            [ // Permanent, birthday this month
                'name' => 'Siti Rahmawati', 'gender' => 'P', 'position' => 'KDP-HRG',
                'birth' => CarbonImmutable::create(1985, $t->month, 20), 'birth_place' => 'Jakarta', 'city' => 'jakarta', 'location' => '01',
                'hired' => '2010-07-01', 'appointment_letter' => '2011-07-01',
                'religion' => 'Islam', 'ethnic_group' => 'Betawi', 'education_level' => ['S1', 'Psikologi', 'Universitas Indonesia', 2007],
                'is_married' => ['Andi Prasetyo', '2012-09-09', 'Karyawan Swasta'], 'children' => ['Keisha Rahma'],
            ],
            [ // Permanent, married with 3 children
                'name' => 'Bambang Susilo', 'gender' => 'L', 'position' => 'KDP-PRD',
                'birth' => '1978-06-12', 'birth_place' => 'Semarang', 'city' => 'murung_raya', 'location' => '15',
                'hired' => '2005-02-14', 'appointment_letter' => '2006-02-14',
                'religion' => 'Islam', 'ethnic_group' => 'Jawa', 'education_level' => ['S1', 'Teknik Industri', 'Universitas Diponegoro', 2001],
                'is_married' => ['Wulan Sari', '2004-10-10', 'Guru'], 'children' => ['Bima Susilo', 'Citra Ayu', 'Dafa Pratama'],
            ],
            [ // Permanent, single — PKWT history kept from before the appointment
                'name' => 'Dewi Lestari', 'gender' => 'P', 'position' => 'KDP-FIN',
                'birth' => '1990-02-03', 'birth_place' => 'Jakarta', 'city' => 'jakarta', 'location' => '11',
                'hired' => '2016-04-01', 'appointment_letter' => '2018-04-01',
                'contract' => [['2016-04-01', '2017-03-31'], ['2017-04-01', '2018-03-31']],
                'religion' => 'Kristen', 'ethnic_group' => 'Jawa', 'education_level' => ['S1', 'Akuntansi', 'Universitas Trisakti', 2012],
            ],
            [ // Permanent, Kiriman
                'name' => 'Agus Setiawan', 'gender' => 'L', 'position' => 'KDP-MTC',
                'birth' => '1982-11-21', 'birth_place' => 'Surabaya', 'city' => 'surabaya', 'location' => '01', 'type' => '7',
                'hired' => '2008-08-01', 'appointment_letter' => '2009-08-01',
                'religion' => 'Islam', 'ethnic_group' => 'Jawa', 'education_level' => ['D3', 'Teknik Mesin', 'Politeknik Negeri Surabaya', 2003],
                'is_married' => ['Lia Kurniasih', '2009-12-12', 'Wiraswasta'], 'children' => ['Farel Setiawan'],
            ],
            [ // PKWT II running for another 6 months
                'name' => 'Rina Marlina', 'gender' => 'P', 'position' => 'STF-HRG',
                'birth' => '1998-04-17', 'birth_place' => 'Bandung', 'city' => 'jakarta', 'location' => '01',
                'contract' => $this->periodsEndingOn($t->addMonths(6), [12, 12]),
                'religion' => 'Islam', 'ethnic_group' => 'Sunda', 'education_level' => ['S1', 'Manajemen SDM', 'Universitas Padjadjaran', 2020],
            ],
            [ // PKWT ends in 19 days
                'name' => 'Fajar Nugroho', 'gender' => 'L', 'position' => 'STF-FIN',
                'birth' => '1997-08-09', 'birth_place' => 'Yogyakarta', 'city' => 'jakarta', 'location' => '01',
                'contract' => $this->periodsEndingOn($t->addDays(19), [12]),
                'religion' => 'Islam', 'ethnic_group' => 'Jawa', 'education_level' => ['S1', 'Akuntansi', 'Universitas Gadjah Mada', 2019],
            ],
            [ // PKWT ends in 24 days, married with a toddler
                'name' => 'Yoga Pratama', 'gender' => 'L', 'position' => 'OPR-PRD',
                'birth' => '1996-01-25', 'birth_place' => 'Balikpapan', 'city' => 'murung_raya', 'location' => '15',
                'contract' => $this->periodsEndingOn($t->addDays(24), [6, 6]),
                'religion' => 'Islam', 'ethnic_group' => 'Kutai', 'education_level' => ['SMA/SMK', 'Teknik Kendaraan Ringan', 'SMK Negeri 1 Balikpapan', 2014],
                'is_married' => ['Anisa Rahmawati', '2023-06-17', 'Ibu Rumah Tangga'], 'children' => ['Arkan Pratama'],
            ],
            [ // PKWT ended 26 days ago and was never extended
                'name' => 'Maya Sari', 'gender' => 'P', 'position' => 'OPR-PRD',
                'birth' => '2000-12-05', 'birth_place' => 'Balikpapan', 'city' => 'murung_raya', 'location' => '15',
                'contract' => $this->periodsEndingOn($t->subDays(26), [12]),
                'religion' => 'Kristen', 'ethnic_group' => 'Dayak', 'education_level' => ['SMA/SMK', 'Kimia Industri', 'SMK Negeri 2 Balikpapan', 2018],
            ],
            [ // PKWT III ends in 55 days (red in Data Lengkap, not yet in the 1-month list)
                'name' => 'Rudi Hartono', 'gender' => 'L', 'position' => 'MKN-MTC',
                'birth' => '1988-03-30', 'birth_place' => 'Samarinda', 'city' => 'tapin', 'location' => '03',
                'contract' => $this->periodsEndingOn($t->addDays(55), [12, 12, 12]),
                'religion' => 'Islam', 'ethnic_group' => 'Banjar', 'education_level' => ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Samarinda', 2006],
                'is_married' => ['Fitri Handayani', '2012-02-14', 'Pedagang'], 'children' => ['Rafi Hartono', 'Salsa Hartono'],
            ],
            [ // PKWT running, Kiriman, birthday this month
                'name' => 'Indah Permatasari', 'gender' => 'P', 'position' => 'STF-FIN',
                'birth' => CarbonImmutable::create(1999, $t->month, 8), 'birth_place' => 'Makassar', 'city' => 'makassar', 'location' => '15',
                'contract' => $this->periodsEndingOn($t->addMonths(8), [12]),
                'religion' => 'Islam', 'ethnic_group' => 'Bugis', 'education_level' => ['S1', 'Manajemen Keuangan', 'Universitas Hasanuddin', 2021],
            ],
            [ // Permanent, retires in 75 days, grown-up children
                'name' => 'Joko Santoso', 'gender' => 'L', 'position' => 'SPV-MTC',
                'birth' => $t->subYears(55)->addDays(75), 'birth_place' => 'Yogyakarta', 'city' => 'tapin', 'location' => '03',
                'hired' => '1998-01-05', 'appointment_letter' => '1999-01-05',
                'religion' => 'Katolik', 'ethnic_group' => 'Jawa', 'education_level' => ['SMA/SMK', 'Mesin Produksi', 'STM Negeri 2 Yogyakarta', 1990],
                'is_married' => ['Sri Lestari', '1996-03-03', 'Ibu Rumah Tangga'], 'children' => ['Andreas Santoso', 'Maria Santoso', 'Yohanes Santoso'],
            ],
            [ // PKWT running, profile deliberately incomplete
                'name' => 'Lina Agustina', 'gender' => 'P', 'position' => 'OPR-PRD',
                'birth' => '2002-08-17', 'birth_place' => 'Balikpapan', 'city' => 'murung_raya', 'location' => '15', 'type' => '9',
                'contract' => $this->periodsEndingOn($t->addMonths(10), [12]),
                'religion' => 'Islam', 'ethnic_group' => 'Banjar', 'education_level' => ['SMA/SMK', 'Teknik Produksi', 'SMK Negeri 3 Balikpapan', 2020],
                'incomplete' => true,
            ],
            [ // PKWT II running, Kiriman, married without children
                'name' => 'Dimas Aditya', 'gender' => 'L', 'position' => 'OPR-PRD',
                'birth' => '1995-10-02', 'birth_place' => 'Medan', 'city' => 'medan', 'location' => '01',
                'contract' => $this->periodsEndingOn($t->addMonths(4), [12, 12]),
                'religion' => 'Kristen', 'ethnic_group' => 'Batak', 'education_level' => ['D3', 'Teknik Kimia', 'Politeknik Negeri Medan', 2016],
                'is_married' => ['Maria Simanjuntak', '2025-11-15', 'Perawat'],
            ],
            [ // Left the company: resigned 3 months ago
                'name' => 'Wahyu Kurniawan', 'gender' => 'L', 'position' => 'OPR-PRD',
                'birth' => '1994-07-07', 'birth_place' => 'Balikpapan', 'city' => 'murung_raya', 'location' => '15',
                'contract' => $this->periodsEndingOn($t->addMonths(2), [12, 12]),
                'religion' => 'Islam', 'ethnic_group' => 'Kutai', 'education_level' => ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 1 Balikpapan', 2012],
                'termination' => ['Resign', $t->subMonths(3), 'Mengundurkan diri, pindah ke perusahaan lain'],
            ],
        ];
    }

    /**
     * Consecutive contracts of the given lengths (months) where the last one ends
     * on $end. Each end date is start + n months - 1 day, as in the extend dialog.
     *
     * @param  list<int>  $monthsEach
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    protected function periodsEndingOn(CarbonImmutable $end, array $monthsEach): array
    {
        $periods = [];
        foreach (array_reverse($monthsEach) as $months) {
            $start = $end->addDay()->subMonthsNoOverflow($months);
            array_unshift($periods, [$start, $end]);
            $end = $start->subDay();
        }

        return $periods;
    }

    protected function createEmployee(int $i, array $e, Position $position, CarbonImmutable $today): void
    {
        $termination = $e['termination'] ?? null;
        $contract = array_map(
            fn (array $p) => [CarbonImmutable::parse($p[0]), CarbonImmutable::parse($p[1])],
            $e['contract'] ?? [],
        );
        $hired = CarbonImmutable::parse($e['hired'] ?? $contract[0][0]);

        // J LL YY NNN
        $prefix = ($e['type'] ?? '1').$e['location'].$hired->format('y');
        $this->sequence[$prefix] = ($this->sequence[$prefix] ?? self::FIRST_URUT - 1) + 1;
        $employee_number = $prefix.sprintf('%03d', $this->sequence[$prefix]);

        $employee = Employee::create([
            'employee_number' => $employee_number,
            'name' => $e['name'],
            'email' => Str::of($e['name'])->lower()->replace(' ', '.').'@eolas.local',
            'status' => $termination ? Employee::STATUS_TERMINATED : Employee::STATUS_ACTIVE,
            // Their placement is the one of their position.
            ...PositionComposer::employeePlacementOf($position),
            'position_id' => $position->id,
            'work_location_id' => $this->workLocations[$this->workLocationOf($position)],
            'termination_date' => $termination[1] ?? null,
            'termination_reason' => $termination[0] ?? null,
            'termination_notes' => $termination[2] ?? null,
        ]);

        $profile = $this->profileAttributes($i, $employee_number, $e, $position, $hired);
        if ($e['incomplete'] ?? false) {
            $profile = Arr::only($profile, self::INCOMPLETE_KEEP);
        }

        $profileModel = $employee->profile()->create($profile);

        foreach ($contract as $n => [$start, $end]) {
            $profileModel->contracts()->create([
                'sequence_no' => $n + 1,
                'start_date' => $start,
                'end_date' => $end,
            ]);
        }

        if (isset($e['is_married']) && ! empty($e['children'])) {
            $married = CarbonImmutable::parse($e['is_married'][1]);
            foreach ($e['children'] as $n => $name) {
                $profileModel->children()->create($this->childAttributes($n, $name, $married, $today));
            }
        }
    }

    private function profileAttributes(int $i, string $employee_number, array $e, Position $position, CarbonImmutable $hired): array
    {
        // Old demo levels (1 = top, 5 = staff) from the SubGroup layer (6 = top, 1 = non-staff).
        $level = [6 => 1, 5 => 2, 4 => 3, 3 => 4, 2 => 5, 1 => 5][$position->jobLevel->layer];
        $female = $e['gender'] === 'P';
        $birth = CarbonImmutable::parse($e['birth']);
        $permanent = isset($e['appointment_letter']);
        $is_married = $e['is_married'] ?? null;

        $home = self::REGIONS[$e['city']];
        $workKey = self::WORK_REGION[$this->workLocationOf($position)];
        // Staff hired elsewhere (Kiriman) live near their workplace; their letters still go to their home town.
        $transferred = self::LOKASI_WORK[$e['location']] !== $this->workLocationOf($position);
        $tinggal = $transferred ? self::REGIONS[$workKey] : $home;

        // NIK: region (4) + kecamatan (2) + birth DDMMYY with +40 on the day for women (6) + sequence (4).
        $national_id_number = $home[2].sprintf('%02d', $i % 20 + 1)
            .sprintf('%02d', $birth->day + ($female ? 40 : 0)).$birth->format('my')
            .sprintf('%04d', $i + 1);

        $noHp = sprintf('0812%08d', 54120300 + $i * 1117);
        $ayah = self::AYAH[$i % count(self::AYAH)];
        $ibu = self::IBU[$i % count(self::IBU)];
        $key = $this->keyOf[$position->id];
        $isStaff = $level <= 4 || str_starts_with($key, 'STF') || str_starts_with($key, 'GL-');
        $isMekanik = str_contains($key, 'MTC') || str_starts_with($key, 'MKN');
        $baju = $female ? ['S', 'M', 'L'][$i % 3] : ['M', 'L', 'XL'][$i % 3];

        return [
            // 1. Identitas & Pekerjaan
            'sequence_no' => $i + 1,
            'sap_employee_number' => '5'.$employee_number, // the full NRP: its first digit is significant, so it cannot be dropped

            'employee_class' => $isStaff ? 'Staff' : 'Non Staff',
            'technical_class' => $isMekanik ? 'Mekanik/Nonom' : 'Staff/Operator',

            // 2. Kelahiran & Masa Kerja
            'birth_place' => $e['birth_place'],
            'birth_date' => $birth,
            'hire_date' => $hired,

            // 3. Status Pegawai & Perkawinan
            'employment_status' => $permanent ? 'Tetap' : 'PKWT',
            'marital_status' => $is_married ? 'Menikah' : 'Belum Menikah',
            'marriage_date' => $is_married[1] ?? null,

            // 4. Golongan & Hak Normatif
            // Plafon Pengobatan follows from SubGroup, golongan and family (Modul Comben).
            'grade' => self::GOLONGAN_OF[$position->jobLevel->name] ?? self::GOLONGAN[$position->jobLevel->layer],

            // 6. Pengangkatan Pegawai
            'is_permanent' => $permanent,
            'appointment_letter_number' => $permanent ? sprintf('%03d/SK-HRD/%s', $i + 1, substr($e['appointment_letter'], 0, 4)) : null,
            'appointment_letter_date' => $e['appointment_letter'] ?? null,

            // 7. Ukuran Seragam & APD
            'shirt_size' => $baju,
            'pants_size' => (string) (($female ? 27 : 30) + ($i % 3) * 2),
            'shoe_size' => (string) (($female ? 37 : 40) + $i % 3),
            'ppe_size' => $baju,

            // 8. Tanda Pengenal & Kepesertaan (individual NPWP = NIK since 2024)
            'national_id_number' => $national_id_number,
            'national_id_province' => $home[0],
            'national_id_city' => $home[1],
            'tax_id_number' => $national_id_number,
            'tax_id_registered_date' => $hired->addMonth(),
            'bpjs_employment_number' => sprintf('26%09d', 124123 + $i),
            'bpjs_employment_registered_date' => $hired,
            'bpjs_health_number' => sprintf('0001%09d', 3124123 + $i),

            // 9. Rekening Bank
            'bank_account_number' => '14900'.$employee_number,
            'bank_name' => self::BANKS[$i % count(self::BANKS)],
            'bank_account_holder' => $e['name'],

            // 10. Data Pribadi
            'religion' => $e['religion'],
            'gender' => $female ? 'Perempuan' : 'Laki-laki',
            'ethnicity' => $e['ethnic_group'],

            // 11. Riwayat Pendidikan
            'education_level' => $e['education_level'][0],
            'education_major' => $e['education_level'][1],
            'education_institution' => $e['education_level'][2],
            'graduation_year' => $e['education_level'][3],

            // 12. Alamat Tinggal
            'residential_address' => self::STREETS[$i % count(self::STREETS)].' No. '.($i * 7 % 90 + 3),
            'residential_rt' => sprintf('%03d', $i % 9 + 1),
            'residential_rw' => sprintf('%03d', $i % 5 + 2),
            'residential_city' => $tinggal[1],
            'residential_province' => $tinggal[0],
            'residential_postal_code' => $tinggal[3],

            // 13. Alamat Surat Menyurat
            'mailing_address' => self::STREETS[($i + 5) % count(self::STREETS)].' No. '.($i * 11 % 80 + 5),
            'mailing_rt' => sprintf('%03d', ($i + 3) % 9 + 1),
            'mailing_rw' => sprintf('%03d', ($i + 2) % 5 + 1),
            'mailing_city' => $home[1],
            'mailing_postal_code' => $home[3],
            'mailing_phone' => null, // landline, optional
            'mailing_mobile' => $noHp,

            // 14. Kontak Darurat & Nomor Pribadi
            'emergency_contact_phone' => sprintf('0813%08d', 66230100 + $i * 991),
            'emergency_contact_name' => $is_married[0] ?? $ibu,
            'emergency_contact_relationship' => $is_married ? ($female ? 'Suami' : 'Istri') : 'Ibu Kandung',
            'phone' => $noHp,
            'whatsapp_number' => $noHp,

            // 15. Email
            'personal_email' => Str::of($e['name'])->lower()->replace(' ', '.').'@example.com',

            // 16. Keluarga Inti
            'spouse_name' => $is_married[0] ?? null,
            'spouse_birth_date' => $is_married ? $birth->addYears($female ? -3 : 2)->addDays(41 * ($i + 1)) : null,
            'spouse_occupation' => $is_married[2] ?? null,

            // 18. Data Orang Tua
            'father_name' => $ayah,
            'father_birth_date' => $birth->subYears(29)->subDays(37 * ($i + 1)),
            'mother_name' => $ibu,
            'mother_birth_date' => $birth->subYears(25)->subDays(23 * ($i + 1)),
            'parents_rt' => sprintf('%03d', ($i + 3) % 9 + 1),
            'parents_rw' => sprintf('%03d', ($i + 2) % 5 + 1),
            'parents_city' => $home[1],
            'parents_postal_code' => $home[3],
            'parents_phone' => null, // landline, optional
            'parents_mobile' => sprintf('0821%08d', 33410200 + $i * 773),

            // 19. Data Mertua (married employees only)
            'father_in_law_name' => $is_married ? self::AYAH[14 - $i % 15] : null,
            'father_in_law_birth_date' => $is_married ? $birth->subYears(27)->subDays(19 * ($i + 1)) : null,
            'mother_in_law_name' => $is_married ? self::IBU[14 - $i % 15] : null,
            'mother_in_law_birth_date' => $is_married ? $birth->subYears(24)->subDays(29 * ($i + 1)) : null,
            'in_laws_rt' => $is_married ? sprintf('%03d', ($i + 6) % 9 + 1) : null,
            'in_laws_rw' => $is_married ? sprintf('%03d', ($i + 4) % 5 + 1) : null,
            'in_laws_city' => $is_married ? $tinggal[1] : null,
            'in_laws_postal_code' => $is_married ? $tinggal[3] : null,
            'in_laws_mobile' => $is_married ? sprintf('0852%08d', 71150300 + $i * 619) : null,
        ];
    }

    /** Children are born from a year after the wedding, two years apart. */
    private function childAttributes(int $n, string $name, CarbonImmutable $married, CarbonImmutable $today): array
    {
        $birth = $married->addYears(1 + 2 * $n)->addMonths(3);
        $age = (int) $birth->diffInYears($today);

        $education_level = match (true) {
            $age < 4 => 'Belum Sekolah',
            $age < 6 => 'TK',
            $age < 12 => 'SD',
            $age < 15 => 'SMP',
            $age < 18 => 'SMA/SMK',
            default => 'S1',
        };

        return [
            'sort_order' => $n + 1,
            'name' => $name,
            'birth_date' => $birth,
            'education_level' => $education_level,
            'employment_status' => $age >= 23 ? 'Bekerja' : 'Belum Bekerja',
            'marital_status' => $age >= 26 ? 'Menikah' : 'Belum Menikah',
        ];
    }
}
