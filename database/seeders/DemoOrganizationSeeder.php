<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Directorate;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\LookupValue;
use App\Models\Position;
use App\Models\Section;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use App\Support\Organization\PositionComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use RuntimeException;

/**
 * The demo company at full size: every work location gets a chain of command (each position
 * points at its superior, so Struktur Organisasi shows the whole tree at once) and the
 * positions are filled up to their headcount — about 500 active employees in all, on top of
 * the hand-written ones of KaryawanSeeder / DemoEmployeeSeeder, which keep their stories.
 *
 * The generated people are varied but deterministic (fixed random seed): names from several
 * ethnic groups, ages and education by level, permanent staff and PKWT contracts, families.
 */
class DemoOrganizationSeeder extends EmployeeSeeder
{
    use WithoutModelEvents;

    /** Units the bigger company needs besides OrgStructureSeeder's: department => sections. */
    private const MORE_SECTIONS = ['HRGA' => ['GENERAL AFFAIR'], 'FINANCE' => ['PAJAK']];

    private const MORE_DISCIPLINES = ['GENERAL AFFAIR', 'TAX'];

    /**
     * The chain of command per location: key => [SubGroup, unit level, unit name, discipline,
     * superior key, headcount]. Keys of OrgStructureSeeder::JABATANS are its positions
     * (and keep their people); null SubGroup = that existing position. Section Head and up
     * hold a position alone (JobLevel::isSingleHolder), so their headcount is 1.
     */
    private const TREE = [
        'Head Office' => [
            'DIR-UTM' => ['BOD', 'directorate', 'Direktur Utama', null, null, 1],
            'KDV-UMK' => [null, null, null, null, 'DIR-UTM', 1],
            'KDP-HRG' => [null, null, null, null, 'KDV-UMK', 1],
            'KDP-FIN' => [null, null, null, null, 'KDV-UMK', 1],
            'SPV-PRS' => ['Section Head HO', 'section', 'PERSONALIA', null, 'KDP-HRG', 1],
            'SPV-GA' => ['Section Head HO', 'section', 'GENERAL AFFAIR', null, 'KDP-HRG', 1],
            'SPV-ACC' => ['Section Head HO', 'section', 'AKUNTANSI', null, 'KDP-FIN', 1],
            'SPV-TAX' => ['Section Head HO', 'section', 'PAJAK', null, 'KDP-FIN', 1],
            'STF-HRG' => [null, null, null, null, 'SPV-PRS', 14],
            'STF-GA' => ['Officer HO/ Group Leader', 'section', 'GENERAL AFFAIR', 'GENERAL AFFAIR', 'SPV-GA', 12],
            'STF-FIN' => [null, null, null, null, 'SPV-ACC', 14],
            'STF-TAX' => ['Junior Staff', 'section', 'PAJAK', 'TAX', 'SPV-TAX', 8],
        ],
        'Site Murung Raya' => [
            'KDV-OPS' => [null, null, null, null, null, 1],
            'PM-MRY' => ['Project Manager', null, null, null, 'KDV-OPS', 1],
            'DPM-MRY' => ['Deputy Project Manager', null, null, null, 'PM-MRY', 1],
            'KDP-PRD' => [null, null, null, null, 'DPM-MRY', 1],
            'SPV-PRD-MRY' => ['Section Head Site', 'section', 'OPERASI TAMBANG', null, 'KDP-PRD', 1],
            'GL-PRD-MRY' => ['Officer HO/ Group Leader', 'section', 'OPERASI TAMBANG', 'PRODUCTION OPERATOR', 'SPV-PRD-MRY', 14],
            'OPR-PRD' => [null, null, null, null, 'GL-PRD-MRY', 220],
            'DH-MTC-MRY' => ['Departement Head', 'department', 'MAINTENANCE', null, 'DPM-MRY', 1],
            'SPV-MTC-MRY' => ['Section Head Site', 'section', 'WORKSHOP', null, 'DH-MTC-MRY', 1],
            'GL-MTC-MRY' => ['Officer HO/ Group Leader', 'section', 'WORKSHOP', 'MECHANIC', 'SPV-MTC-MRY', 4],
            'MKN-MRY' => ['Mekanik', 'section', 'WORKSHOP', 'MECHANIC', 'GL-MTC-MRY', 40],
        ],
        'Site Rantau' => [
            'PM-RTU' => ['Project Manager', null, null, null, null, 1],
            'DPM-RTU' => ['Deputy Project Manager', null, null, null, 'PM-RTU', 1],
            'KDP-MTC' => [null, null, null, null, 'DPM-RTU', 1],
            'SPV-MTC' => [null, null, null, null, 'KDP-MTC', 1],
            'GL-MTC-RTU' => ['Officer HO/ Group Leader', 'section', 'WORKSHOP', 'MECHANIC', 'SPV-MTC', 7],
            'MKN-MTC' => [null, null, null, null, 'GL-MTC-RTU', 95],
            'DH-PRD-RTU' => ['Departement Head', 'department', 'PRODUKSI', null, 'DPM-RTU', 1],
            'SPV-PRD-RTU' => ['Section Head Site', 'section', 'OPERASI TAMBANG', null, 'DH-PRD-RTU', 1],
            'GL-PRD-RTU' => ['Officer HO/ Group Leader', 'section', 'OPERASI TAMBANG', 'PRODUCTION OPERATOR', 'SPV-PRD-RTU', 4],
            'OPR-RTU' => ['Operator', 'section', 'OPERASI TAMBANG', 'PRODUCTION OPERATOR', 'GL-PRD-RTU', 50],
        ],
    ];

