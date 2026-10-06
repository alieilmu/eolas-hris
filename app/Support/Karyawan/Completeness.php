<?php

namespace App\Support\Karyawan;

use App\Models\KaryawanProfile;

/**
 * Which fields count towards "kelengkapan data" for each of the 19 groups, and
 * when a group (or a single field) does not need to be filled at all. Everything
 * that decides the percentages lives here so the rules can be adjusted in one place.
 *
 * Fields that can never be empty (NRP, Nama, Jabatan) and values the system
 * derives itself (usia, masa kerja, departemen, ...) are not scored.
 */
class Completeness
{
    /** Retirement age used for the "akan pensiun" list. */
    public const PENSIUN_AGE = 55;

    /** Fields of one child row that must be filled once a child is recorded. */
    public const ANAK_FIELDS = ['nama', 'tanggal_lahir', 'pendidikan', 'status_bekerja', 'status_menikah'];

    /**
     * group id => rule. `applies` skips the whole group for an employee; a field
     * given as [key, when] is only scored when `when` holds.
     *
     * @return array<string, array{applies?: callable(array): bool, fields?: list<string|array{0: string, 1: callable(array): bool}>, special?: string}>
     */
    public static function rules(): array
    {
        return [
            'identitas' => ['fields' => ['no_urut', 'nrp_sap', 'klasifikasi', 'klasifikasi_teknis']],
            'kelahiran' => ['fields' => ['tempat_lahir', 'tanggal_lahir', 'tanggal_masuk']],
            'status-pegawai' => ['fields' => [
                'status_pegawai',
                'status_perkawinan',
                // A marriage date makes no sense for someone who has never married.
                ['tanggal_perkawinan', fn (array $ctx) => ! $ctx['belum_menikah']],
            ]],
            // Tempat penerimaan and status asal come from the NRP, so there is nothing to fill.
            // Plafon Pengobatan follows from SubGroup and family (Modul Comben).
            'golongan' => ['fields' => ['golongan']],
            // Only staff still on contract need a PKWT record.
            'pkwt' => ['applies' => fn (array $ctx) => ! $ctx['tetap'], 'special' => 'pkwt'],
            // Only appointed staff have an SK.
            'pengangkatan' => ['applies' => fn (array $ctx) => $ctx['tetap'], 'fields' => ['nomor_sk', 'tanggal_sk']],
            'seragam' => ['fields' => ['ukuran_baju', 'ukuran_celana', 'ukuran_sepatu', 'ukuran_apd']],
            'tanda-pengenal' => ['fields' => [
                'nik', 'nik_provinsi', 'nik_kabupaten_kota', 'npwp', 'npwp_tanggal_daftar',
                'bpjs_tk', 'bpjs_tk_tanggal_daftar', 'bpjs_kesehatan',
            ]],
            'rekening' => ['fields' => ['no_rekening', 'nama_bank', 'nama_pemilik_rekening']],
            'pribadi' => ['fields' => ['agama', 'jenis_kelamin', 'suku_bangsa']],
            'pendidikan' => ['fields' => ['pendidikan_terakhir', 'jurusan', 'asal_sekolah', 'tahun_lulus']],
            'alamat-tinggal' => ['fields' => [
                'alamat_tinggal', 'alamat_tinggal_rt', 'alamat_tinggal_rw',
                'alamat_tinggal_kab_kota', 'alamat_tinggal_provinsi', 'alamat_tinggal_kode_pos',
            ]],
            // alamat_surat_telepon (landline) is optional and not scored.
            'alamat-surat' => ['fields' => [
                'alamat_surat', 'alamat_surat_rt', 'alamat_surat_rw',
                'alamat_surat_kab_kota', 'alamat_surat_kode_pos', 'alamat_surat_hp',
            ]],
            'kontak' => ['fields' => ['emergency_telepon', 'emergency_nama', 'emergency_hubungan', 'no_hp', 'no_whatsapp']],
            // The office email lives on the employee record and is optional.
            'email' => ['fields' => ['email_pribadi']],
            'pasangan' => [
                'applies' => fn (array $ctx) => $ctx['menikah'],
                'fields' => ['nama_pasangan', 'tanggal_lahir_pasangan', 'pekerjaan_pasangan'],
            ],
            // Scored only for children that are actually recorded.
            'anak' => ['applies' => fn (array $ctx) => $ctx['jumlah_anak'] > 0, 'special' => 'anak'],
            // ortu_telepon (landline) is optional and not scored.
            'orang-tua' => ['fields' => [
                'nama_ayah', 'tanggal_lahir_ayah', 'nama_ibu', 'tanggal_lahir_ibu',
                'ortu_alamat_rt', 'ortu_alamat_rw', 'ortu_alamat_kab_kota', 'ortu_alamat_kode_pos', 'ortu_hp',
            ]],
            'mertua' => [
                'applies' => fn (array $ctx) => $ctx['menikah'],
                'fields' => [
                    'nama_ayah_mertua', 'tanggal_lahir_ayah_mertua', 'nama_ibu_mertua', 'tanggal_lahir_ibu_mertua',
                    'mertua_alamat_rt', 'mertua_alamat_rw', 'mertua_alamat_kab_kota', 'mertua_alamat_kode_pos', 'mertua_hp',
                ],
            ],
        ];
    }

    /**
     * Scores one employee's profile group by group. A missing profile is scored
     * as an entirely empty one. Expects pkwtPeriods and anak to be loaded.
     *
     * @return array<string, array{filled: int, total: int, applicable: bool}>
     */
    public static function score(?KaryawanProfile $profile): array
    {
        $anak = $profile?->anak ?? collect();
        $ctx = [
            'menikah' => $profile?->status_perkawinan === 'Menikah',
            'belum_menikah' => $profile?->status_perkawinan === 'Belum Menikah',
            'tetap' => $profile?->status_pegawai_tetap === true,
            'jumlah_anak' => $anak->count(),
        ];

        $scores = [];

        foreach (self::rules() as $id => $rule) {
            if (isset($rule['applies']) && ! $rule['applies']($ctx)) {
                $scores[$id] = ['filled' => 0, 'total' => 0, 'applicable' => false];

                continue;
            }

            $filled = 0;
            $total = 0;

            if (($rule['special'] ?? null) === 'pkwt') {
                $total = 1;
                $filled = ($profile?->pkwtPeriods->isNotEmpty() ?? false) ? 1 : 0;
            } elseif (($rule['special'] ?? null) === 'anak') {
                foreach ($anak as $child) {
                    foreach (self::ANAK_FIELDS as $field) {
                        $total++;
                        $filled += self::isFilled($child->{$field}) ? 1 : 0;
                    }
                }
            } else {
                foreach ($rule['fields'] as $field) {
                    if (is_array($field)) {
                        [$field, $when] = $field;
                        if (! $when($ctx)) {
                            continue;
                        }
                    }
                    $total++;
                    $filled += self::isFilled($profile?->{$field}) ? 1 : 0;
                }
            }

            $scores[$id] = ['filled' => $filled, 'total' => $total, 'applicable' => true];
        }

        return $scores;
    }

    /** @param  array<string, array{filled: int, total: int}>  $scores */
    public static function overallPercent(array $scores): ?int
    {
        $filled = array_sum(array_column($scores, 'filled'));
        $total = array_sum(array_column($scores, 'total'));

        return $total > 0 ? (int) round($filled / $total * 100) : null;
    }

    private static function isFilled(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }
}
