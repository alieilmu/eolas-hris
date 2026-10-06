<?php

namespace App\Support\Karyawan;

use App\Models\KaryawanProfile;
use App\Models\PkwtPeriod;

/**
 * The 19 groups of the employee master record, flattened into table columns.
 * Keys match the rows built by KaryawanRow. Repeating blocks (PKWT, children)
 * are expanded here; the child count comes from the data so an employee with
 * more than four children still shows all of them.
 */
class ColumnGroups
{
    public const MIN_ANAK_COLUMNS = 4;

    /** Every contract period side by side — used by the Excel template, export and import. */
    public const PKWT_FULL = 'full';

    /** On screen: only the contract that ends last. */
    public const PKWT_ACTIVE = 'active';

    /**
     * @return list<array{id: string, title: string, locked?: bool, columns: list<array{key: string, label: string, sticky?: bool}>}>
     */
    public static function build(int $anakCount = self::MIN_ANAK_COLUMNS, string $pkwtMode = self::PKWT_FULL): array
    {
        $pkwtColumns = $pkwtMode === self::PKWT_ACTIVE
            ? [
                self::col('pkwt_terakhir_periode', 'PKWT Terakhir'),
                self::col('pkwt_terakhir_kontrak', 'Tgl Kontrak'),
                self::col('pkwt_terakhir_berakhir', 'Tgl Berakhir'),
            ]
            : collect(range(1, KaryawanProfile::PKWT_MAX_PERIODS))->flatMap(fn (int $i) => [
                self::col("pkwt_{$i}_kontrak", PkwtPeriod::label($i).' — Tgl Kontrak'),
                self::col("pkwt_{$i}_berakhir", PkwtPeriod::label($i).' — Tgl Berakhir'),
            ])->all();
        $pkwtColumns[] = self::col('status_akhir_pkwt', 'Status Akhir PKWT');

        $anakColumns = collect(range(1, max($anakCount, self::MIN_ANAK_COLUMNS)))->flatMap(fn (int $i) => [
            self::col("anak_{$i}_nama", "Anak {$i} — Nama"),
            self::col("anak_{$i}_tanggal_lahir", "Anak {$i} — Tgl Lahir"),
            self::col("anak_{$i}_pendidikan", "Anak {$i} — Pendidikan"),
            self::col("anak_{$i}_status_bekerja", "Anak {$i} — Status Bekerja"),
            self::col("anak_{$i}_status_menikah", "Anak {$i} — Status Menikah"),
        ])->all();

        return [
            [
                'id' => 'identitas',
                'title' => '1. Identitas & Pekerjaan',
                // Always shown: carries the NRP / Nama columns every row is read by.
                'locked' => true,
                'columns' => [
                    self::col('no_urut', 'No. Urut'),
                    ['key' => 'nrp', 'label' => 'NRP', 'sticky' => true],
                    ['key' => 'name', 'label' => 'Nama', 'sticky' => true],
                    self::col('nrp_sap', 'NRP SAP'),
                    self::col('jenis_karyawan', 'Jenis Karyawan'),
                    // Placement: the position title is composed from these (see JabatanComposer).
                    self::col('work_location_name', 'Work Location'),
                    self::col('sub_location_name', 'Sub Lokasi Kerja'),
                    self::col('sub_group', 'SubGroup'),
                    self::col('jabatan_name', 'Jabatan'),
                    self::col('bod_name', 'BOD'),
                    self::col('divisi_name', 'Divisi'),
                    self::col('departemen_name', 'Departemen'),
                    self::col('section_name', 'Section'),
                    self::col('bidang', 'Nama Jabatan (Bidang)'),
                    self::col('klasifikasi', 'Klasifikasi'),
                    self::col('klasifikasi_teknis', 'Klasifikasi Teknis'),
                ],
            ],
            self::group('kelahiran', '2. Kelahiran & Masa Kerja', [
                'tempat_lahir' => 'Tempat Lahir',
                'tanggal_lahir' => 'Tanggal Lahir',
                'tanggal_masuk' => 'Tanggal Masuk',
                'usia' => 'Usia',
                'masa_kerja' => 'Masa Kerja',
            ]),
            self::group('status-pegawai', '3. Status Pegawai & Perkawinan', [
                'status_pegawai' => 'Status Pegawai',
                'status_perkawinan' => 'Status Perkawinan',
                'tanggal_perkawinan' => 'Tanggal Perkawinan',
            ]),
            self::group('golongan', '4. Golongan & Hak Normatif', [
                'golongan' => 'Golongan',
                'plafon_pengobatan' => 'Plafon Pengobatan',
                'tempat_penerimaan' => 'Tempat Penerimaan',
                'status_asal' => 'Status Asal',
            ]),
            ['id' => 'pkwt', 'title' => '5. Kontrak Kerja (PKWT)', 'columns' => $pkwtColumns],
            self::group('pengangkatan', '6. Pengangkatan Pegawai', [
                'status_pegawai_tetap' => 'Pegawai Tetap',
                'nomor_sk' => 'Nomor SK',
                'tanggal_sk' => 'Tanggal SK',
            ]),
            self::group('seragam', '7. Ukuran Seragam & APD', [
                'ukuran_baju' => 'Ukuran Baju',
                'ukuran_celana' => 'Ukuran Celana',
                'ukuran_sepatu' => 'Ukuran Sepatu',
                'ukuran_apd' => 'Ukuran APD',
            ]),
            self::group('tanda-pengenal', '8. Tanda Pengenal & Kepesertaan', [
                'nik' => 'NIK (KTP)',
                'nik_provinsi' => 'Provinsi (NIK)',
                'nik_kabupaten_kota' => 'Kab/Kota (NIK)',
                'npwp' => 'NPWP',
                'npwp_tanggal_daftar' => 'Tgl Terdaftar NPWP',
                'bpjs_tk' => 'BPJS Ketenagakerjaan',
                'bpjs_tk_tanggal_daftar' => 'Tgl Terdaftar BPJS TK',
                'bpjs_kesehatan' => 'BPJS Kesehatan',
            ]),
            self::group('rekening', '9. Rekening Bank', [
                'no_rekening' => 'Nomor Rekening',
                'nama_bank' => 'Nama Bank',
                'nama_pemilik_rekening' => 'Nama Pemilik Rekening',
            ]),
            self::group('pribadi', '10. Data Pribadi', [
                'agama' => 'Agama',
                'jenis_kelamin' => 'Jenis Kelamin',
                'suku_bangsa' => 'Suku Bangsa',
            ]),
            self::group('pendidikan', '11. Riwayat Pendidikan', [
                'pendidikan_terakhir' => 'Pendidikan Terakhir',
                'jurusan' => 'Jurusan',
                'asal_sekolah' => 'Asal Sekolah/Universitas',
                'tahun_lulus' => 'Tahun Lulus',
            ]),
            self::group('alamat-tinggal', '12. Alamat Tinggal', [
                'alamat_tinggal' => 'Alamat',
                'alamat_tinggal_rt' => 'RT',
                'alamat_tinggal_rw' => 'RW',
                'alamat_tinggal_kab_kota' => 'Kabupaten/Kota',
                'alamat_tinggal_provinsi' => 'Provinsi',
                'alamat_tinggal_kode_pos' => 'Kode Pos',
            ]),
            self::group('alamat-surat', '13. Alamat Surat Menyurat', [
                'alamat_surat' => 'Alamat',
                'alamat_surat_rt' => 'RT',
                'alamat_surat_rw' => 'RW',
                'alamat_surat_kab_kota' => 'Kabupaten/Kota',
                'alamat_surat_kode_pos' => 'Kode Pos',
                'alamat_surat_telepon' => 'Telepon',
                'alamat_surat_hp' => 'HP',
            ]),
            self::group('kontak', '14. Kontak Darurat & Nomor Pribadi', [
                'emergency_telepon' => 'Telepon Darurat',
                'emergency_nama' => 'Nama Pemilik Nomor',
                'emergency_hubungan' => 'Hubungan',
                'no_hp' => 'Nomor HP',
                'no_whatsapp' => 'Nomor WhatsApp',
            ]),
            self::group('email', '15. Email', [
                'email' => 'Email Kantor',
                'email_pribadi' => 'Email (Gmail)',
            ]),
            self::group('pasangan', '16. Keluarga Inti', [
                'nama_pasangan' => 'Nama Istri/Suami',
                'tanggal_lahir_pasangan' => 'Tanggal Lahir',
                'pekerjaan_pasangan' => 'Pekerjaan',
            ]),
            ['id' => 'anak', 'title' => '17. Data Anak', 'columns' => $anakColumns],
            self::group('orang-tua', '18. Data Orang Tua', [
                'nama_ayah' => 'Nama Ayah',
                'tanggal_lahir_ayah' => 'Tgl Lahir Ayah',
                'nama_ibu' => 'Nama Ibu',
                'tanggal_lahir_ibu' => 'Tgl Lahir Ibu',
                'ortu_alamat_rt' => 'RT',
                'ortu_alamat_rw' => 'RW',
                'ortu_alamat_kab_kota' => 'Kabupaten/Kota',
                'ortu_alamat_kode_pos' => 'Kode Pos',
                'ortu_telepon' => 'Telepon',
                'ortu_hp' => 'HP',
            ]),
            self::group('mertua', '19. Data Mertua', [
                'nama_ayah_mertua' => 'Nama Ayah Mertua',
                'tanggal_lahir_ayah_mertua' => 'Tgl Lahir Ayah Mertua',
                'nama_ibu_mertua' => 'Nama Ibu Mertua',
                'tanggal_lahir_ibu_mertua' => 'Tgl Lahir Ibu Mertua',
                'mertua_alamat_rt' => 'RT',
                'mertua_alamat_rw' => 'RW',
                'mertua_alamat_kab_kota' => 'Kabupaten/Kota',
                'mertua_alamat_kode_pos' => 'Kode Pos',
                'mertua_hp' => 'HP',
            ]),
        ];
    }

    /** @return list<string> group ids in display order */
    public static function ids(): array
    {
        return array_column(self::build(), 'id');
    }

    private static function col(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label];
    }

    /** @param  array<string, string>  $columns  key => label */
    private static function group(string $id, string $title, array $columns): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'columns' => array_map(self::col(...), array_keys($columns), array_values($columns)),
        ];
    }
}
