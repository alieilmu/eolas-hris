<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\Lampiran;
use App\Models\SubLocation;
use App\Models\Trip;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Bandara;
use App\Support\Attendance\Onsite;
use App\Support\Attendance\Period;
use App\Support\Attendance\Roll;
use App\Support\Attendance\TripWriter;
use App\Support\Cuti\Cuti;
use App\Support\Excel\TableExcel;
use App\Support\Karyawan\MasterOptions;
use App\Support\Lampiran\Attachments;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trip & Leave: business trips (dinas) and leave (cuti). Each entry fills its
 * days in Absensi, and with it the Man Onsite chart; the Pengajuan Dinas list
 * follows each trip and its money request.
 */
class TripController extends Controller
{
    public const STATUS_UANG = 'status_uang';

    public function index(Request $request): Response
    {
        $period = Period::from($request->query('periode'));
        $lokasi = $request->integer('lokasi') ?: null;
        $sub = Roll::subLocationFilter($request->query('sub'));
        $today = CarbonImmutable::today();

        return Inertia::render('Attendance/TripLeave', [
            'period' => $period->toArray(),
            'periods' => Period::options(),
            'lokasi' => $lokasi,
            'sub' => $sub === null ? '' : (string) $sub,
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'can_edit' => $request->user()->canEdit('trip-leave'),
            'daily' => Onsite::daily($period->start, $period->end(), $lokasi, $sub),
            'today' => Onsite::daily($today, $today, $lokasi, $sub)[0],
            'away' => Onsite::awayToday($lokasi, $today, $sub),
            'people' => self::people(),
            'cities' => self::cities(),
            'bandara' => Bandara::options(),
            'lampiran_limits' => Attachments::limits(),
        ]);
    }

    /** Pengajuan Dinas: every trip with where it stands today and its money request, 10 per page. */
    public function list(Request $request): Response
    {
        $filters = $request->validate([
            'jenis' => ['nullable', Rule::in(['dinas', 'cuti', 'semua'])],
            'perjalanan' => ['nullable', Rule::in(['akan', 'berjalan', 'selesai'])],
            'status' => ['nullable', Rule::in(Trip::STATUS)],
            'uang' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $jenis = $filters['jenis'] ?? 'dinas';
        $today = CarbonImmutable::today();
        $d = $today->toDateString();

        $trips = Trip::with(['karyawan.jabatan:id,name', 'karyawan.workLocation:id,name', 'lampirans'])
            ->when($jenis !== 'semua', fn ($q) => $q->where('jenis', $jenis))
            ->when($filters['perjalanan'] ?? null, fn ($q, $p) => match ($p) {
                'akan' => $q->whereDate('tanggal_awal', '>', $d),
                'selesai' => $q->whereDate('tanggal_akhir', '<', $d),
                default => $q->whereDate('tanggal_awal', '<=', $d)->whereDate('tanggal_akhir', '>=', $d),
            })
            ->when($filters['uang'] ?? null, fn ($q, $s) => $q->where('status_uang', $s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereHas('karyawan', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('nrp', 'like', "%{$s}%"))
                ->orWhere('kota_tujuan', 'like', "%{$s}%")
                ->orWhere('kota_asal', 'like', "%{$s}%")))
            // Waiting for HR first.
            ->orderByRaw("case when status = 'diajukan' then 0 else 1 end")
            ->orderByDesc('tanggal_awal')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Trip $t) => self::tripData($t, $today));

        return Inertia::render('Attendance/Pengajuan', [
            'trips' => $trips,
            'filters' => ['jenis' => $jenis, 'perjalanan' => $filters['perjalanan'] ?? '', 'uang' => $filters['uang'] ?? '', 'status' => $filters['status'] ?? '', 'q' => $filters['q'] ?? ''],
            // Pengajuan from the portal waiting for HR, per jenis.
            'menunggu' => Trip::where('status', 'diajukan')->selectRaw('jenis, count(*) as n')->groupBy('jenis')->pluck('n', 'jenis'),
            'can_edit' => $request->user()->canEdit('pengajuan-dinas'),
            'status_uang' => MasterOptions::values(self::STATUS_UANG),
            'people' => self::people(),
            'cities' => self::cities(),
            'bandara' => Bandara::options(),
            'lampiran_limits' => Attachments::limits(),
        ]);
    }

