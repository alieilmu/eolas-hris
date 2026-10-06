<?php

namespace Tests\Feature\Comben;

use App\Models\Deklarasi;
use App\Models\Golongan;
use App\Models\Karyawan;
use App\Models\PlafonKacamata;
use App\Models\PlafonPengobatan;
use App\Models\SkalaUpah;
use App\Models\SkalaUpahKolom;
use App\Models\SubGroup;
use App\Models\User;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CombenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 09:00:00');
        CarbonImmutable::setTestNow('2026-10-03 09:00:00');
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** An active employee of a SubGroup, made single (TK/0) or married with $anak children. */
    private function employee(string $subGroup, bool $kawin = false, int $anak = 0, ?string $golongan = null): Karyawan
    {
        $k = Karyawan::where('status', Karyawan::STATUS_AKTIF)
            ->whereHas('subGroup', fn ($q) => $q->where('nama', $subGroup))
            ->whereNotIn('id', $this->used ?? [])
            ->firstOrFail();
        $this->used[] = $k->id;
        $k->profile->update(['status_perkawinan' => $kawin ? 'Menikah' : 'Belum Menikah', 'nama_pasangan' => $kawin ? 'Siti' : null, ...($golongan ? ['golongan' => $golongan] : [])]);
        $k->profile->anak()->delete();
        for ($i = 1; $i <= $anak; $i++) {
            $k->profile->anak()->create(['urutan' => $i, 'nama' => "Anak {$i} {$k->name}"]);
        }

        return $k->fresh(['subGroup', 'profile.anak']);
    }

    private array $used = [];

    private function deklarasi(Karyawan $k, array $items, string $tanggal = '2026-10-02', ?int $id = null): TestResponse
    {
        $data = ['karyawan_id' => $k->id, 'tanggal' => $tanggal, 'tempat' => 'Ugang Sayu', 'lampiran' => $id ? [] : [UploadedFile::fake()->image('nota.jpg')], 'items' => $items];

        return $id ? $this->put("/comben/deklarasi/{$id}", $data) : $this->post('/comben/deklarasi', $data);
    }

    private function item(string $jenis, int $nominal, string $penerima = 'karyawan', ?int $anak = null): array
    {
        return ['jenis' => $jenis, 'penerima' => $penerima, 'karyawan_anak_id' => $anak, 'nominal' => $nominal];
    }

    // --- master data & rules ---------------------------------------------------------------

    public function test_master_data_is_seeded_from_the_tables(): void
    {
        $this->assertSame(63, Golongan::count());
        $this->assertSame(['NONOM', 'OPERATOR', 'MEKANIK', 'WELDER', 'STAFF'], Golongan::orderBy('urutan')->pluck('grup')->unique()->values()->all());
        $this->assertSame(72, SkalaUpah::count());
        $step1 = SkalaUpah::with('nilai')->where('step', 'Step 1')->firstOrFail();
        $this->assertSame('1A', $step1->golongan->kode);
        $this->assertEqualsCanonicalizing([3850000, 4067140], $step1->nilai->pluck('nominal')->all());

        $this->assertSame([[1750000, 2250000], [2500000, 3750000], [3250000, 5250000], [5800000, 5800000], [7550000, 7550000], [9550000, 9550000], [11000000, 11000000]],
            PlafonPengobatan::orderBy('urutan')->get()->map(fn ($p) => [$p->lajang, $p->keluarga])->all());
        $this->assertSame([625000, 800000, 1000000, 1125000, 1250000, 1500000, 2000000], PlafonKacamata::orderBy('urutan')->pluck('bingkai')->all());
        // Every SubGroup but BOD has a plafon row.
        $mapped = PlafonPengobatan::all()->flatMap->sub_group_ids->all();
        $this->assertSame(['BOD'], SubGroup::whereNotIn('id', $mapped)->pluck('nama')->all());

        $this->get('/comben/master-data')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comben/MasterData')
            ->has('golongans', 63)
            ->has('kolom', 2)
            ->where('kolom.0.label', 'Tahun 2025'));
    }

    public function test_book_month_closes_on_the_8th_and_the_plafon_year_follows_it(): void
    {
        $this->assertSame('2026-10', BukuBulan::of(CarbonImmutable::parse('2026-10-08')));
        $this->assertSame('2026-11', BukuBulan::of(CarbonImmutable::parse('2026-10-09')));
        $this->assertSame('2027-01', BukuBulan::of(CarbonImmutable::parse('2026-12-09')));
        $this->assertSame(2027, BukuBulan::tahun(BukuBulan::of(CarbonImmutable::parse('2026-12-31'))));
        $this->assertSame('09.09.2026 – 08.10.2026', BukuBulan::span('2026-10'));
    }

    public function test_plafon_follows_subgroup_and_family_status(): void
    {
        $e = Entitlement::shared();
        $lajang = $this->employee('Operator', golongan: 'D2J');
        $this->assertSame('TK/0', Entitlement::statusPtkp($lajang));
        $this->assertSame(1750000, $e->plafonPengobatan($lajang));
        $this->assertNull($e->warning($lajang));

        $keluarga = $this->employee('Operator', kawin: true, anak: 2, golongan: '3A');
        $this->assertSame('K/2', Entitlement::statusPtkp($keluarga));
        $this->assertSame(2250000, $e->plafonPengobatan($keluarga));
        $this->assertSame('Golongan 3A di luar rentang plafon L1 untuk SubGroup Operator.', $e->warning($keluarga));
        $this->assertCount(4, Entitlement::members($keluarga));

        // Not married but with a child: keluarga.
        $single = $this->employee('Section Head Site', anak: 1, golongan: '4C');
        $this->assertSame('TK/1', Entitlement::statusPtkp($single));
        $this->assertSame(5800000, $e->plafonPengobatan($single));

        // The profile shows it, computed.
        $this->get("/manpower/karyawan/{$keluarga->id}/profil")->assertInertia(fn (Assert $page) => $page
            ->where('computed.status_ptkp', 'K/2')
            ->where('computed.plafon_pengobatan', fn ($t) => str_starts_with($t, 'Rp 2.250.000 / tahun (L1 · Keluarga)'))
            ->where('sections', fn ($sections) => collect($sections)->flatMap(fn ($s) => $s['fields'] ?? [])->firstWhere('key', 'golongan')['options'][0] === '1A'));
    }

    // --- deklarasi ---------------------------------------------------------------------------

    public function test_deklarasi_pengobatan_within_the_family_plafon(): void
    {
        $k = $this->employee('Operator', kawin: true, anak: 1, golongan: 'D2J');
        $anak = $k->profile->anak->first();

        $this->deklarasi($k, [$this->item('pengobatan', 215000), $this->item('perawatan', 100000, 'pasangan'), $this->item('pengobatan', 50000, 'anak', $anak->id)])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', "Deklarasi DKL/2026/10/0001 {$k->name} disimpan (bulan buku Oktober 2026).");
        $d = Deklarasi::firstOrFail();
        $this->assertSame(['2026-10', 2026], [$d->bulan_buku, $d->tahun_plafon]);

        $this->getJson("/comben/karyawan/{$k->id}/plafon?tanggal=2026-10-02")->assertOk()
            ->assertJsonPath('pengobatan.plafon', 2250000)
            ->assertJsonPath('pengobatan.terpakai', 365000)
            ->assertJsonPath('pengobatan.sisa', 1885000)
            ->assertJsonPath('status_ptkp', 'K/1')
            ->assertJsonPath('bulan_buku.label', 'Oktober 2026');

        // Over what is left: refused.
        $this->deklarasi($k, [$this->item('pengobatan', 1900000)])
            ->assertSessionHasErrors(['items' => 'Plafon Pengobatan 2026 (K/1) tersisa Rp 1.885.000 dari Rp 2.250.000; deklarasi ini Rp 1.900.000.']);
        // A date after the 8th of December books to January: the next year's plafon.
        $this->deklarasi($k, [$this->item('pengobatan', 1900000)], '2026-12-10')->assertSessionHasNoErrors();
        $this->assertSame(['2027-01', 2027], [Deklarasi::latest('id')->first()->bulan_buku, Deklarasi::latest('id')->first()->tahun_plafon]);
        $this->assertSame('DKL/2027/01/0001', Deklarasi::latest('id')->first()->nomor);

        // Editing counts the deklarasi only once; deleting frees the plafon.
        $this->deklarasi($k, [$this->item('pengobatan', 2250000)], '2026-10-02', $d->id)->assertSessionHasNoErrors();
        $this->deklarasi($k, [$this->item('pengobatan', 1)])->assertSessionHasErrors('items');
        $this->delete("/comben/deklarasi/{$d->id}")->assertSessionHasNoErrors();
        $this->deklarasi($k, [$this->item('pengobatan', 1)])->assertSessionHasNoErrors();
    }

    public function test_kacamata_per_member_frame_every_two_years_lens_every_year(): void
    {
        $k = $this->employee('Officer HO/ Group Leader', kawin: false, anak: 1, golongan: '4A');
        $anak = $k->profile->anak->first();

        // Not married: no spouse; a child must be one of theirs.
        $this->deklarasi($k, [$this->item('lensa', 100000, 'pasangan')])->assertSessionHasErrors(['items' => 'Status perkawinan karyawan bukan Menikah, jadi tidak ada klaim untuk Istri / Suami.']);
        $this->deklarasi($k, [$this->item('lensa', 100000, 'anak', 999999)])->assertSessionHasErrors('items');

        $this->deklarasi($k, [$this->item('bingkai', 1000000), $this->item('lensa', 1000000), $this->item('lensa', 400000, 'anak', $anak->id)])->assertSessionHasNoErrors();
        $this->deklarasi($k, [$this->item('lensa', 700000, 'anak', $anak->id)])
            ->assertSessionHasErrors(['items' => "Kacamata – Lensa {$anak->nama} (Anak 1) tersisa Rp 600.000 dari Rp 1.000.000; deklarasi ini Rp 700.000."]);

        // Next year: lenses again, but the frame only the year after.
        $this->deklarasi($k, [$this->item('lensa', 1000000)], '2027-02-01')->assertSessionHasNoErrors();
        $this->deklarasi($k, [$this->item('bingkai', 500000)], '2027-02-01')
            ->assertSessionHasErrors(['items' => "Kacamata – Bingkai {$k->name} (Karyawan) tersisa Rp 0 dari Rp 1.000.000; deklarasi ini Rp 500.000. Bingkai terakhir diklaim Oktober 2026; bingkai bisa diklaim lagi mulai tahun 2028."]);
        $this->deklarasi($k, [$this->item('bingkai', 500000)], '2028-02-01')->assertSessionHasNoErrors();
    }

    public function test_monitoring_lists_plafon_claims_per_month_and_what_is_left(): void
    {
        $k = $this->employee('Operator', kawin: true, anak: 1, golongan: 'D2J');
        $this->deklarasi($k, [$this->item('pengobatan', 200000)], '2026-09-20'); // book month October
        $this->deklarasi($k, [$this->item('pengobatan', 300000), $this->item('lensa', 500000, 'pasangan')], '2026-10-02');

        $this->get('/comben/monitoring?tahun=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comben/Monitoring')
            ->has('months', 12)
            ->where('current_bulan', '2026-10')
            ->where('pengobatan', fn ($rows) => ($r = collect($rows)->firstWhere('id', $k->id))
                && $r['plafon'] === 2250000 && $r['terpakai'] === 500000 && $r['sisa'] === 1750000
                && (array) $r['bulan'] === ['2026-10' => 500000] && $r['status_ptkp'] === 'K/1')
            ->where('kacamata', fn ($rows) => ($r = collect($rows)->firstWhere('key', "{$k->id}:pasangan"))
                && $r['lensa'] === 500000 && $r['sisa_lensa'] === 125000 && $r['anggota'] === 'Siti'));
    }

    // --- master edits ------------------------------------------------------------------------

    public function test_master_edits_follow_through(): void
    {
        $k = $this->employee('Operator', golongan: 'D2J');
        $d2j = Golongan::where('kode', 'D2J')->firstOrFail();

        // Renaming a golongan renames it on the employees holding it; one in use cannot go.
        $this->put("/comben/master-data/golongan/{$d2j->id}", ['grup' => 'OPERATOR', 'kode' => 'd2j-x'])->assertSessionHasNoErrors();
        $this->assertSame('D2J-X', $k->profile->fresh()->golongan);
        $this->delete("/comben/master-data/golongan/{$d2j->id}")->assertSessionHasErrors('kode');
        $this->post('/comben/master-data/golongan', ['grup' => 'driver', 'kode' => 'DR1'])->assertSessionHasNoErrors();
        $this->assertSame('DRIVER', Golongan::where('kode', 'DR1')->value('grup'));

        // A SubGroup sits in one plafon row only.
        $operator = SubGroup::where('nama', 'Operator')->value('id');
        $l2 = PlafonPengobatan::where('layer', 'L2')->firstOrFail();
        $this->put("/comben/master-data/plafon/pengobatan/{$l2->id}", [
            'layer' => 'L2', 'golongan_ids' => $l2->golongan_ids, 'sub_group_ids' => [...$l2->sub_group_ids, $operator],
            'lajang' => 1, 'keluarga' => 2,
        ])->assertSessionHasErrors('sub_group_ids');

        // Changing the plafon changes the employee's entitlement straight away.
        $l1 = PlafonPengobatan::where('layer', 'L1')->firstOrFail();
        $this->put("/comben/master-data/plafon/pengobatan/{$l1->id}", [
            'layer' => 'L1', 'golongan_ids' => $l1->golongan_ids, 'sub_group_ids' => $l1->sub_group_ids, 'lajang' => 1800000, 'keluarga' => 2300000,
        ])->assertSessionHasNoErrors();
        $this->getJson("/comben/karyawan/{$k->id}/plafon")->assertJsonPath('pengobatan.plafon', 1800000);

        // Skala upah: a new column and a value in it.
        $this->post('/comben/master-data/skala-kolom', ['tahun' => 2027])->assertSessionHasNoErrors();
        $this->post('/comben/master-data/skala-kolom', ['tahun' => 2027])->assertSessionHasErrors('tahun');
        $kolom = SkalaUpahKolom::where('tahun', 2027)->firstOrFail();
        $row = SkalaUpah::where('step', 'Step 1')->firstOrFail();
        $this->put("/comben/master-data/skala-upah/{$row->id}", ['golongan_id' => $row->golongan_id, 'step' => 'Step 1', 'nilai' => [$kolom->id => 4200000]])->assertSessionHasNoErrors();
        $this->assertSame(4200000, $row->nilai()->where('kolom_id', $kolom->id)->value('nominal'));
    }

    public function test_viewers_are_kept_out(): void
    {
        $this->actingAs(User::where('email', 'viewer@hrpuspita.local')->firstOrFail());
        $this->get('/comben/deklarasi')->assertForbidden();
        $this->get('/comben/monitoring')->assertForbidden();
        $this->get('/comben/master-data')->assertForbidden();
    }
}