    /** Lokasi penerimaan codes: mostly the location itself, some sent from elsewhere (Kiriman). */
    private const POH = ['Head Office' => ['01', '01', '11', '11', '15'], 'Site Murung Raya' => ['15', '15', '15', '15', '03', '01'], 'Site Rantau' => ['03', '03', '03', '03', '15', '01']];

    /** ethnic group => [home region, religions (weighted), birth places, male first names, female first names, family names] */
    private const PEOPLE = [
        'Jawa' => ['surabaya', ['Islam', 'Islam', 'Islam', 'Islam', 'Kristen', 'Katolik'], ['Surabaya', 'Semarang', 'Yogyakarta', 'Malang', 'Klaten', 'Solo', 'Kediri'],
            ['Agung', 'Bagus', 'Dwi', 'Eko', 'Fajar', 'Galih', 'Hadi', 'Joko', 'Kurniawan', 'Purwanto', 'Rizky', 'Setyo', 'Teguh', 'Wahyu', 'Yanuar', 'Aris', 'Bayu', 'Catur', 'Dimas', 'Gunawan'],
            ['Ayu', 'Dewi', 'Endah', 'Fitri', 'Indah', 'Lestari', 'Nining', 'Puji', 'Rahayu', 'Sri', 'Tri', 'Wulan', 'Yuni', 'Ratna', 'Sekar'],
            ['Santoso', 'Prasetyo', 'Wibowo', 'Saputro', 'Nugroho', 'Hartono', 'Setiawan', 'Susilo', 'Kusuma', 'Purnomo', 'Wijaya', 'Hidayat', 'Raharjo', 'Sudarsono', 'Utomo']],
        'Sunda' => ['jakarta', ['Islam'], ['Bandung', 'Bogor', 'Sukabumi', 'Garut', 'Tasikmalaya', 'Cianjur'],
            ['Asep', 'Dadang', 'Cecep', 'Ujang', 'Iwan', 'Dede', 'Yayan', 'Ade', 'Hendi', 'Rudi', 'Ilham', 'Rizal'],
            ['Euis', 'Neneng', 'Rina', 'Siti', 'Wida', 'Yanti', 'Nurul', 'Lilis', 'Ai', 'Rika'],
            ['Suhendar', 'Ruhiyat', 'Permana', 'Kurnia', 'Hermawan', 'Sopian', 'Mulyana', 'Gunadi', 'Sutisna', 'Rachmat']],
        'Banjar' => ['tapin', ['Islam'], ['Banjarmasin', 'Rantau', 'Martapura', 'Kandangan', 'Barabai', 'Banjarbaru', 'Amuntai'],
            ['Akhmad', 'Muhammad', 'Rahmadi', 'Syahrani', 'Fahrul', 'Hairil', 'Noor', 'Rusdi', 'Saipul', 'Yusran', 'Zainal', 'Fauzan'],
            ['Norhayati', 'Rahmah', 'Siti Aisyah', 'Mariana', 'Halimah', 'Hayatun', 'Nadia', 'Raudah'],
            ['Rahman', 'Hidayat', 'Fadillah', 'Ramadhan', 'Syarifudin', 'Arsyad', 'Hasan', 'Mahmud', 'Ansari', 'Rizani']],
        'Dayak' => ['murung_raya', ['Kristen', 'Kristen', 'Katolik', 'Islam', 'Hindu'], ['Puruk Cahu', 'Muara Teweh', 'Palangka Raya', 'Kuala Kurun', 'Buntok'],
            ['Agustinus', 'Benediktus', 'Darius', 'Elias', 'Frans', 'Kristian', 'Martinus', 'Petrus', 'Yohanes', 'Rinaldo', 'Simon', 'Teddy'],
            ['Agnes', 'Elisabeth', 'Maria', 'Natalia', 'Rosalina', 'Theresia', 'Yuliana', 'Veronika'],
            ['Saragi', 'Ningkan', 'Dinan', 'Lambung', 'Rangkap', 'Tingang', 'Bahat', 'Uyang', 'Mantir', 'Ngabe']],
        'Batak' => ['medan', ['Kristen', 'Kristen', 'Katolik', 'Islam'], ['Medan', 'Pematangsiantar', 'Tarutung', 'Balige', 'Sibolga'],
            ['Andreas', 'Benny', 'Daniel', 'Frengky', 'Hotman', 'Jonathan', 'Marihot', 'Parlindungan', 'Robert', 'Tigor', 'Samuel', 'Roni'],
            ['Debora', 'Friska', 'Imelda', 'Lasmaria', 'Rotua', 'Sondang', 'Tiurma', 'Yohana'],
            ['Siregar', 'Simanjuntak', 'Nainggolan', 'Hutapea', 'Pardede', 'Sinaga', 'Situmorang', 'Panjaitan', 'Manurung', 'Sitompul', 'Lubis', 'Nasution']],
        'Bugis' => ['makassar', ['Islam'], ['Makassar', 'Bone', 'Parepare', 'Sengkang', 'Watampone'],
            ['Andi Arfan', 'Andi Baso', 'Muhammad Rizal', 'Syamsul', 'Ridwan', 'Basri', 'Hasanuddin', 'Irwan', 'Jamaluddin', 'Kaharuddin'],
            ['Andi Nurul', 'Andi Fitri', 'Hasnawati', 'Nurhayati', 'Rosmiati', 'Sitti Rahma', 'Wahyuni', 'Kasmawati'],
            ['Pawelloi', 'Mappanyukki', 'Paturusi', 'Rahim', 'Sulaiman', 'Latif', 'Amir', 'Daeng Nai']],
        'Betawi' => ['jakarta', ['Islam'], ['Jakarta', 'Bekasi', 'Depok', 'Tangerang'],
            ['Ahmad', 'Bahrudin', 'Fikri', 'Hasbi', 'Mamat', 'Rohman', 'Sapta', 'Zaenal'],
            ['Aminah', 'Fauziah', 'Khadijah', 'Nurlela', 'Rohimah', 'Zahra'],
            ['Saputra', 'Haris', 'Fadli', 'Ramdani', 'Syahputra', 'Maulana']],
    ];

