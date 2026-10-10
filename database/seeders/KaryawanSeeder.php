<?php

namespace Database\Seeders;

use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\WorkLocation;
use App\Support\Org\JabatanComposer;
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
class KaryawanSeeder extends Seeder
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
    protected array $urut = [];

    /** @var array<string, int> work location name => id */
    protected array $workLocations = [];

    /** @var array<int, string> jabatan id => demo key from OrgStructureSeeder::JABATANS */
    protected array $keyOf = [];

    private function workLocationOf(Jabatan $jabatan): string
    {
        // A position belongs to one work location; its holder works there.
        return $jabatan->workLocation->name;
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
        'no_urut', 'nrp_sap', 'klasifikasi', 'klasifikasi_teknis',
        'tempat_lahir', 'tanggal_lahir', 'tanggal_masuk',
        'status_pegawai', 'status_perkawinan', 'status_pegawai_tetap',
        'golongan',
        'agama', 'jenis_kelamin', 'suku_bangsa', 'no_hp', 'no_whatsapp',
    ];

    public function run(): void
    {
        $today = CarbonImmutable::today();
        $jabatans = collect(OrgStructureSeeder::$created)->map(fn (int $id) => Jabatan::with(['subGroup', 'workLocation'])->findOrFail($id));
        $this->keyOf = $jabatans->mapWithKeys(fn (Jabatan $j, string $key) => [$j->id => $key])->all();
        $this->workLocations = WorkLocation::pluck('id', 'name')->all();

        foreach ($this->employees($today) as $i => $e) {
            $this->createEmployee($this->firstIndex() + $i, $e, $jabatans[$e['jabatan']], $today);
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
                'name' => 'Hendra Wijaya', 'gender' => 'L', 'jabatan' => 'KDV-OPS',
                'lahir' => $t->subYears(55)->addMonths(5), 'tempat_lahir' => 'Samarinda', 'kota' => 'murung_raya', 'lokasi' => '15',
                'masuk' => '2001-03-01', 'sk' => '2002-03-01',
                'agama' => 'Islam', 'suku' => 'Banjar', 'pendidikan' => ['S1', 'Teknik Mesin', 'Universitas Mulawarman', 1994],
                'nikah' => ['Ratna Sari Dewi', '1996-05-18', 'Ibu Rumah Tangga'], 'anak' => ['Rizky Aditya', 'Nadia Putri'],
            ],
            [ // Permanent, birthday this month
                'name' => 'Siti Rahmawati', 'gender' => 'P', 'jabatan' => 'KDP-HRG',
                'lahir' => CarbonImmutable::create(1985, $t->month, 20), 'tempat_lahir' => 'Jakarta', 'kota' => 'jakarta', 'lokasi' => '01',
                'masuk' => '2010-07-01', 'sk' => '2011-07-01',
                'agama' => 'Islam', 'suku' => 'Betawi', 'pendidikan' => ['S1', 'Psikologi', 'Universitas Indonesia', 2007],
                'nikah' => ['Andi Prasetyo', '2012-09-09', 'Karyawan Swasta'], 'anak' => ['Keisha Rahma'],
            ],
            [ // Permanent, married with 3 children
                'name' => 'Bambang Susilo', 'gender' => 'L', 'jabatan' => 'KDP-PRD',
                'lahir' => '1978-06-12', 'tempat_lahir' => 'Semarang', 'kota' => 'murung_raya', 'lokasi' => '15',
                'masuk' => '2005-02-14', 'sk' => '2006-02-14',
                'agama' => 'Islam', 'suku' => 'Jawa', 'pendidikan' => ['S1', 'Teknik Industri', 'Universitas Diponegoro', 2001],
                'nikah' => ['Wulan Sari', '2004-10-10', 'Guru'], 'anak' => ['Bima Susilo', 'Citra Ayu', 'Dafa Pratama'],
            ],
            [ // Permanent, single — PKWT history kept from before the appointment
                'name' => 'Dewi Lestari', 'gender' => 'P', 'jabatan' => 'KDP-FIN',
                'lahir' => '1990-02-03', 'tempat_lahir' => 'Jakarta', 'kota' => 'jakarta', 'lokasi' => '11',
                'masuk' => '2016-04-01', 'sk' => '2018-04-01',
                'pkwt' => [['2016-04-01', '2017-03-31'], ['2017-04-01', '2018-03-31']],
                'agama' => 'Kristen', 'suku' => 'Jawa', 'pendidikan' => ['S1', 'Akuntansi', 'Universitas Trisakti', 2012],
            ],
            [ // Permanent, Kiriman
                'name' => 'Agus Setiawan', 'gender' => 'L', 'jabatan' => 'KDP-MTC',
                'lahir' => '1982-11-21', 'tempat_lahir' => 'Surabaya', 'kota' => 'surabaya', 'lokasi' => '01', 'jenis' => '7',
                'masuk' => '2008-08-01', 'sk' => '2009-08-01',
                'agama' => 'Islam', 'suku' => 'Jawa', 'pendidikan' => ['D3', 'Teknik Mesin', 'Politeknik Negeri Surabaya', 2003],
                'nikah' => ['Lia Kurniasih', '2009-12-12', 'Wiraswasta'], 'anak' => ['Farel Setiawan'],
            ],
            [ // PKWT II running for another 6 months
                'name' => 'Rina Marlina', 'gender' => 'P', 'jabatan' => 'STF-HRG',
                'lahir' => '1998-04-17', 'tempat_lahir' => 'Bandung', 'kota' => 'jakarta', 'lokasi' => '01',
                'pkwt' => $this->periodsEndingOn($t->addMonths(6), [12, 12]),
                'agama' => 'Islam', 'suku' => 'Sunda', 'pendidikan' => ['S1', 'Manajemen SDM', 'Universitas Padjadjaran', 2020],
            ],
            [ // PKWT ends in 19 days
                'name' => 'Fajar Nugroho', 'gender' => 'L', 'jabatan' => 'STF-FIN',
                'lahir' => '1997-08-09', 'tempat_lahir' => 'Yogyakarta', 'kota' => 'jakarta', 'lokasi' => '01',
                'pkwt' => $this->periodsEndingOn($t->addDays(19), [12]),
                'agama' => 'Islam', 'suku' => 'Jawa', 'pendidikan' => ['S1', 'Akuntansi', 'Universitas Gadjah Mada', 2019],
            ],
            [ // PKWT ends in 24 days, married with a toddler
                'name' => 'Yoga Pratama', 'gender' => 'L', 'jabatan' => 'OPR-PRD',
                'lahir' => '1996-01-25', 'tempat_lahir' => 'Balikpapan', 'kota' => 'murung_raya', 'lokasi' => '15',
                'pkwt' => $this->periodsEndingOn($t->addDays(24), [6, 6]),
                'agama' => 'Islam', 'suku' => 'Kutai', 'pendidikan' => ['SMA/SMK', 'Teknik Kendaraan Ringan', 'SMK Negeri 1 Balikpapan', 2014],
                'nikah' => ['Anisa Rahmawati', '2023-06-17', 'Ibu Rumah Tangga'], 'anak' => ['Arkan Pratama'],
            ],
            [ // PKWT ended 26 days ago and was never extended
                'name' => 'Maya Sari', 'gender' => 'P', 'jabatan' => 'OPR-PRD',
                'lahir' => '2000-12-05', 'tempat_lahir' => 'Balikpapan', 'kota' => 'murung_raya', 'lokasi' => '15',
                'pkwt' => $this->periodsEndingOn($t->subDays(26), [12]),
                'agama' => 'Kristen', 'suku' => 'Dayak', 'pendidikan' => ['SMA/SMK', 'Kimia Industri', 'SMK Negeri 2 Balikpapan', 2018],
            ],
            [ // PKWT III ends in 55 days (red in Data Lengkap, not yet in the 1-month list)
                'name' => 'Rudi Hartono', 'gender' => 'L', 'jabatan' => 'MKN-MTC',
                'lahir' => '1988-03-30', 'tempat_lahir' => 'Samarinda', 'kota' => 'tapin', 'lokasi' => '03',
                'pkwt' => $this->periodsEndingOn($t->addDays(55), [12, 12, 12]),
                'agama' => 'Islam', 'suku' => 'Banjar', 'pendidikan' => ['SMA/SMK', 'Teknik Alat Berat', 'SMK Negeri 1 Samarinda', 2006],
                'nikah' => ['Fitri Handayani', '2012-02-14', 'Pedagang'], 'anak' => ['Rafi Hartono', 'Salsa Hartono'],
            ],
            [ // PKWT running, Kiriman, birthday this month
                'name' => 'Indah Permatasari', 'gender' => 'P', 'jabatan' => 'STF-FIN',
                'lahir' => CarbonImmutable::create(1999, $t->month, 8), 'tempat_lahir' => 'Makassar', 'kota' => 'makassar', 'lokasi' => '15',
                'pkwt' => $this->periodsEndingOn($t->addMonths(8), [12]),
                'agama' => 'Islam', 'suku' => 'Bugis', 'pendidikan' => ['S1', 'Manajemen Keuangan', 'Universitas Hasanuddin', 2021],
            ],
            [ // Permanent, retires in 75 days, grown-up children
                'name' => 'Joko Santoso', 'gender' => 'L', 'jabatan' => 'SPV-MTC',
                'lahir' => $t->subYears(55)->addDays(75), 'tempat_lahir' => 'Yogyakarta', 'kota' => 'tapin', 'lokasi' => '03',
                'masuk' => '1998-01-05', 'sk' => '1999-01-05',
                'agama' => 'Katolik', 'suku' => 'Jawa', 'pendidikan' => ['SMA/SMK', 'Mesin Produksi', 'STM Negeri 2 Yogyakarta', 1990],
                'nikah' => ['Sri Lestari', '1996-03-03', 'Ibu Rumah Tangga'], 'anak' => ['Andreas Santoso', 'Maria Santoso', 'Yohanes Santoso'],
            ],
            [ // PKWT running, profile deliberately incomplete
                'name' => 'Lina Agustina', 'gender' => 'P', 'jabatan' => 'OPR-PRD',
                'lahir' => '2002-08-17', 'tempat_lahir' => 'Balikpapan', 'kota' => 'murung_raya', 'lokasi' => '15', 'jenis' => '9',
                'pkwt' => $this->periodsEndingOn($t->addMonths(10), [12]),
                'agama' => 'Islam', 'suku' => 'Banjar', 'pendidikan' => ['SMA/SMK', 'Teknik Produksi', 'SMK Negeri 3 Balikpapan', 2020],
                'incomplete' => true,
            ],
            [ // PKWT II running, Kiriman, married without children
                'name' => 'Dimas Aditya', 'gender' => 'L', 'jabatan' => 'OPR-PRD',
                'lahir' => '1995-10-02', 'tempat_lahir' => 'Medan', 'kota' => 'medan', 'lokasi' => '01',
                'pkwt' => $this->periodsEndingOn($t->addMonths(4), [12, 12]),
                'agama' => 'Kristen', 'suku' => 'Batak', 'pendidikan' => ['D3', 'Teknik Kimia', 'Politeknik Negeri Medan', 2016],
                'nikah' => ['Maria Simanjuntak', '2025-11-15', 'Perawat'],
            ],
            [ // Left the company: resigned 3 months ago
                'name' => 'Wahyu Kurniawan', 'gender' => 'L', 'jabatan' => 'OPR-PRD',
                'lahir' => '1994-07-07', 'tempat_lahir' => 'Balikpapan', 'kota' => 'murung_raya', 'lokasi' => '15',
                'pkwt' => $this->periodsEndingOn($t->addMonths(2), [12, 12]),
                'agama' => 'Islam', 'suku' => 'Kutai', 'pendidikan' => ['SMA/SMK', 'Teknik Mesin', 'SMK Negeri 1 Balikpapan', 2012],
                'keluar' => ['Resign', $t->subMonths(3), 'Mengundurkan diri, pindah ke perusahaan lain'],
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

    protected function createEmployee(int $i, array $e, Jabatan $jabatan, CarbonImmutable $today): void
    {
        $keluar = $e['keluar'] ?? null;
        $pkwt = array_map(
            fn (array $p) => [CarbonImmutable::parse($p[0]), CarbonImmutable::parse($p[1])],
            $e['pkwt'] ?? [],
        );
        $masuk = CarbonImmutable::parse($e['masuk'] ?? $pkwt[0][0]);

        // J LL YY NNN
        $prefix = ($e['jenis'] ?? '1').$e['lokasi'].$masuk->format('y');
        $this->urut[$prefix] = ($this->urut[$prefix] ?? self::FIRST_URUT - 1) + 1;
        $nrp = $prefix.sprintf('%03d', $this->urut[$prefix]);

        $karyawan = Karyawan::create([
            'nrp' => $nrp,
            'name' => $e['name'],
            'email' => Str::of($e['name'])->lower()->replace(' ', '.').'@eloas.local',
            'status' => $keluar ? Karyawan::STATUS_KELUAR : Karyawan::STATUS_AKTIF,
            // Their placement is the one of their position.
            ...JabatanComposer::employeePlacementOf($jabatan),
            'jabatan_id' => $jabatan->id,
            'work_location_id' => $this->workLocations[$this->workLocationOf($jabatan)],
            'tanggal_keluar' => $keluar[1] ?? null,
            'alasan_keluar' => $keluar[0] ?? null,
            'keterangan_keluar' => $keluar[2] ?? null,
        ]);

        $profile = $this->profileAttributes($i, $nrp, $e, $jabatan, $masuk);
        if ($e['incomplete'] ?? false) {
            $profile = Arr::only($profile, self::INCOMPLETE_KEEP);
        }

        $profileModel = $karyawan->profile()->create($profile);

        foreach ($pkwt as $n => [$start, $end]) {
            $profileModel->pkwtPeriods()->create([
                'periode_ke' => $n + 1,
                'tanggal_kontrak' => $start,
                'tanggal_berakhir' => $end,
            ]);
        }

        if (isset($e['nikah']) && ! empty($e['anak'])) {
            $married = CarbonImmutable::parse($e['nikah'][1]);
            foreach ($e['anak'] as $n => $nama) {
                $profileModel->anak()->create($this->childAttributes($n, $nama, $married, $today));
            }
        }
    }

    private function profileAttributes(int $i, string $nrp, array $e, Jabatan $jabatan, CarbonImmutable $masuk): array
    {
        // Old demo levels (1 = top, 5 = staff) from the SubGroup layer (6 = top, 1 = non-staff).
        $level = [6 => 1, 5 => 2, 4 => 3, 3 => 4, 2 => 5, 1 => 5][$jabatan->subGroup->layer];
        $female = $e['gender'] === 'P';
        $lahir = CarbonImmutable::parse($e['lahir']);
        $tetap = isset($e['sk']);
        $nikah = $e['nikah'] ?? null;

        $home = self::REGIONS[$e['kota']];
        $workKey = self::WORK_REGION[$this->workLocationOf($jabatan)];
        // Staff hired elsewhere (Kiriman) live near their workplace; their letters still go to their home town.
        $kiriman = self::LOKASI_WORK[$e['lokasi']] !== $this->workLocationOf($jabatan);
        $tinggal = $kiriman ? self::REGIONS[$workKey] : $home;

        // NIK: region (4) + kecamatan (2) + birth DDMMYY with +40 on the day for women (6) + sequence (4).
        $nik = $home[2].sprintf('%02d', $i % 20 + 1)
            .sprintf('%02d', $lahir->day + ($female ? 40 : 0)).$lahir->format('my')
            .sprintf('%04d', $i + 1);

        $noHp = sprintf('0812%08d', 54120300 + $i * 1117);
        $ayah = self::AYAH[$i % count(self::AYAH)];
        $ibu = self::IBU[$i % count(self::IBU)];
        $isStaff = $level <= 4 || str_starts_with($this->keyOf[$jabatan->id], 'STF');
        $isMekanik = in_array($this->keyOf[$jabatan->id], ['KDP-MTC', 'SPV-MTC', 'MKN-MTC'], true);
        $baju = $female ? ['S', 'M', 'L'][$i % 3] : ['M', 'L', 'XL'][$i % 3];

        return [
            // 1. Identitas & Pekerjaan
            'no_urut' => $i + 1,
            'nrp_sap' => '5'.$nrp, // the full NRP: its first digit is significant, so it cannot be dropped

            'klasifikasi' => $isStaff ? 'Staff' : 'Non Staff',
            'klasifikasi_teknis' => $isMekanik ? 'Mekanik/Nonom' : 'Staff/Operator',

            // 2. Kelahiran & Masa Kerja
            'tempat_lahir' => $e['tempat_lahir'],
            'tanggal_lahir' => $lahir,
            'tanggal_masuk' => $masuk,

            // 3. Status Pegawai & Perkawinan
            'status_pegawai' => $tetap ? 'Tetap' : 'PKWT',
            'status_perkawinan' => $nikah ? 'Menikah' : 'Belum Menikah',
            'tanggal_perkawinan' => $nikah[1] ?? null,

            // 4. Golongan & Hak Normatif
            // Plafon Pengobatan follows from SubGroup, golongan and family (Modul Comben).
            'golongan' => self::GOLONGAN_OF[$jabatan->subGroup->nama] ?? self::GOLONGAN[$jabatan->subGroup->layer],

            // 6. Pengangkatan Pegawai
            'status_pegawai_tetap' => $tetap,
            'nomor_sk' => $tetap ? sprintf('%03d/SK-HRD/%s', $i + 1, substr($e['sk'], 0, 4)) : null,
            'tanggal_sk' => $e['sk'] ?? null,

            // 7. Ukuran Seragam & APD
            'ukuran_baju' => $baju,
            'ukuran_celana' => (string) (($female ? 27 : 30) + ($i % 3) * 2),
            'ukuran_sepatu' => (string) (($female ? 37 : 40) + $i % 3),
            'ukuran_apd' => $baju,

            // 8. Tanda Pengenal & Kepesertaan (individual NPWP = NIK since 2024)
            'nik' => $nik,
            'nik_provinsi' => $home[0],
            'nik_kabupaten_kota' => $home[1],
            'npwp' => $nik,
            'npwp_tanggal_daftar' => $masuk->addMonth(),
            'bpjs_tk' => sprintf('26%09d', 124123 + $i),
            'bpjs_tk_tanggal_daftar' => $masuk,
            'bpjs_kesehatan' => sprintf('0001%09d', 3124123 + $i),

            // 9. Rekening Bank
            'no_rekening' => '14900'.$nrp,
            'nama_bank' => self::BANKS[$i % count(self::BANKS)],
            'nama_pemilik_rekening' => $e['name'],

            // 10. Data Pribadi
            'agama' => $e['agama'],
            'jenis_kelamin' => $female ? 'Perempuan' : 'Laki-laki',
            'suku_bangsa' => $e['suku'],

            // 11. Riwayat Pendidikan
            'pendidikan_terakhir' => $e['pendidikan'][0],
            'jurusan' => $e['pendidikan'][1],
            'asal_sekolah' => $e['pendidikan'][2],
            'tahun_lulus' => $e['pendidikan'][3],

            // 12. Alamat Tinggal
            'alamat_tinggal' => self::STREETS[$i % count(self::STREETS)].' No. '.($i * 7 % 90 + 3),
            'alamat_tinggal_rt' => sprintf('%03d', $i % 9 + 1),
            'alamat_tinggal_rw' => sprintf('%03d', $i % 5 + 2),
            'alamat_tinggal_kab_kota' => $tinggal[1],
            'alamat_tinggal_provinsi' => $tinggal[0],
            'alamat_tinggal_kode_pos' => $tinggal[3],

            // 13. Alamat Surat Menyurat
            'alamat_surat' => self::STREETS[($i + 5) % count(self::STREETS)].' No. '.($i * 11 % 80 + 5),
            'alamat_surat_rt' => sprintf('%03d', ($i + 3) % 9 + 1),
            'alamat_surat_rw' => sprintf('%03d', ($i + 2) % 5 + 1),
            'alamat_surat_kab_kota' => $home[1],
            'alamat_surat_kode_pos' => $home[3],
            'alamat_surat_telepon' => null, // landline, optional
            'alamat_surat_hp' => $noHp,

            // 14. Kontak Darurat & Nomor Pribadi
            'emergency_telepon' => sprintf('0813%08d', 66230100 + $i * 991),
            'emergency_nama' => $nikah[0] ?? $ibu,
            'emergency_hubungan' => $nikah ? ($female ? 'Suami' : 'Istri') : 'Ibu Kandung',
            'no_hp' => $noHp,
            'no_whatsapp' => $noHp,

            // 15. Email
            'email_pribadi' => Str::of($e['name'])->lower()->replace(' ', '.').'@example.com',

            // 16. Keluarga Inti
            'nama_pasangan' => $nikah[0] ?? null,
            'tanggal_lahir_pasangan' => $nikah ? $lahir->addYears($female ? -3 : 2)->addDays(41 * ($i + 1)) : null,
            'pekerjaan_pasangan' => $nikah[2] ?? null,

            // 18. Data Orang Tua
            'nama_ayah' => $ayah,
            'tanggal_lahir_ayah' => $lahir->subYears(29)->subDays(37 * ($i + 1)),
            'nama_ibu' => $ibu,
            'tanggal_lahir_ibu' => $lahir->subYears(25)->subDays(23 * ($i + 1)),
            'ortu_alamat_rt' => sprintf('%03d', ($i + 3) % 9 + 1),
            'ortu_alamat_rw' => sprintf('%03d', ($i + 2) % 5 + 1),
            'ortu_alamat_kab_kota' => $home[1],
            'ortu_alamat_kode_pos' => $home[3],
            'ortu_telepon' => null, // landline, optional
            'ortu_hp' => sprintf('0821%08d', 33410200 + $i * 773),

            // 19. Data Mertua (married employees only)
            'nama_ayah_mertua' => $nikah ? self::AYAH[14 - $i % 15] : null,
            'tanggal_lahir_ayah_mertua' => $nikah ? $lahir->subYears(27)->subDays(19 * ($i + 1)) : null,
            'nama_ibu_mertua' => $nikah ? self::IBU[14 - $i % 15] : null,
            'tanggal_lahir_ibu_mertua' => $nikah ? $lahir->subYears(24)->subDays(29 * ($i + 1)) : null,
            'mertua_alamat_rt' => $nikah ? sprintf('%03d', ($i + 6) % 9 + 1) : null,
            'mertua_alamat_rw' => $nikah ? sprintf('%03d', ($i + 4) % 5 + 1) : null,
            'mertua_alamat_kab_kota' => $nikah ? $tinggal[1] : null,
            'mertua_alamat_kode_pos' => $nikah ? $tinggal[3] : null,
            'mertua_hp' => $nikah ? sprintf('0852%08d', 71150300 + $i * 619) : null,
        ];
    }

    /** Children are born from a year after the wedding, two years apart. */
    private function childAttributes(int $n, string $nama, CarbonImmutable $married, CarbonImmutable $today): array
    {
        $lahir = $married->addYears(1 + 2 * $n)->addMonths(3);
        $age = (int) $lahir->diffInYears($today);

        $pendidikan = match (true) {
            $age < 4 => 'Belum Sekolah',
            $age < 6 => 'TK',
            $age < 12 => 'SD',
            $age < 15 => 'SMP',
            $age < 18 => 'SMA/SMK',
            default => 'S1',
        };

        return [
            'urutan' => $n + 1,
            'nama' => $nama,
            'tanggal_lahir' => $lahir,
            'pendidikan' => $pendidikan,
            'status_bekerja' => $age >= 23 ? 'Bekerja' : 'Belum Bekerja',
            'status_menikah' => $age >= 26 ? 'Menikah' : 'Belum Menikah',
        ];
    }
}