    /**
     * Trips and leave in Excel: from Trip & Leave for a range of months (those touching it),
     * or from Pengajuan Dinas with the filters of the list.
     */
    public function export(Request $request): StreamedResponse
    {
        $f = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m'],
            'sampai' => ['nullable', 'date_format:Y-m'],
            'jenis' => ['nullable', Rule::in(['dinas', 'cuti', 'semua'])],
            'perjalanan' => ['nullable', Rule::in(['akan', 'berjalan', 'selesai'])],
            'status' => ['nullable', Rule::in(Trip::STATUS)],
            'uang' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'lokasi' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
        ]);
        $today = CarbonImmutable::today();
        $d = $today->toDateString();
        $months = ($f['dari'] ?? null) ? Period::range($f['dari'], $f['sampai'] ?? $f['dari'], 36) : null;
        $from = $months ? $months[0]->start : null;
        $to = $months ? end($months)->end() : null;
        $jenis = $f['jenis'] ?? 'semua';
        $sub = Roll::subLocationFilter($f['sub'] ?? null);

        $trips = Trip::with(['karyawan.jabatan:id,name', 'karyawan.workLocation:id,name', 'karyawan.subLocation:id,name', 'karyawan.departemen', 'karyawan.section.departemen'])
            ->when($months, fn ($q) => $q->whereDate('tanggal_awal', '<=', $to->toDateString())->whereDate('tanggal_akhir', '>=', $from->toDateString()))
            ->when($jenis !== 'semua', fn ($q) => $q->where('jenis', $jenis))
            ->when($f['perjalanan'] ?? null, fn ($q, $p) => match ($p) {
                'akan' => $q->whereDate('tanggal_awal', '>', $d),
                'selesai' => $q->whereDate('tanggal_akhir', '<', $d),
                default => $q->whereDate('tanggal_awal', '<=', $d)->whereDate('tanggal_akhir', '>=', $d),
            })
            ->when($f['uang'] ?? null, fn ($q, $st) => $q->where('status_uang', $st))
            ->when($f['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->when($f['lokasi'] ?? null, fn ($q, $l) => $q->whereHas('karyawan', fn ($k) => $k->where('work_location_id', $l)))
            ->when($sub !== null, fn ($q) => $q->whereHas('karyawan', fn ($k) => $sub === Roll::NO_SUB_LOCATION ? $k->whereNull('sub_location_id') : $k->where('sub_location_id', $sub)))
            ->when(trim($f['q'] ?? ''), fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereHas('karyawan', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('nrp', 'like', "%{$s}%"))
                ->orWhere('kota_tujuan', 'like', "%{$s}%")
                ->orWhere('kota_asal', 'like', "%{$s}%")))
            ->orderBy('tanggal_awal')
            ->orderBy('id')
            ->get();

        $route = fn (?string $a, ?string $b) => $a || $b ? ($a ?? '?').' → '.($b ?? '?') : null;
        $rows = $trips->values()->map(fn (Trip $t, int $i) => [
            $i + 1,
            Trip::JENIS[$t->jenis] ?? $t->jenis,
            $t->karyawan->nrp,
            $t->karyawan->name,
            $t->karyawan->jabatan?->name,
            $t->karyawan->placementDepartemen()?->name,
            $t->karyawan->workLocation?->name,
            $t->karyawan->subLocation?->name,
            $t->tanggal_awal->toDateString(),
            $t->tanggal_akhir->toDateString(),
            $t->lamaHari(),
            $t->kota_asal,
            $t->kota_tujuan,
            $t->kategori_cuti ? (Cuti::KATEGORI[$t->kategori_cuti] ?? $t->kategori_cuti) : null,
            $t->hari_cuti,
            $t->potong_tahunan ?: null,
            $t->potong_besar ?: null,
            $t->tiket_pesawat ? $route($t->bandara_asal, $t->bandara_tujuan) : null,
            $t->tiket_pesawat && $t->pesawat_pp ? $t->pulang_tanggal?->toDateString() : null,
            $t->tiket_pesawat && $t->pesawat_pp ? $route($t->pulang_bandara_asal, $t->pulang_bandara_tujuan) : null,
            $t->tiket_kereta ? 'Ya' : null,
            $t->uang_muka ? $t->uang_muka_nominal : null,
            $t->status_uang,
            ['akan' => 'Akan berangkat', 'berjalan' => 'Berjalan', 'selesai' => 'Selesai'][$t->statusPerjalanan($today)],
            ucfirst($t->status),
            ['hr' => 'HR', 'portal' => 'Portal'][$t->sumber] ?? $t->sumber,
            $t->keterangan,
            $t->catatan_verifikasi,
        ]);

        $name = $months ? $from->format('Y-m').($from->format('Y-m') !== $to->format('Y-m') ? '-sd-'.$to->format('Y-m') : '') : 'semua';

        return TableExcel::download("trip-leave-{$name}-".$today->format('Y-m-d').'.xlsx', [[
            'title' => 'Trip & Leave',
            'info' => TableExcel::info([
                $months ? 'Periode '.$from->format('d.m.Y').' – '.$to->format('d.m.Y') : 'Semua tanggal',
                $jenis === 'semua' ? 'Dinas & cuti' : Trip::JENIS[$jenis],
                ($f['lokasi'] ?? null) ? 'Lokasi '.WorkLocation::whereKey($f['lokasi'])->value('name') : null,
                match (true) {
                    $sub === Roll::NO_SUB_LOCATION => 'Tanpa sub lokasi',
                    is_int($sub) => 'Sub lokasi '.SubLocation::whereKey($sub)->value('name'),
                    default => null,
                },
                ($f['perjalanan'] ?? null) ? 'Perjalanan '.$f['perjalanan'] : null,
                ($f['status'] ?? null) ? 'Status '.$f['status'] : null,
                ($f['uang'] ?? null) ? 'Status uang '.$f['uang'] : null,
                trim($f['q'] ?? '') !== '' ? 'Cari "'.trim($f['q']).'"' : null,
                $trips->count().' data',
            ]),
            'freeze' => 4,
            'columns' => [
                ['No', 5, 'int'], ['Jenis', 8], ['NRP', 12], ['Nama', 28], ['Jabatan', 26], ['Departemen', 22], ['Lokasi', 16], ['Sub Lokasi', 16],
                ['Berangkat / Mulai', 12, 'date'], ['Kembali / Selesai', 12, 'date'], ['Lama (hari)', 8, 'int', 'sum' => true],
                ['Kota Asal', 16], ['Kota Tujuan', 16], ['Kategori Cuti', 22], ['Hari Cuti', 7, 'int', 'sum' => true],
                ['Potong Tahunan', 9, 'int', 'sum' => true], ['Potong Besar', 9, 'int', 'sum' => true],
                ['Tiket Pesawat Pergi', 16], ['Tgl Pulang (PP)', 12, 'date'], ['Tiket Pesawat Pulang', 16], ['Tiket Kereta', 8],
                ['Uang Muka', 14, 'rupiah', 'sum' => true], ['Status Uang', 18], ['Perjalanan', 14], ['Status', 11], ['Sumber', 8],
                ['Keterangan', 32], ['Catatan Verifikasi', 28],
            ],
            'rows' => $rows,
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = self::validateTrip($request);
        TripWriter::assertFree($data['karyawan_id'], $data['tanggal_awal'], $data['tanggal_akhir']);
        TripWriter::codeFor($data['jenis']);

        $trip = DB::transaction(function () use ($data, $request) {
            // Entered by HR: approved as it is, so it fills Absensi straight away.
            $trip = Trip::create([
                ...$data,
                'status_uang' => self::statusUangAwal($data['uang_muka']),
                'created_by' => $request->user()->id,
                'status' => 'disetujui',
                'sumber' => 'hr',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);
            TripWriter::fill($trip);
            Attachments::sync($request, $trip, 'trip');

            return $trip;
        });
        Inertia::flash('success', Trip::JENIS[$trip->jenis].' '.$trip->karyawan->name.' ke '.$trip->kota_tujuan.' disimpan; Absensi '.$trip->lamaHari().' hari ikut terisi.');

        return back();
    }

    public function update(Request $request, Trip $trip): RedirectResponse
    {
        $data = self::validateTrip($request, $trip);
        TripWriter::assertFree($data['karyawan_id'], $data['tanggal_awal'], $data['tanggal_akhir'], $trip);
        TripWriter::codeFor($data['jenis']);

        // Uang muka asked now on a trip whose money was not requested yet: it is requested.
        if ($data['uang_muka'] && ! $trip->uang_muka && $trip->status_uang === (MasterOptions::values(self::STATUS_UANG)[0] ?? null)) {
            $data['status_uang'] = self::statusUangAwal(true);
        }
        DB::transaction(function () use ($trip, $data, $request) {
            $trip->update($data);
            TripWriter::fill($trip->refresh());
            Attachments::sync($request, $trip, 'trip');
        });
        Inertia::flash('success', 'Data '.Trip::JENIS[$trip->jenis].' '.$trip->karyawan->name.' diperbarui.');

        return back();
    }

    public function destroy(Trip $trip): RedirectResponse
    {
        $trip->delete(); // its Absensi days go with it
        Inertia::flash('success', 'Data '.Trip::JENIS[$trip->jenis].' '.$trip->karyawan->name.' dihapus; hari-harinya di Absensi ikut dikosongkan.');

        return back();
    }

    /** Approves (fills Absensi) or rejects (with a note) a cuti / dinas from the employee portal. */
    public function verify(Request $request, Trip $trip): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak'],
        ], ['catatan.required_if' => 'Tulis alasan penolakan.']);
        if ($trip->status !== 'diajukan') {
            throw ValidationException::withMessages(['keputusan' => 'Pengajuan ini sudah diverifikasi.']);
        }
        $approve = $data['keputusan'] === 'setujui';
        if ($approve) {
            TripWriter::codeFor($trip->jenis);
        }
        DB::transaction(function () use ($trip, $approve, $data, $request) {
            $trip->update([
                'status' => $approve ? 'disetujui' : 'ditolak',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'catatan_verifikasi' => $data['catatan'] ?? null,
            ]);
            TripWriter::fill($trip);
        });
        Inertia::flash('success', Trip::JENIS[$trip->jenis].' '.$trip->karyawan->name.' '.($approve ? 'disetujui; Absensi ikut terisi.' : 'ditolak.'));

        return back();
    }

    public function statusUang(Request $request, Trip $trip): RedirectResponse
    {
        $data = $request->validate([
            'status_uang' => ['required', Rule::in(MasterOptions::values(self::STATUS_UANG))],
        ], [], ['status_uang' => 'Status Pengajuan Uang']);
        $trip->update($data);
        Inertia::flash('success', "Status uang {$trip->karyawan->name}: {$data['status_uang']}.");

        return back();
    }

    /** The first money status: "Diajukan" when uang muka is asked (if that status exists), else the first one. */
    public static function statusUangAwal(bool $uangMuka): ?string
    {
        $options = MasterOptions::values(self::STATUS_UANG);

        return $uangMuka && in_array('Diajukan', $options, true) ? 'Diajukan' : ($options[0] ?? null);
    }

    /** A new trip is for an active employee; an existing one keeps its employee even after they left. */
    public static function validateTrip(Request $request, ?Trip $current = null): array
    {
        $keepsEmployee = $current && (int) $request->input('karyawan_id') === $current->karyawan_id;
        $dinas = $request->input('jenis') === 'dinas';
        $pp = $request->boolean('tiket_pesawat') && $request->boolean('pesawat_pp');
        $data = $request->validate([
            'jenis' => ['required', Rule::in(array_keys(Trip::JENIS))],
            'kategori_cuti' => ['nullable', 'required_if:jenis,cuti', Rule::in(array_keys(Cuti::KATEGORI))],
            'karyawan_id' => ['required', 'integer', $keepsEmployee ? Rule::exists('karyawans', 'id') : Rule::exists('karyawans', 'id')->where('status', Karyawan::STATUS_AKTIF)],
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'kota_asal' => ['required', 'string', 'max:100'],
            'kota_tujuan' => ['required', 'string', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:255', Rule::requiredIf($dinas && $request->boolean('tiket_kereta'))],
            'tiket_pesawat' => ['nullable', 'boolean'],
            'bandara_asal' => ['nullable', 'required_if_accepted:tiket_pesawat', Rule::in(Bandara::codes())],
            'bandara_tujuan' => ['nullable', 'required_if_accepted:tiket_pesawat', Rule::in(Bandara::codes()), 'different:bandara_asal'],
            'pesawat_pp' => ['nullable', 'boolean'],
            'pulang_tanggal' => ['nullable', Rule::requiredIf($pp), 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'pulang_bandara_asal' => ['nullable', Rule::requiredIf($pp), Rule::in(Bandara::codes())],
            'pulang_bandara_tujuan' => ['nullable', Rule::requiredIf($pp), Rule::in(Bandara::codes()), 'different:pulang_bandara_asal'],
            'tiket_kereta' => ['nullable', 'boolean'],
            'uang_muka' => ['nullable', 'boolean'],
            'uang_muka_nominal' => ['nullable', Rule::requiredIf($dinas && $request->boolean('uang_muka')), 'integer', 'min:1', 'max:999999999'],
            ...Attachments::rules(),
        ], [
            'karyawan_id.exists' => 'NRP tidak ditemukan di karyawan aktif.',
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'kategori_cuti.required_if' => 'Pilih jenis cuti.',
            'bandara_asal.required_if_accepted' => 'Pilih bandara keberangkatan.',
            'bandara_tujuan.required_if_accepted' => 'Pilih bandara tujuan.',
            'bandara_tujuan.different' => 'Bandara tujuan harus berbeda dari bandara keberangkatan.',
            'bandara_asal.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'bandara_tujuan.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'pulang_tanggal.required' => 'Isi tanggal penerbangan pulang.',
            'pulang_tanggal.after_or_equal' => 'Tanggal pulang tidak boleh sebelum tanggal awal.',
            'pulang_bandara_asal.required' => 'Pilih bandara keberangkatan pulang.',
            'pulang_bandara_tujuan.required' => 'Pilih bandara tujuan pulang.',
            'pulang_bandara_tujuan.different' => 'Bandara tujuan pulang harus berbeda dari bandara keberangkatannya.',
            'pulang_bandara_asal.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'pulang_bandara_tujuan.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'keterangan.required' => 'Tulis stasiun asal dan tujuan kereta di Keterangan (mis. Gambir → Bandung).',
            'uang_muka_nominal.required' => 'Isi jumlah uang muka yang diajukan.',
            'uang_muka_nominal.min' => 'Jumlah uang muka harus lebih dari 0.',
            ...Attachments::messages(),
        ], [
            'karyawan_id' => 'NRP',
            'tanggal_awal' => 'Tanggal awal',
            'tanggal_akhir' => 'Tanggal akhir',
            'kota_asal' => 'Kota asal',
            'kota_tujuan' => 'Kota tujuan',
            'uang_muka_nominal' => 'Jumlah uang muka',
        ]);
        if (CarbonImmutable::parse($data['tanggal_awal'])->diffInDays(CarbonImmutable::parse($data['tanggal_akhir'])) > 366) {
            throw ValidationException::withMessages(['tanggal_akhir' => 'Satu entri paling lama 1 tahun.']);
        }
        Attachments::require($request, $current, 'Lampirkan foto / PDF form '.(($data['jenis'] ?? '') === 'cuti' ? 'cuti' : 'dinas').' yang sudah disetujui.');
        $data['kota_asal'] = trim($data['kota_asal']);
        $data['kota_tujuan'] = trim($data['kota_tujuan']);
        // A flight ticket goes with its airports; a train ticket only for dinas.
        $pesawat = $request->boolean('tiket_pesawat');
        $data['tiket_pesawat'] = $pesawat;
        $data['bandara_asal'] = $pesawat ? $data['bandara_asal'] : null;
        $data['bandara_tujuan'] = $pesawat ? $data['bandara_tujuan'] : null;
        $data['pesawat_pp'] = $pp;
        $data['pulang_tanggal'] = $pp ? $data['pulang_tanggal'] : null;
        $data['pulang_bandara_asal'] = $pp ? $data['pulang_bandara_asal'] : null;
        $data['pulang_bandara_tujuan'] = $pp ? $data['pulang_bandara_tujuan'] : null;
        $data['tiket_kereta'] = $dinas && $request->boolean('tiket_kereta');
        // Uang muka only for dinas, with its amount.
        $data['uang_muka'] = $dinas && $request->boolean('uang_muka');
        $data['uang_muka_nominal'] = $data['uang_muka'] ? (int) $data['uang_muka_nominal'] : null;
        unset($data['lampiran'], $data['halaman'], $data['hapus_lampiran']);

        // A cuti takes its days (calendar days, as in Absensi) from the annual quota or Cuti Besar, by its kind.
        $hari = (int) CarbonImmutable::parse($data['tanggal_awal'])->diffInDays(CarbonImmutable::parse($data['tanggal_akhir'])) + 1;
        if ($data['jenis'] === 'cuti') {
            $karyawan = Karyawan::withoutGlobalScope('akses')->findOrFail($data['karyawan_id']);
            $data += ['hari_cuti' => $hari, ...Cuti::potongan($karyawan, $data['kategori_cuti'], $hari, $current?->id)];
        } else {
            $data = [...$data, 'kategori_cuti' => null, 'hari_cuti' => null, 'potong_tahunan' => 0, 'potong_besar' => 0, 'cuti_besar_periode' => null];
        }

        return $data;
    }

    public static function tripData(Trip $t, CarbonImmutable $today): array
    {
        return [
            'id' => $t->id,
            'karyawan_id' => $t->karyawan_id,
            'nrp' => $t->karyawan->nrp,
            'name' => $t->karyawan->name,
            'jabatan' => $t->karyawan->jabatan?->name,
            'work_location' => $t->karyawan->workLocation?->name,
            'jenis' => $t->jenis,
            'tanggal_awal' => $t->tanggal_awal->toDateString(),
            'tanggal_akhir' => $t->tanggal_akhir->toDateString(),
            'kota_asal' => $t->kota_asal,
            'kota_tujuan' => $t->kota_tujuan,
            'keterangan' => $t->keterangan,
            'status_uang' => $t->status_uang,
            'status' => $t->status,
            'sumber' => $t->sumber,
            'catatan_verifikasi' => $t->catatan_verifikasi,
            'kategori_cuti' => $t->kategori_cuti,
            'hari_cuti' => $t->hari_cuti,
            'potong_tahunan' => $t->potong_tahunan,
            'potong_besar' => $t->potong_besar,
            'tiket_pesawat' => $t->tiket_pesawat,
            'bandara_asal' => $t->bandara_asal,
            'bandara_tujuan' => $t->bandara_tujuan,
            'pesawat_pp' => $t->pesawat_pp,
            'pulang_tanggal' => $t->pulang_tanggal?->toDateString(),
            'pulang_bandara_asal' => $t->pulang_bandara_asal,
            'pulang_bandara_tujuan' => $t->pulang_bandara_tujuan,
            'tiket_kereta' => $t->tiket_kereta,
            'uang_muka' => $t->uang_muka,
            'uang_muka_nominal' => $t->uang_muka_nominal,
            'lampiran' => $t->lampirans->map(fn (Lampiran $l) => $l->toView())->values(),
            'lama' => $t->lamaHari(),
            'perjalanan' => $t->statusPerjalanan($today),
            // Days until departure (akan), the day of the trip it is (berjalan), days since return (selesai).
            'hari_ke' => match ($t->statusPerjalanan($today)) {
                'akan' => (int) $today->diffInDays($t->tanggal_awal),
                'berjalan' => (int) $t->tanggal_awal->diffInDays($today) + 1,
                default => (int) $t->tanggal_akhir->diffInDays($today),
            },
        ];
    }

    /** Active employees, for looking up an NRP in the form. */
    private static function people(): array
    {
        return Karyawan::with(['jabatan:id,name', 'workLocation:id,name'])
            ->where('status', Karyawan::STATUS_AKTIF)
            ->orderBy('nrp')
            ->get()
            ->map(fn (Karyawan $k) => [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'jabatan' => $k->jabatan?->name,
                'work_location' => $k->workLocation?->name,
            ])
            ->all();
    }

    /** Cities typed before, as suggestions. */
    private static function cities(): array
    {
        return Trip::query()->select('kota_asal as kota')->union(Trip::query()->select('kota_tujuan as kota'))
            ->pluck('kota')->unique()->sort()->values()->all();
    }
}