    /** Ethnic mix per location. */
    private const MIX = [
        'Head Office' => ['Jawa', 'Jawa', 'Sunda', 'Sunda', 'Betawi', 'Batak', 'Bugis', 'Banjar'],
        'Site Murung Raya' => ['Dayak', 'Dayak', 'Dayak', 'Banjar', 'Banjar', 'Jawa', 'Jawa', 'Batak', 'Bugis'],
        'Site Rantau' => ['Banjar', 'Banjar', 'Banjar', 'Jawa', 'Jawa', 'Dayak', 'Batak', 'Bugis'],
    ];

    /** education by SubGroup layer: [level, majors, institutions] */
    private const EDUCATION = [
        1 => ['SMA/SMK', ['Teknik Alat Berat', 'Teknik Mesin', 'Teknik Kendaraan Ringan', 'Teknik Otomotif'], ['SMK Negeri 1', 'SMK Negeri 2', 'SMK Negeri 3', 'STM Pembangunan']],
        2 => ['D3', ['Teknik Mesin', 'Akuntansi', 'Administrasi Bisnis', 'Teknik Pertambangan', 'Manajemen'], ['Politeknik Negeri Banjarmasin', 'Politeknik Negeri Samarinda', 'Politeknik Negeri Jakarta', 'Politeknik Negeri Bandung']],
        3 => ['S1', ['Teknik Pertambangan', 'Teknik Mesin', 'Teknik Industri', 'Akuntansi', 'Manajemen', 'Hukum'], ['Universitas Lambung Mangkurat', 'Institut Teknologi Bandung', 'Universitas Gadjah Mada', 'Universitas Brawijaya', 'Universitas Hasanuddin', 'Universitas Diponegoro']],
        4 => ['S1', ['Teknik Pertambangan', 'Teknik Mesin', 'Manajemen', 'Akuntansi'], ['Institut Teknologi Bandung', 'Universitas Indonesia', 'Institut Teknologi Sepuluh Nopember', 'Universitas Gadjah Mada']],
        5 => ['S2', ['Manajemen', 'Teknik Pertambangan'], ['Universitas Indonesia', 'Institut Teknologi Bandung', 'Universitas Gadjah Mada']],
        6 => ['S2', ['Manajemen', 'Magister Manajemen'], ['Universitas Indonesia', 'Universitas Prasetiya Mulya']],
    ];

