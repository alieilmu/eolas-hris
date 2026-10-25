<?php

namespace App\Support\Employees;

use App\Models\Grade;
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
        'employee_class' => 'employee_class',
        'technical_class' => 'technical_class',
        'marital_status' => 'marital_status',
        'shirt_size' => 'shirt_size',
        'pants_size' => 'pants_size',
        'shoe_size' => 'shoe_size',
        'ppe_size' => 'ppe_size',
        'religion' => 'religion',
        'education_level' => 'education_level',
    ];

    /** Choices of a select field, whether fixed or from the master data. */
    public static function optionsFor(string $key): array
    {
        return match ($key) {
            'gender' => self::JENIS_KELAMIN,
            'work_location' => WorkLocation::orderBy('sort_order')->pluck('name')->all(),
            // Pengaturan Kompensasi.
            'grade' => Grade::codes(),
            default => LookupValues::values(self::MASTER_LISTS[$key]),
        };
    }

    /**
     * @return list<array{id: string, title: string, kind?: string, fields?: list<array{key: string, label: string, type: string, options?: list<string>, computed?: bool, hint?: string}>}>
     */
    public static function build(): array
    {
        return [
            self::section('identity', '1. Identitas & Pekerjaan', [
                self::f('sequence_no', 'No. Urut', 'number'),
                self::f('sap_employee_number', 'NRP SAP', hint: 'Kunci integrasi dengan sistem SAP'),
                self::f('employee_category', 'Jenis Karyawan', computed: true, hint: 'Dari digit ke-1 NRP'),
                // Part of the placement (the position belongs to a work location): changed with "Ubah Penempatan".
                self::f('work_location', 'Lokasi Kerja', computed: true, hint: 'Diubah lewat Ubah Penempatan; menentukan Status Asal'),
                self::f('employee_class', 'Klasifikasi', 'select', self::optionsFor('employee_class')),
                self::f('technical_class', 'Klasifikasi Teknis', 'select', self::optionsFor('technical_class')),
            ]),
            self::section('birth_info', '2. Kelahiran & Masa Kerja', [
                self::f('birth_place', 'Tempat Lahir'),
                self::f('birth_date', 'Tanggal Lahir', 'date'),
                self::f('hire_date', 'Tanggal Masuk Kerja', 'date'),
                self::f('age', 'Usia', computed: true),
                self::f('years_of_service', 'Masa Kerja', computed: true),
            ]),
            self::section('employment', '3. Status Pegawai & Perkawinan', [
                self::f('employment_status', 'Status Pegawai'),
                self::f('marital_status', 'Status Perkawinan', 'select', self::optionsFor('marital_status')),
                self::f('ptkp_status', 'Status Keluarga', computed: true, hint: 'K = Kawin, TK = Tidak Kawin, lalu jumlah anak terdaftar (mis. K/2). Menentukan plafon Lajang / Keluarga.'),
                self::f('marriage_date', 'Tanggal Perkawinan', 'date'),
            ]),
            self::section('grade', '4. Golongan & Hak Normatif', [
                self::f('grade', 'Golongan', 'select', self::optionsFor('grade'), hint: 'Dari Pengaturan Kompensasi'),
                self::f('medical_benefit_limit', 'Plafon Pengobatan', computed: true, hint: 'Otomatis dari Pengaturan Kompensasi: SubGroup + Status Keluarga'),
                self::f('hire_location', 'Tempat Penerimaan', computed: true, hint: 'Dari digit ke-2–3 NRP'),
                self::f('origin_status', 'Status Asal', computed: true, hint: 'Lokal bila lokasi penerimaan = lokasi kerja jabatan'),
            ]),
            ['id' => 'contract', 'title' => '5. Kontrak Kerja (PKWT)', 'kind' => 'contract'],
            self::section('appointment', '6. Pengangkatan Pegawai', [
                self::f('is_permanent', 'Sudah Diangkat Pegawai Tetap', 'checkbox'),
                self::f('appointment_letter_number', 'Nomor SK'),
                self::f('appointment_letter_date', 'Tanggal SK', 'date'),
                self::f('contract_end_status', 'Status Akhir PKWT', computed: true),
            ]),
            self::section('uniform', '7. Ukuran Seragam & APD', [
                self::f('shirt_size', 'Ukuran Baju', 'select', self::optionsFor('shirt_size')),
                self::f('pants_size', 'Ukuran Celana', 'select', self::optionsFor('pants_size')),
                self::f('shoe_size', 'Ukuran Sepatu', 'select', self::optionsFor('shoe_size')),
                self::f('ppe_size', 'Ukuran APD', 'select', self::optionsFor('ppe_size')),
            ]),
            self::section('identification', '8. Tanda Pengenal & Kepesertaan', [
                self::f('national_id_number', 'NIK (KTP)', hint: '16 digit angka, unik antar karyawan'),
                self::f('national_id_province', 'Provinsi sesuai NIK'),
                self::f('national_id_city', 'Kabupaten/Kota sesuai NIK'),
                self::f('tax_id_number', 'NPWP', hint: '15 atau 16 digit angka'),
                self::f('tax_id_registered_date', 'Tanggal Terdaftar NPWP', 'date'),
                self::f('bpjs_employment_number', 'BPJS Ketenagakerjaan'),
                self::f('bpjs_employment_registered_date', 'Tanggal Terdaftar BPJS TK', 'date'),
                self::f('bpjs_health_number', 'BPJS Kesehatan'),
            ]),
            self::section('bank_account', '9. Rekening Bank', [
                self::f('bank_account_number', 'Nomor Rekening'),
                self::f('bank_name', 'Nama Bank'),
                self::f('bank_account_holder', 'Nama Pemilik Rekening', hint: 'Dicocokkan dengan nama karyawan untuk mencegah salah transfer'),
            ]),
            self::section('personal', '10. Data Pribadi', [
                self::f('religion', 'Agama', 'select', self::optionsFor('religion')),
                self::f('gender', 'Jenis Kelamin', 'select', self::JENIS_KELAMIN),
                self::f('ethnicity', 'Suku Bangsa'),
            ]),
            self::section('education_level', '11. Riwayat Pendidikan', [
                self::f('education_level', 'Pendidikan Terakhir', 'select', self::optionsFor('education_level')),
                self::f('education_major', 'Jurusan'),
                self::f('education_institution', 'Asal Sekolah/Universitas'),
                self::f('graduation_year', 'Tahun Lulus', 'number'),
            ]),
            self::section('residential_address', '12. Alamat Tinggal', [
                self::f('residential_address', 'Alamat', 'textarea'),
                self::f('residential_rt', 'RT'),
                self::f('residential_rw', 'RW'),
                self::f('residential_city', 'Kabupaten/Kota'),
                self::f('residential_province', 'Provinsi'),
                self::f('residential_postal_code', 'Kode Pos'),
            ]),
            self::section('mailing_address', '13. Alamat Surat Menyurat', [
                self::f('mailing_address', 'Alamat', 'textarea'),
                self::f('mailing_rt', 'RT'),
                self::f('mailing_rw', 'RW'),
                self::f('mailing_city', 'Kabupaten/Kota'),
                self::f('mailing_postal_code', 'Kode Pos'),
                self::f('mailing_phone', 'Telepon'),
                self::f('mailing_mobile', 'HP'),
            ]),
            self::section('contact', '14. Kontak Darurat & Nomor Pribadi', [
                self::f('emergency_contact_phone', 'Nomor Telepon Darurat'),
                self::f('emergency_contact_name', 'Nama Pemilik Nomor'),
                self::f('emergency_contact_relationship', 'Hubungan dengan Karyawan'),
                self::f('phone', 'Nomor HP'),
                self::f('whatsapp_number', 'Nomor WhatsApp'),
            ]),
            self::section('email', '15. Email', [
                self::f('personal_email', 'Email (Gmail)'),
            ]),
            self::section('spouse', '16. Keluarga Inti (Istri/Suami)', [
                self::f('spouse_name', 'Nama Istri/Suami'),
                self::f('spouse_birth_date', 'Tanggal Lahir', 'date'),
                self::f('spouse_occupation', 'Pekerjaan'),
            ]),
            ['id' => 'children', 'title' => '17. Data Anak', 'kind' => 'children'],
            self::section('parents', '18. Data Orang Tua', [
                self::f('father_name', 'Nama Ayah'),
                self::f('father_birth_date', 'Tanggal Lahir Ayah', 'date'),
                self::f('mother_name', 'Nama Ibu'),
                self::f('mother_birth_date', 'Tanggal Lahir Ibu', 'date'),
                self::f('parents_rt', 'RT'),
                self::f('parents_rw', 'RW'),
                self::f('parents_city', 'Kabupaten/Kota'),
                self::f('parents_postal_code', 'Kode Pos'),
                self::f('parents_phone', 'Telepon'),
                self::f('parents_mobile', 'HP'),
            ]),
            self::section('in_laws', '19. Data Mertua', [
                self::f('father_in_law_name', 'Nama Ayah Mertua'),
                self::f('father_in_law_birth_date', 'Tanggal Lahir Ayah Mertua', 'date'),
                self::f('mother_in_law_name', 'Nama Ibu Mertua'),
                self::f('mother_in_law_birth_date', 'Tanggal Lahir Ibu Mertua', 'date'),
                self::f('in_laws_rt', 'RT'),
                self::f('in_laws_rw', 'RW'),
                self::f('in_laws_city', 'Kabupaten/Kota'),
                self::f('in_laws_postal_code', 'Kode Pos'),
                self::f('in_laws_mobile', 'HP'),
            ]),
            // Man Power Planning counts them as expected to be away (still Actual).
            self::section('health', '20. Sakit Berkepanjangan', [
                self::f('long_sick_start_date', 'Sakit Berkepanjangan Sejak', 'date', hint: 'Kosongkan bila tidak sedang sakit berkepanjangan'),
                self::f('long_sick_end_date', 'Perkiraan Kembali Bekerja', 'date'),
                self::f('long_sick_notes', 'Keterangan'),
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
