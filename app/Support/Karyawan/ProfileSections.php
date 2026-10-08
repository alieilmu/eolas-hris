<?php

namespace App\Support\Karyawan;

use App\Models\Golongan;
use App\Models\WorkLocation;

/**
 * The profile form: 19 sections, their fields and choice lists. The page renders
 * from this, the update request validates against it, and the Excel import and
 * reference sheet use the same choices. Most lists live in Master Data
 * Personalia (MasterOptions); the few fixed ones are below.
 */
class ProfileSections
{
    public const JENIS_KELAMIN = ['Laki-laki', 'Perempuan'];

    public const STATUS_BEKERJA_ANAK = ['Bekerja', 'Belum Bekerja'];

    public const STATUS_MENIKAH_ANAK = ['Menikah', 'Belum Menikah'];

    /** select field => master list it draws from */
    public const MASTER_LISTS = [
        'klasifikasi' => 'klasifikasi',
        'klasifikasi_teknis' => 'klasifikasi_teknis',
        'status_perkawinan' => 'status_perkawinan',
        'ukuran_baju' => 'ukuran_baju',
        'ukuran_celana' => 'ukuran_celana',
        'ukuran_sepatu' => 'ukuran_sepatu',
        'ukuran_apd' => 'ukuran_apd',
        'agama' => 'agama',
        'pendidikan_terakhir' => 'pendidikan',
    ];

    /** Choices of a select field, whether fixed or from the master data. */
    public static function optionsFor(string $key): array
    {
        return match ($key) {
            'jenis_kelamin' => self::JENIS_KELAMIN,
            'lokasi_kerja' => WorkLocation::orderBy('order')->pluck('name')->all(),
            // Master Data Comben.
            'golongan' => Golongan::codes(),
            default => MasterOptions::values(self::MASTER_LISTS[$key]),
        };
    }