    private const SPOUSE_JOBS = ['Ibu Rumah Tangga', 'Ibu Rumah Tangga', 'Guru', 'Wiraswasta', 'Karyawan Swasta', 'Pedagang', 'Perawat'];

    private const CHILD_NAMES = ['Arkan', 'Bilqis', 'Daffa', 'Kenzie', 'Naura', 'Raisa', 'Rafa', 'Alya', 'Fathan', 'Zahra', 'Hafiz', 'Keisha', 'Gibran', 'Aqila', 'Rayyan', 'Nayla'];

    /** @var array<string, true> names taken (DemoSeeder keys people by name) */
    private array $taken = [];

    public function run(): void
    {
        mt_srand(4242);
        $today = CarbonImmutable::today();
        $this->workLocations = WorkLocation::pluck('id', 'name')->all();
        foreach (Employee::pluck('employee_number') as $employeeNumber) {
            $prefix = substr($employeeNumber, 0, 5);
            $this->sequence[$prefix] = max($this->sequence[$prefix] ?? 0, (int) substr($employeeNumber, 5));
        }
        $this->taken = Employee::pluck('name')->flip()->map(fn () => true)->all();

        $this->moreUnits();
        $positions = $this->chainOfCommand();
        $this->keyOf = $positions->mapWithKeys(fn (Position $p, string $key) => [$p->id => $key])->all();

        // Past the indexes the hand-written employees used (identity numbers are derived from it).
        $i = 200;
        foreach (self::TREE as $location => $nodes) {
            foreach ($nodes as $key => [, , , , , $headcount]) {
                $position = $positions[$key];
                $missing = $headcount - Employee::where('position_id', $position->id)->where('status', Employee::STATUS_ACTIVE)->count();
                for ($n = 0; $n < $missing; $n++) {
                    $this->createEmployee($i, $this->person($i, $location, $key, $position, $today), $position, $today);
                    $i++;
                }
            }
        }
    }

