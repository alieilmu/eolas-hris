<?php

namespace Database\Seeders;

use App\Http\Controllers\Comben\DeklarasiController;
use App\Http\Controllers\Settings\UserController;
use App\Models\CutiBesar;
use App\Models\CutiLapangan;
use App\Models\Deklarasi;
use App\Models\Holiday;
use App\Models\Karyawan;
use App\Models\PengajuanUpdateData;
use App\Models\ShiftCode;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Attendance\TripWriter;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\DeklarasiCheck;
use App\Support\Cuti\Cuti;
use App\Support\Notifikasi\Pengajuan;
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
        $this->call([DatabaseSeeder::class, DemoKaryawanSeeder::class]);

        mt_srand(2026);
        $this->today = CarbonImmutable::today();
        $this->admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();
        $this->codes = ShiftCode::pluck('id', 'kode')->all();
        $this->people = Karyawan::with(['profile.anak', 'jabatan', 'subGroup', 'departemen', 'section.departemen', 'workLocation'])
            ->where('status', Karyawan::STATUS_AKTIF)->get()->keyBy('name')->all();
        // Files of an earlier demo / database are not ours any more.
        Storage::disk('local')->deleteDirectory('lampiran');
        Storage::disk('local')->deleteDirectory('rekrutmen');

        $this->accounts();
        $this->masters();
        $this->rosterAndAbsensi();
        $this->leaveHistory();
        $this->cutiBesar();
        $this->trips();
        $this->deklarasi();
        $this->updateData();
        $this->call(DemoRecruitmentSeeder::class);
    }

    /** Portal accounts for every active employee, ready to use (no forced password change in the demo). */
    private function accounts(): void
    {
        $now = now();
        $rows = array_map(fn (Karyawan $k) => [
            'name' => $k->name, 'email' => null, 'password' => UserController::initialPasswordHash($k), 'role' => User::ROLE_KARYAWAN,
            'karyawan_id' => $k->id, 'is_active' => true, 'must_change_password' => false, 'created_at' => $now, 'updated_at' => $now,
        ], array_values($this->people));
        User::insert($rows);
    }

    /** Field leave allowance per site, and the public holidays of the year. */
    private function masters(): void
    {
        foreach (WorkLocation::where('type', 'Site')->get() as $site) {
            CutiLapangan::create(['work_location_id' => $site->id, 'sub_location_id' => null, 'hari_cuti' => 14, 'hari_berangkat' => 2, 'hari_kembali' => 2]);
        }
        $y = $this->today->year;
        foreach (["{$y}-01-01" => 'Tahun Baru Masehi', "{$y}-05-01" => 'Hari Buruh Internasional', "{$y}-06-01" => 'Hari Lahir Pancasila',
            "{$y}-08-17" => 'Hari Kemerdekaan RI', "{$y}-12-25" => 'Hari Raya Natal'] as $tanggal => $nama) {
            Holiday::create(['tanggal' => $tanggal, 'nama' => $nama]);
        }
    }

    /**
     * Roster from two months back to the end of this month; Absensi up to yesterday, as the
     * roster with a few sick days, permits and absences. Head Office works Monday to Friday,
     * the sites 10 days on (day then night shift) and 2 off, each crew starting elsewhere.
     */
    private function rosterAndAbsensi(): void
    {
        $from = $this->today->subMonths(2)->startOfMonth();
        $to = $this->today->endOfMonth()->startOfDay();
        $holidays = Holiday::pluck('tanggal')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();
        $now = now();
        $roster = [];
        $absensi = [];
        foreach (array_values($this->people) as $i => $k) {
            $site = $k->workLocation?->type === 'Site';
            $masuk = $k->profile?->tanggal_masuk;
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                if ($masuk && $d->lt($masuk)) {
                    continue;
                }
                if ($site) {
                    $step = ((int) $from->diffInDays($d) + $i * 3) % 12;
                    $kode = $step < 5 ? 'D' : ($step < 10 ? 'N' : 'OFF');
                } else {
                    $kode = $d->isWeekend() || isset($holidays[$d->toDateString()]) ? 'OFF' : 'D';
                }
                $row = ['karyawan_id' => $k->id, 'tanggal' => $d->toDateString(), 'created_at' => $now, 'updated_at' => $now];
                $roster[] = $row + ['shift_code_id' => $this->codes[$kode]];
                if ($d->lt($this->today)) {
                    $actual = $kode !== 'OFF' ? match (true) {
                        ($r = mt_rand(1, 1000)) <= 18 => 'S',
                        $r <= 28 => 'I',
                        $r <= 33 => 'A',
                        default => $kode,
                    } : 'OFF';
                    $absensi[] = $row + ['shift_code_id' => $this->codes[$actual], 'sumber' => $actual === $kode ? 'roster' : 'manual'];
                }
            }
        }
        foreach (array_chunk($roster, 500) as $chunk) {
            DB::table('rosters')->insert($chunk);
        }
        foreach (array_chunk($absensi, 500) as $chunk) {
            DB::table('absensis')->insert($chunk);
        }
    }

    /** Annual leave taken before this app (as imported from the old Excel), the last three years. */
    private function leaveHistory(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->people as $k) {
            $masuk = $k->profile?->tanggal_masuk;
            if (! $masuk || $masuk->gt($this->today->subYears(2))) {
                continue;
            }
            foreach ([$this->today->year - 2, $this->today->year - 1, $this->today->year] as $tahun) {
                $last = $tahun === $this->today->year ? 6 : 12; // this year: only before the trips below
                foreach (range(1, mt_rand(1, 3)) as $n) {
                    $awal = CarbonImmutable::create($tahun, mt_rand(1, $last), mt_rand(1, 20));
                    $hari = mt_rand(2, 5);
                    $rows[] = [
                        'karyawan_id' => $k->id, 'jenis' => 'tahunan', 'tahun' => $tahun,
                        'tanggal_awal' => $awal->toDateString(), 'tanggal_akhir' => $awal->addDays($hari - 1)->toDateString(),
                        'hari' => $hari, 'sumber' => 'import', 'catatan' => $n === 1 && $tahun < $this->today->year ? 'Riwayat dari Excel lama' : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }
        DB::table('cuti_pengambilans')->insert($rows);
    }

    /** Choices for the Cuti Besar reached: cashed in, taken, and one sent from the portal. */
    private function cutiBesar(): void
    {
        $decided = ['status' => 'disetujui', 'sumber' => 'hr', 'verified_by' => $this->admin->id, 'verified_at' => now()];
        // Hendra Wijaya: the first three cashed in, the 4th passed its deadline, the 5th (this year) taken.
        foreach ([1, 2, 3] as $n) {
            CutiBesar::create(['karyawan_id' => $this->k('Hendra Wijaya')->id, 'periode_ke' => $n, 'keputusan' => 'diuangkan'] + $decided);
        }
        $this->reached('Hendra Wijaya', 'diambil', $decided);
        $this->reached('Dewi Lestari', 'diuangkan', $decided);
        // Eko Prasetyo chose from the portal; HR has not checked it yet.
        if ($choice = $this->reached('Eko Prasetyo', 'diambil', ['status' => 'diajukan', 'sumber' => 'portal'])) {
            Pengajuan::cutiBesar($choice);
        }
    }

    /** Records $keputusan for the employee's open Cuti Besar (reached, before its deadline). */
    private function reached(string $name, string $keputusan, array $attributes): ?CutiBesar
    {
        $k = $this->k($name);
        $open = collect(Cuti::summary($k)['besar']['periode'])->firstWhere('status', 'belum_dipilih');

        return $open ? CutiBesar::create(['karyawan_id' => $k->id, 'periode_ke' => $open['periode_ke'], 'keputusan' => $keputusan] + $attributes) : null;
    }

    /** Dinas and cuti around today: done, running, coming, and waiting for HR. */
    private function trips(): void
    {
        $t = $this->today;
        $day = fn (int $offset) => $t->addDays($offset)->toDateString();
        $hr = ['status' => 'disetujui', 'sumber' => 'hr'];
        $portal = ['status' => 'diajukan', 'sumber' => 'portal'];

        // Dinas
        $this->trip('Bambang Susilo', 'dinas', $day(-51), $day(-48), 'Puruk Cahu', 'Jakarta', $hr + [
            'keterangan' => 'Rapat evaluasi produksi kuartal III di Head Office',
            'tiket_pesawat' => true, 'bandara_asal' => 'BDJ', 'bandara_tujuan' => 'CGK', 'pesawat_pp' => true,
            'pulang_tanggal' => $day(-48), 'pulang_bandara_asal' => 'CGK', 'pulang_bandara_tujuan' => 'BDJ', 'status_uang' => 'Selesai',
            'uang_muka' => true, 'uang_muka_nominal' => 4500000,
        ]);
        $this->trip('Siti Rahmawati', 'dinas', $day(-31), $day(-27), 'Jakarta', 'Puruk Cahu', $hr + [
            'keterangan' => 'Sosialisasi peraturan perusahaan & audit administrasi personalia site',
            'tiket_pesawat' => true, 'bandara_asal' => 'CGK', 'bandara_tujuan' => 'BDJ', 'pesawat_pp' => true,
            'pulang_tanggal' => $day(-27), 'pulang_bandara_asal' => 'BDJ', 'pulang_bandara_tujuan' => 'CGK',
            'uang_muka' => true, 'uang_muka_nominal' => 3500000, 'status_uang' => 'Selesai',
        ]);
        $this->trip('Joko Santoso', 'dinas', $day(-16), $day(-15), 'Rantau', 'Banjarmasin', $hr + ['keterangan' => 'Pengambilan sparepart & koordinasi vendor']);
        $this->trip('Agus Setiawan', 'dinas', $day(-2), $day(2), 'Rantau', 'Balikpapan', $hr + [
            'keterangan' => 'Training overhaul engine di workshop principal',
            'tiket_pesawat' => true, 'bandara_asal' => 'BDJ', 'bandara_tujuan' => 'BPN', 'uang_muka' => true, 'uang_muka_nominal' => 2000000, 'status_uang' => 'Dicairkan',
        ]);
        $this->trip('Dewi Lestari', 'dinas', $day(11), $day(13), 'Jakarta', 'Surabaya', $hr + [
            'keterangan' => 'Gambir → Surabaya Pasar Turi; audit pajak cabang', 'tiket_kereta' => true,
            'uang_muka' => true, 'uang_muka_nominal' => 2500000, 'status_uang' => 'Disetujui',
        ]);
        // Cuti
        $this->trip('Ahmad Fauzi', 'cuti', $day(-59), $day(-42), 'Puruk Cahu', 'Banjarmasin', $hr + ['kategori_cuti' => 'lapangan']);
        $this->trip('Rendi Gunawan', 'cuti', $day(-45), $day(-28), 'Rantau', 'Tanjung', $hr + ['kategori_cuti' => 'lapangan']);
        $this->trip('Hendra Wijaya', 'cuti', $day(-66), $day(-47), 'Puruk Cahu', 'Samarinda', $hr + ['kategori_cuti' => 'besar', 'keterangan' => 'Cuti Besar periode ke-5']);
        $this->trip('Lukman Hakim', 'cuti', $day(-24), $day(-5), 'Puruk Cahu', 'Kandangan', $hr + ['kategori_cuti' => 'lapangan', 'keterangan' => '2 hari tambahan dari cuti tahunan']);
        $this->trip('Winda Kartika', 'cuti', $day(-10), $day(-8), 'Jakarta', 'Jakarta', $hr + ['kategori_cuti' => 'tahunan', 'keterangan' => 'Keperluan keluarga']);
        $this->trip('Eko Prasetyo', 'cuti', $day(-7), $day(10), 'Puruk Cahu', 'Surabaya', $hr + ['kategori_cuti' => 'lapangan']);
        $this->trip('Yudi Hermawan', 'cuti', $day(4), $day(6), 'Jakarta', 'Bandung', $hr + ['kategori_cuti' => 'tahunan']);

        // Sent from the portal, waiting for HR.
        foreach ([
            ['Gilang Ramadhan', 'cuti', 18, 35, 'Puruk Cahu', 'Palangka Raya', $portal + ['kategori_cuti' => 'lapangan']],
            ['Mario Tambunan', 'cuti', 25, 29, 'Rantau', 'Medan', $portal + ['kategori_cuti' => 'tahunan', 'keterangan' => 'Pernikahan adik',
                'tiket_pesawat' => true, 'bandara_asal' => 'BDJ', 'bandara_tujuan' => 'KNO', 'pesawat_pp' => true,
                'pulang_tanggal' => $day(29), 'pulang_bandara_asal' => 'KNO', 'pulang_bandara_tujuan' => 'BDJ']],
            ['Fajar Nugroho', 'dinas', 19, 21, 'Jakarta', 'Surabaya', $portal + ['keterangan' => 'Rekonsiliasi piutang dengan customer',
                'tiket_pesawat' => true, 'bandara_asal' => 'CGK', 'bandara_tujuan' => 'SUB', 'pesawat_pp' => true,
                'pulang_tanggal' => $day(21), 'pulang_bandara_asal' => 'SUB', 'pulang_bandara_tujuan' => 'CGK',
                'uang_muka' => true, 'uang_muka_nominal' => 1500000, 'status_uang' => 'Diajukan']],
            ['Rudi Hartono', 'dinas', 7, 8, 'Rantau', 'Banjarmasin', $portal + ['keterangan' => 'Pengujian alat ukur di laboratorium kalibrasi']],
        ] as [$name, $jenis, $a, $b, $asal, $tujuan, $extra]) {
            Pengajuan::trip($this->trip($name, $jenis, $day($a), $day($b), $asal, $tujuan, $extra));
        }
    }

    private function trip(string $name, string $jenis, string $awal, string $akhir, string $asal, string $tujuan, array $extra): Trip
    {
        $k = $this->k($name);
        $approved = $extra['status'] === 'disetujui';
        $hari = (int) CarbonImmutable::parse($awal)->diffInDays(CarbonImmutable::parse($akhir)) + 1;
        $cuti = $jenis === 'cuti' ? ['hari_cuti' => $hari, ...Cuti::potongan($k, $extra['kategori_cuti'], $hari)] : [];
        $user = User::where('karyawan_id', $k->id)->first();

        $trip = Trip::create([
            'karyawan_id' => $k->id, 'jenis' => $jenis, 'tanggal_awal' => $awal, 'tanggal_akhir' => $akhir, 'kota_asal' => $asal, 'kota_tujuan' => $tujuan,
            'status_uang' => $jenis === 'dinas' ? ($extra['status_uang'] ?? 'Belum diajukan') : null,
            'created_by' => $approved ? $this->admin->id : $user?->id,
            'verified_by' => $approved ? $this->admin->id : null,
            'verified_at' => $approved ? now() : null,
            ...$cuti,
            ...$extra,
        ]);
        $this->attach($trip, 'trip', $jenis === 'cuti' ? 'FORMULIR PERMOHONAN CUTI' : 'SURAT PERINTAH PERJALANAN DINAS', [
            "Nama      : {$k->name}",
            "NRP       : {$k->nrp}",
            'Jabatan   : '.($k->jabatan?->name ?? '-'),
            'Tanggal   : '.CarbonImmutable::parse($awal)->format('d.m.Y').' s/d '.CarbonImmutable::parse($akhir)->format('d.m.Y')." ({$hari} hari)",
            "Dari      : {$asal}",
            "Tujuan    : {$tujuan}",
            'Keperluan : '.Str::limit($extra['keterangan'] ?? ($jenis === 'cuti' ? Cuti::KATEGORI[$extra['kategori_cuti']] : 'Perjalanan dinas'), 46),
            '',
            $approved ? '[ DISETUJUI ]  Atasan & HR' : '[ Ditandatangani atasan langsung ]',
        ], $user?->id ?? $this->admin->id);
        TripWriter::fill($trip);

        return $trip;
    }

    /**
     * Deklarasi of this plafon year: claims typed by HR from the signed form, a few glasses,
     * and three sent from the portal that HR has to check. Each nota is an image.
     */
    private function deklarasi(): void
    {
        $tempat = ['Head Office' => ['Klinik Medika Jakarta', 'Apotek Sehat Jakarta'], 'Site Murung Raya' => ['Klinik Site Murung Raya', 'Apotek Puruk Cahu Farma'], 'Site Rantau' => ['Klinik Rantau Sehat', 'Apotek Tapin Farma']];
        $start = CarbonImmutable::create($this->today->year, 1, 9);
        foreach ($this->people as $k) {
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $tanggal = $start->addDays(mt_rand(0, (int) $start->diffInDays($this->today->subDays(3))));
                $penerima = $this->penerima($k);
                $items = [['jenis' => mt_rand(1, 6) === 1 ? 'perawatan' : 'pengobatan', ...$penerima, 'nominal' => mt_rand(8, 60) * 10000]];
                $this->claim($k, $tanggal, $items, $tempat[$k->workLocation?->name][mt_rand(0, 1)] ?? null, false);
            }
        }
        // Glasses.
        foreach (['Joko Santoso' => [650000, 600000], 'Siti Rahmawati' => [800000, 750000], 'Mario Tambunan' => [450000, 400000]] as $name => [$bingkai, $lensa]) {
            $this->claim($this->k($name), $this->today->subDays(mt_rand(20, 200)), [
                ['jenis' => 'bingkai', 'penerima' => 'karyawan', 'karyawan_anak_id' => null, 'nominal' => $bingkai],
                ['jenis' => 'lensa', 'penerima' => 'karyawan', 'karyawan_anak_id' => null, 'nominal' => $lensa],
            ], 'Optik Mata Prima', false);
        }
        // From the portal, waiting for HR.
        foreach ([
            ['Yoga Pratama', [['jenis' => 'pengobatan', 'penerima' => 'anak', 'nominal' => 275000]], 'Klinik Site Murung Raya'],
            ['Rina Marlina', [['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 185000]], 'Klinik Medika Jakarta'],
            ['Deni Kurniawan', [['jenis' => 'pengobatan', 'penerima' => 'pasangan', 'nominal' => 320000], ['jenis' => 'pengobatan', 'penerima' => 'karyawan', 'nominal' => 95000]], 'Apotek Puruk Cahu Farma'],
        ] as [$name, $items, $tempatPortal]) {
            $k = $this->k($name);
            $items = array_map(fn (array $i) => $i + ['karyawan_anak_id' => $i['penerima'] === 'anak' ? $k->profile?->anak->first()?->id : null], $items);
            if ($d = $this->claim($k, $this->today->subDays(mt_rand(1, 4)), $items, $tempatPortal, true)) {
                Pengajuan::deklarasi($d);
            }
        }
    }

    /** Who a claim is for: the employee mostly, sometimes the spouse or a child. */
    private function penerima(Karyawan $k): array
    {
        $anak = $k->profile?->anak ?? collect();
        $r = mt_rand(1, 10);
        if ($r <= 2 && $k->profile?->nama_pasangan) {
            return ['penerima' => 'pasangan', 'karyawan_anak_id' => null];
        }
        if ($r <= 4 && $anak->isNotEmpty()) {
            return ['penerima' => 'anak', 'karyawan_anak_id' => $anak->random()->id];
        }

        return ['penerima' => 'karyawan', 'karyawan_anak_id' => null];
    }

    /** One deklarasi checked against the plafon (skipped when it would go over), one nota image per amount. */
    private function claim(Karyawan $k, CarbonImmutable $tanggal, array $items, ?string $tempat, bool $portal): ?Deklarasi
    {
        $bulan = BukuBulan::of($tanggal);
        $tahun = BukuBulan::tahun($bulan);
        if (DeklarasiCheck::errors($k, $items, $tahun)) {
            return null;
        }
        $user = User::where('karyawan_id', $k->id)->first();
        $d = Deklarasi::create([
            'nomor' => DeklarasiController::nextNomor($bulan), 'karyawan_id' => $k->id, 'tanggal' => $tanggal->toDateString(), 'tempat' => $tempat,
            'jumlah_kwitansi' => count($items), 'bulan_buku' => $bulan, 'tahun_plafon' => $tahun,
            'created_by' => $portal ? $user?->id : $this->admin->id,
            'status' => $portal ? 'diajukan' : 'disetujui', 'sumber' => $portal ? 'portal' : 'hr',
            'verified_by' => $portal ? null : $this->admin->id, 'verified_at' => $portal ? null : now(),
        ]);
        $d->items()->createMany($items);
        foreach ($items as $n => $item) {
            $untuk = match ($item['penerima']) {
                'pasangan' => $k->profile?->nama_pasangan ?? 'Istri / Suami',
                'anak' => $k->profile?->anak->firstWhere('id', $item['karyawan_anak_id'])?->nama ?? 'Anak',
                default => $k->name,
            };
            $this->attach($d, 'deklarasi', 'KWITANSI / NOTA', [
                strtoupper($tempat ?? 'Klinik'),
                'No.        : KW-'.$tanggal->format('ymd').'-'.sprintf('%03d', $d->id * 3 + $n),
                'Tanggal    : '.$tanggal->format('d.m.Y'),
                "Pasien     : {$untuk}",
                'Untuk      : '.match ($item['jenis']) {
                    'bingkai' => 'Bingkai kacamata', 'lensa' => 'Lensa kacamata', 'perawatan' => 'Biaya perawatan', default => 'Konsultasi dokter & obat',
                },
                '',
                'JUMLAH     : Rp '.number_format($item['nominal'], 0, ',', '.'),
                '',
                '[ LUNAS ]',
            ], $portal ? ($user?->id ?? $this->admin->id) : $this->admin->id);
        }

        return $d;
    }

    /** Two portal requests to change own data, and one HR already approved. */
    private function updateData(): void
    {
        $now = now();
        foreach ([
            ['Ferdi Saputra', ['no_hp' => '081351234567', 'alamat_tinggal' => 'Jl. Tjilik Riwut Km. 5 No. 12'], 'Ganti nomor HP & pindah kontrakan', 'diajukan'],
            ['Vina Oktaviani', ['email_pribadi' => 'vina.oktaviani@gmail.com', 'no_whatsapp' => '085712349876'], null, 'diajukan'],
            ['Kevin Sitorus', ['no_hp' => '082154329876'], 'Nomor lama hilang', 'disetujui'],
        ] as [$name, $after, $catatan, $status]) {
            $k = $this->k($name);
            $perubahan = [];
            foreach ($after as $field => $value) {
                $perubahan[$field] = ['before' => $k->profile?->{$field}, 'after' => $value];
            }
            $p = PengajuanUpdateData::create([
                'karyawan_id' => $k->id, 'perubahan' => $perubahan, 'anak' => [], 'catatan' => $catatan, 'status' => $status,
                'created_by' => User::where('karyawan_id', $k->id)->value('id'),
                'verified_by' => $status === 'disetujui' ? $this->admin->id : null, 'verified_at' => $status === 'disetujui' ? $now : null,
            ]);
            if ($status === 'disetujui') {
                $k->profile->update($after);
            } else {
                Pengajuan::updateData($p);
            }
        }
    }

    private function k(string $name): Karyawan
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

        $path = "lampiran/{$folder}/{$owner->getKey()}/".Str::uuid().'.png';
        Storage::disk('local')->put($path, $png);
        $owner->lampirans()->create(['path' => $path, 'nama' => Str::slug($title).'.png', 'mime' => 'image/png', 'ukuran' => strlen($png), 'halaman' => 1, 'created_by' => $by]);
    }
}