    /**
     * @return list<array{id: string, title: string, kind?: string, fields?: list<array{key: string, label: string, type: string, options?: list<string>, computed?: bool, hint?: string}>}>
     */
    public static function build(): array
    {
        return [
            self::section('identitas', '1. Identitas & Pekerjaan', [
                self::f('no_urut', 'No. Urut', 'number'),
                self::f('nrp_sap', 'NRP SAP', hint: 'Kunci integrasi dengan sistem SAP'),
                self::f('jenis_karyawan', 'Jenis Karyawan', computed: true, hint: 'Dari digit ke-1 NRP'),
                // Part of the placement (the position belongs to a work location): changed with "Ubah Penempatan".
                self::f('lokasi_kerja', 'Lokasi Kerja', computed: true, hint: 'Diubah lewat Ubah Penempatan; menentukan Status Asal'),
                self::f('klasifikasi', 'Klasifikasi', 'select', self::optionsFor('klasifikasi')),
                self::f('klasifikasi_teknis', 'Klasifikasi Teknis', 'select', self::optionsFor('klasifikasi_teknis')),
            ]),
            self::section('kelahiran', '2. Kelahiran & Masa Kerja', [
                self::f('tempat_lahir', 'Tempat Lahir'),
                self::f('tanggal_lahir', 'Tanggal Lahir', 'date'),
                self::f('tanggal_masuk', 'Tanggal Masuk Kerja', 'date'),
                self::f('usia', 'Usia', computed: true),
                self::f('masa_kerja', 'Masa Kerja', computed: true),
            ]),
            self::section('status-pegawai', '3. Status Pegawai & Perkawinan', [
                self::f('status_pegawai', 'Status Pegawai'),
                self::f('status_perkawinan', 'Status Perkawinan', 'select', self::optionsFor('status_perkawinan')),
                self::f('status_ptkp', 'Status Keluarga', computed: true, hint: 'K = Kawin, TK = Tidak Kawin, lalu jumlah anak terdaftar (mis. K/2). Menentukan plafon Lajang / Keluarga.'),
                self::f('tanggal_perkawinan', 'Tanggal Perkawinan', 'date'),
            ]),
            self::section('golongan', '4. Golongan & Hak Normatif', [
                self::f('golongan', 'Golongan', 'select', self::optionsFor('golongan'), hint: 'Dari Master Data Comben'),
                self::f('plafon_pengobatan', 'Plafon Pengobatan', computed: true, hint: 'Otomatis dari Master Data Comben: SubGroup + Status Keluarga'),
                self::f('tempat_penerimaan', 'Tempat Penerimaan', computed: true, hint: 'Dari digit ke-2–3 NRP'),
                self::f('status_asal', 'Status Asal', computed: true, hint: 'Lokal bila lokasi penerimaan = lokasi kerja jabatan'),
            ]),
            ['id' => 'pkwt', 'title' => '5. Kontrak Kerja (PKWT)', 'kind' => 'pkwt'],
            self::section('pengangkatan', '6. Pengangkatan Pegawai', [
                self::f('status_pegawai_tetap', 'Sudah Diangkat Pegawai Tetap', 'checkbox'),
                self::f('nomor_sk', 'Nomor SK'),
                self::f('tanggal_sk', 'Tanggal SK', 'date'),
                self::f('status_akhir_pkwt', 'Status Akhir PKWT', computed: true),
            ]),
            self::section('seragam', '7. Ukuran Seragam & APD', [
                self::f('ukuran_baju', 'Ukuran Baju', 'select', self::optionsFor('ukuran_baju')),
                self::f('ukuran_celana', 'Ukuran Celana', 'select', self::optionsFor('ukuran_celana')),
                self::f('ukuran_sepatu', 'Ukuran Sepatu', 'select', self::optionsFor('ukuran_sepatu')),
                self::f('ukuran_apd', 'Ukuran APD', 'select', self::optionsFor('ukuran_apd')),
            ]),
            self::section('tanda-pengenal', '8. Tanda Pengenal & Kepesertaan', [
                self::f('nik', 'NIK (KTP)', hint: '16 digit angka, unik antar karyawan'),
                self::f('nik_provinsi', 'Provinsi sesuai NIK'),
                self::f('nik_kabupaten_kota', 'Kabupaten/Kota sesuai NIK'),
                self::f('npwp', 'NPWP', hint: '15 atau 16 digit angka'),
                self::f('npwp_tanggal_daftar', 'Tanggal Terdaftar NPWP', 'date'),
                self::f('bpjs_tk', 'BPJS Ketenagakerjaan'),
                self::f('bpjs_tk_tanggal_daftar', 'Tanggal Terdaftar BPJS TK', 'date'),
                self::f('bpjs_kesehatan', 'BPJS Kesehatan'),
            ]),
            self::section('rekening', '9. Rekening Bank', [
                self::f('no_rekening', 'Nomor Rekening'),
                self::f('nama_bank', 'Nama Bank'),
                self::f('nama_pemilik_rekening', 'Nama Pemilik Rekening', hint: 'Dicocokkan dengan nama karyawan untuk mencegah salah transfer'),
            ]),
            self::section('pribadi', '10. Data Pribadi', [
                self::f('agama', 'Agama', 'select', self::optionsFor('agama')),
                self::f('jenis_kelamin', 'Jenis Kelamin', 'select', self::JENIS_KELAMIN),
                self::f('suku_bangsa', 'Suku Bangsa'),
            ]),
            self::section('pendidikan', '11. Riwayat Pendidikan', [
                self::f('pendidikan_terakhir', 'Pendidikan Terakhir', 'select', self::optionsFor('pendidikan_terakhir')),
                self::f('jurusan', 'Jurusan'),
                self::f('asal_sekolah', 'Asal Sekolah/Universitas'),
                self::f('tahun_lulus', 'Tahun Lulus', 'number'),
            ]),
            self::section('alamat-tinggal', '12. Alamat Tinggal', [
                self::f('alamat_tinggal', 'Alamat', 'textarea'),
                self::f('alamat_tinggal_rt', 'RT'),
                self::f('alamat_tinggal_rw', 'RW'),
                self::f('alamat_tinggal_kab_kota', 'Kabupaten/Kota'),
                self::f('alamat_tinggal_provinsi', 'Provinsi'),
                self::f('alamat_tinggal_kode_pos', 'Kode Pos'),
            ]),
            self::section('alamat-surat', '13. Alamat Surat Menyurat', [
                self::f('alamat_surat', 'Alamat', 'textarea'),
                self::f('alamat_surat_rt', 'RT'),
                self::f('alamat_surat_rw', 'RW'),
                self::f('alamat_surat_kab_kota', 'Kabupaten/Kota'),
                self::f('alamat_surat_kode_pos', 'Kode Pos'),
                self::f('alamat_surat_telepon', 'Telepon'),
                self::f('alamat_surat_hp', 'HP'),
            ]),
            self::section('kontak', '14. Kontak Darurat & Nomor Pribadi', [
                self::f('emergency_telepon', 'Nomor Telepon Darurat'),
                self::f('emergency_nama', 'Nama Pemilik Nomor'),
                self::f('emergency_hubungan', 'Hubungan dengan Karyawan'),
                self::f('no_hp', 'Nomor HP'),
                self::f('no_whatsapp', 'Nomor WhatsApp'),
            ]),
            self::section('email', '15. Email', [
                self::f('email_pribadi', 'Email (Gmail)'),
            ]),
            self::section('pasangan', '16. Keluarga Inti (Istri/Suami)', [
                self::f('nama_pasangan', 'Nama Istri/Suami'),
                self::f('tanggal_lahir_pasangan', 'Tanggal Lahir', 'date'),
                self::f('pekerjaan_pasangan', 'Pekerjaan'),
            ]),
            ['id' => 'anak', 'title' => '17. Data Anak', 'kind' => 'anak'],
            self::section('orang-tua', '18. Data Orang Tua', [
                self::f('nama_ayah', 'Nama Ayah'),
                self::f('tanggal_lahir_ayah', 'Tanggal Lahir Ayah', 'date'),
                self::f('nama_ibu', 'Nama Ibu'),
                self::f('tanggal_lahir_ibu', 'Tanggal Lahir Ibu', 'date'),
                self::f('ortu_alamat_rt', 'RT'),
                self::f('ortu_alamat_rw', 'RW'),
                self::f('ortu_alamat_kab_kota', 'Kabupaten/Kota'),
                self::f('ortu_alamat_kode_pos', 'Kode Pos'),
                self::f('ortu_telepon', 'Telepon'),
                self::f('ortu_hp', 'HP'),
            ]),
            self::section('mertua', '19. Data Mertua', [
                self::f('nama_ayah_mertua', 'Nama Ayah Mertua'),
                self::f('tanggal_lahir_ayah_mertua', 'Tanggal Lahir Ayah Mertua', 'date'),
                self::f('nama_ibu_mertua', 'Nama Ibu Mertua'),
                self::f('tanggal_lahir_ibu_mertua', 'Tanggal Lahir Ibu Mertua', 'date'),
                self::f('mertua_alamat_rt', 'RT'),
                self::f('mertua_alamat_rw', 'RW'),
                self::f('mertua_alamat_kab_kota', 'Kabupaten/Kota'),
                self::f('mertua_alamat_kode_pos', 'Kode Pos'),
                self::f('mertua_hp', 'HP'),
            ]),
            // Man Power Planning counts them as expected to be away (still Actual).
            self::section('kesehatan', '20. Sakit Berkepanjangan', [
                self::f('sakit_mulai', 'Sakit Berkepanjangan Sejak', 'date', hint: 'Kosongkan bila tidak sedang sakit berkepanjangan'),
                self::f('sakit_selesai', 'Perkiraan Kembali Bekerja', 'date'),
                self::f('sakit_keterangan', 'Keterangan'),
            ]),
        ];
    }

    /** Every editable (non-computed) field, keyed by column. */
    public static function editableFields(): array
    {
        $fields = [];
        foreach (self::build() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! ($field['computed'] ?? false)) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return $fields;
    }

    private static function section(string $id, string $title, array $fields): array
    {
        return ['id' => $id, 'title' => $title, 'fields' => $fields];
    }

    private static function f(string $key, string $label, string $type = 'text', ?array $options = null, bool $computed = false, ?string $hint = null): array
    {
        return array_filter(
            compact('key', 'label', 'type', 'options', 'computed', 'hint'),
            fn ($v) => $v !== null && $v !== false,
        );
    }
}