    private function moreUnits(): void
    {
        foreach (self::MORE_SECTIONS as $department => $sections) {
            $dept = Department::where('name', $department)->firstOrFail();
            foreach ($sections as $name) {
                $dept->sections()->firstOrCreate(['name' => $name], ['sort_order' => $dept->sections()->count()]);
            }
        }
        foreach (self::MORE_DISCIPLINES as $value) {
            LookupValue::firstOrCreate(['category' => PositionComposer::BIDANG, 'value' => $value], ['sort_order' => LookupValue::where('category', PositionComposer::BIDANG)->count()]);
        }
        LookupValues::flush();
    }

    /** @return \Illuminate\Support\Collection<string, Position> key => position, each pointing at its superior */
    private function chainOfCommand()
    {
        $units = [
            'directorate' => Directorate::pluck('id', 'name'), 'division' => Division::pluck('id', 'name'),
            'department' => Department::pluck('id', 'name'), 'section' => Section::pluck('id', 'name'),
        ];
        $positions = collect();
        foreach (self::TREE as $location => $nodes) {
            foreach ($nodes as $key => [$jobLevel, $unitLevel, $unitName, $discipline, $superior]) {
                if ($jobLevel === null) {
                    $position = Position::findOrFail(OrgStructureSeeder::$created[$key] ?? throw new RuntimeException("Unknown position {$key}"));
                } else {
                    [$position, $error] = PositionComposer::findOrCreate(array_filter([
                        'work_location_id' => $this->workLocations[$location],
                        'job_level_id' => JobLevel::where('name', $jobLevel)->value('id'),
                        ...($unitLevel ? [Position::UNIT_COLUMNS[$unitLevel] => $units[$unitLevel][$unitName] ?? throw new RuntimeException("Unknown unit {$unitName}")] : []),
                        'discipline' => $discipline,
                    ], fn ($v) => $v !== null));
                    $position ?? throw new RuntimeException("Demo position {$key}: {$error}");
                }
                $position->update(['parent_position_id' => $superior ? $positions[$superior]->id : null]);
                $positions[$key] = $position->load(['jobLevel', 'workLocation']);
            }
        }

        return $positions;
    }

    /** One generated employee for a position, in the shape EmployeeSeeder::createEmployee() takes. */
    private function person(int $i, string $location, string $key, Position $position, CarbonImmutable $today): array
    {
        $layer = $position->jobLevel->layer;
        $fieldWork = $layer <= 2 && $location !== 'Head Office';
        $female = mt_rand(1, 100) <= match (true) {
            $location === 'Head Office' && $layer <= 3 => 50,
            $fieldWork => 6,
            default => 20,
        };
        $ethnic = self::MIX[$location][mt_rand(0, count(self::MIX[$location]) - 1)];
        [$region, $religions, $places, $men, $women, $families] = self::PEOPLE[$ethnic];
        $name = $this->uniqueName($female ? $women : $men, $families);

        [$minAge, $maxAge] = [1 => [20, 46], 2 => [23, 42], 3 => [30, 50], 4 => [34, 52], 5 => [40, 54], 6 => [45, 56]][$layer];
        $birth = $today->subYears(mt_rand($minAge, $maxAge))->subDays(mt_rand(0, 364));
        $earliest = max($birth->addYears(19)->year, 2004);
        $hired = CarbonImmutable::create(mt_rand($earliest, max($earliest, $today->year)), mt_rand(1, 12), mt_rand(1, 28));
        if ($hired->gt($today->subMonths(2))) {
            $hired = $today->subMonths(mt_rand(2, 30));
        }
        // Field crews joined lately are often still on a fixed-term contract.
        $pkwt = $layer <= 2 && $hired->gt($today->subYears(3)) && mt_rand(1, 100) <= 70;

        [$level, $majors, $schools] = self::EDUCATION[$layer];
        $schoolCity = $places[mt_rand(0, count($places) - 1)];
        $school = $level === 'SMA/SMK' ? $schools[mt_rand(0, count($schools) - 1)].' '.$schoolCity : $schools[mt_rand(0, count($schools) - 1)];
        $graduated = $birth->year + ['SMA/SMK' => 18, 'D3' => 21, 'S1' => 22, 'S2' => 26][$level];

        $age = $birth->diffInYears($today);
        $married = $age >= 26 && mt_rand(1, 100) <= min(90, 20 + ($age - 26) * 6);
        $family = $families[mt_rand(0, count($families) - 1)];
        $children = [];
        if ($married) {
            for ($c = 0, $n = mt_rand(0, $age > 32 ? 3 : 1); $c < $n; $c++) {
                $children[] = self::CHILD_NAMES[($i + $c * 5) % count(self::CHILD_NAMES)].' '.explode(' ', $name)[count(explode(' ', $name)) - 1];
            }
        }
        $poh = self::POH[$location][mt_rand(0, count(self::POH[$location]) - 1)];
        $type = match (true) {
            $layer === 1 && mt_rand(1, 100) <= 6 => str_starts_with($key, 'MKN') ? '8' : '9',
            $layer >= 3 && mt_rand(1, 100) <= 10 => '7',
            default => '1',
        };

        return [
            'name' => $name, 'gender' => $female ? 'P' : 'L', 'position' => $key,
            'birth' => $birth, 'birth_place' => $places[mt_rand(0, count($places) - 1)], 'city' => $region, 'location' => $poh, 'type' => $type,
            ...($pkwt
                ? ['contract' => $this->periodsEndingOn($today->addDays(mt_rand(5, 330)), mt_rand(1, 3) === 1 ? [12, 12] : [12])]
                : ['hired' => $hired->toDateString(), 'appointment_letter' => $hired->addYear()->min($today)->toDateString()]),
            'religion' => $religions[mt_rand(0, count($religions) - 1)], 'ethnic_group' => $ethnic,
            'education_level' => [$level, $majors[mt_rand(0, count($majors) - 1)], $school, $graduated],
            ...($married ? [
                'is_married' => [$this->spouseName($female, $family), $birth->addYears(mt_rand(23, 27))->min($today->subYear())->toDateString(),
                    $female ? 'Karyawan Swasta' : self::SPOUSE_JOBS[mt_rand(0, count(self::SPOUSE_JOBS) - 1)]],
                'children' => $children,
            ] : []),
        ];
    }

    private function uniqueName(array $first, array $families): string
    {
        for ($try = 0; $try < 50; $try++) {
            $name = $first[mt_rand(0, count($first) - 1)].' '.$families[mt_rand(0, count($families) - 1)];
            if ($try > 20) {
                $name .= ' '.$families[mt_rand(0, count($families) - 1)];
            }
            if (! isset($this->taken[$name])) {
                $this->taken[$name] = true;

                return $name;
            }
        }

        throw new RuntimeException('No unique demo name left.');
    }

    private function spouseName(bool $female, string $family): string
    {
        $pool = $female ? ['Budi', 'Andi', 'Rudi', 'Hendra', 'Yusuf', 'Dedi', 'Taufik', 'Arif'] : ['Siti', 'Nur', 'Dewi', 'Rina', 'Lia', 'Yanti', 'Maya', 'Ani'];

        return $pool[mt_rand(0, count($pool) - 1)].' '.$family;
    }
}
