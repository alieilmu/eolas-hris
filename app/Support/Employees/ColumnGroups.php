<?php

namespace App\Support\Employees;

use App\Models\EmployeeProfile;
use App\Models\EmploymentContract;

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
                self::col('last_contract_sequence', 'PKWT Terakhir'),
                self::col('last_contract_start_date', 'Tgl Kontrak'),
                self::col('last_contract_end_date', 'Tgl Berakhir'),
            ]
            : collect(range(1, EmployeeProfile::PKWT_MAX_PERIODS))->flatMap(fn (int $i) => [
                self::col("contract_{$i}_start_date", EmploymentContract::label($i).' — Tgl Kontrak'),
                self::col("contract_{$i}_end_date", EmploymentContract::label($i).' — Tgl Berakhir'),
            ])->all();
        $pkwtColumns[] = self::col('contract_end_status', 'Status Akhir PKWT');

        $anakColumns = collect(range(1, max($anakCount, self::MIN_ANAK_COLUMNS)))->flatMap(fn (int $i) => [
            self::col("child_{$i}_name", "Anak {$i} — Nama"),
            self::col("child_{$i}_birth_date", "Anak {$i} — Tgl Lahir"),
            self::col("child_{$i}_education_level", "Anak {$i} — Pendidikan"),
            self::col("child_{$i}_employment_status", "Anak {$i} — Status Bekerja"),
            self::col("child_{$i}_marital_status", "Anak {$i} — Status Menikah"),
        ])->all();

        return [
            [
                'id' => 'identity',
                'title' => '1. Identitas & Pekerjaan',
                // Always shown: carries the NRP / Nama columns every row is read by.
                'locked' => true,
                'columns' => [
                    self::col('sequence_no', 'No. Urut'),
                    ['key' => 'employee_number', 'label' => 'NRP', 'sticky' => true],
                    ['key' => 'name', 'label' => 'Nama', 'sticky' => true],
                    self::col('sap_employee_number', 'NRP SAP'),
                    self::col('employee_category', 'Jenis Karyawan'),
                    // Placement: the position title is composed from these (see JabatanComposer).
                    self::col('work_location_name', 'Work Location'),
                    self::col('sub_location_name', 'Sub Lokasi Kerja'),
                    self::col('job_level', 'SubGroup'),
                    self::col('position_name', 'Jabatan'),
                    self::col('directorate_name', 'BOD'),
                    self::col('division_name', 'Divisi'),
                    self::col('department_name', 'Departemen'),
                    self::col('section_name', 'Section'),
                    self::col('discipline', 'Nama Jabatan (Bidang)'),
                    self::col('employee_class', 'Klasifikasi'),
                    self::col('technical_class', 'Klasifikasi Teknis'),
                ],
            ],
            self::group('birth_info', '2. Kelahiran & Masa Kerja', [
                'birth_place' => 'Tempat Lahir',
                'birth_date' => 'Tanggal Lahir',
                'hire_date' => 'Tanggal Masuk',
                'age' => 'Usia',
                'years_of_service' => 'Masa Kerja',
            ]),
            self::group('employment', '3. Status Pegawai & Perkawinan', [
                'employment_status' => 'Status Pegawai',
                'marital_status' => 'Status Perkawinan',
                'marriage_date' => 'Tanggal Perkawinan',
            ]),
            self::group('grade', '4. Golongan & Hak Normatif', [
                'grade' => 'Golongan',
                'medical_benefit_limit' => 'Plafon Pengobatan',
                'hire_location' => 'Tempat Penerimaan',
                'origin_status' => 'Status Asal',
            ]),
            ['id' => 'contract', 'title' => '5. Kontrak Kerja (PKWT)', 'columns' => $pkwtColumns],
            self::group('appointment', '6. Pengangkatan Pegawai', [
                'is_permanent' => 'Pegawai Tetap',
                'appointment_letter_number' => 'Nomor SK',
                'appointment_letter_date' => 'Tanggal SK',
            ]),
            self::group('uniform', '7. Ukuran Seragam & APD', [
                'shirt_size' => 'Ukuran Baju',
                'pants_size' => 'Ukuran Celana',
                'shoe_size' => 'Ukuran Sepatu',
                'ppe_size' => 'Ukuran APD',
            ]),
            self::group('identification', '8. Tanda Pengenal & Kepesertaan', [
                'national_id_number' => 'NIK (KTP)',
                'national_id_province' => 'Provinsi (NIK)',
                'national_id_city' => 'Kab/Kota (NIK)',
                'tax_id_number' => 'NPWP',
                'tax_id_registered_date' => 'Tgl Terdaftar NPWP',
                'bpjs_employment_number' => 'BPJS Ketenagakerjaan',
                'bpjs_employment_registered_date' => 'Tgl Terdaftar BPJS TK',
                'bpjs_health_number' => 'BPJS Kesehatan',
            ]),
            self::group('bank_account', '9. Rekening Bank', [
                'bank_account_number' => 'Nomor Rekening',
                'bank_name' => 'Nama Bank',
                'bank_account_holder' => 'Nama Pemilik Rekening',
            ]),
            self::group('personal', '10. Data Pribadi', [
                'religion' => 'Agama',
                'gender' => 'Jenis Kelamin',
                'ethnicity' => 'Suku Bangsa',
            ]),
            self::group('education_level', '11. Riwayat Pendidikan', [
                'education_level' => 'Pendidikan Terakhir',
                'education_major' => 'Jurusan',
                'education_institution' => 'Asal Sekolah/Universitas',
                'graduation_year' => 'Tahun Lulus',
            ]),
            self::group('residential_address', '12. Alamat Tinggal', [
                'residential_address' => 'Alamat',
                'residential_rt' => 'RT',
                'residential_rw' => 'RW',
                'residential_city' => 'Kabupaten/Kota',
                'residential_province' => 'Provinsi',
                'residential_postal_code' => 'Kode Pos',
            ]),
            self::group('mailing_address', '13. Alamat Surat Menyurat', [
                'mailing_address' => 'Alamat',
                'mailing_rt' => 'RT',
                'mailing_rw' => 'RW',
                'mailing_city' => 'Kabupaten/Kota',
                'mailing_postal_code' => 'Kode Pos',
                'mailing_phone' => 'Telepon',
                'mailing_mobile' => 'HP',
            ]),
            self::group('contact', '14. Kontak Darurat & Nomor Pribadi', [
                'emergency_contact_phone' => 'Telepon Darurat',
                'emergency_contact_name' => 'Nama Pemilik Nomor',
                'emergency_contact_relationship' => 'Hubungan',
                'phone' => 'Nomor HP',
                'whatsapp_number' => 'Nomor WhatsApp',
            ]),
            self::group('email', '15. Email', [
                'email' => 'Email Kantor',
                'personal_email' => 'Email (Gmail)',
            ]),
            self::group('spouse', '16. Keluarga Inti', [
                'spouse_name' => 'Nama Istri/Suami',
                'spouse_birth_date' => 'Tanggal Lahir',
                'spouse_occupation' => 'Pekerjaan',
            ]),
            ['id' => 'children', 'title' => '17. Data Anak', 'columns' => $anakColumns],
            self::group('parents', '18. Data Orang Tua', [
                'father_name' => 'Nama Ayah',
                'father_birth_date' => 'Tgl Lahir Ayah',
                'mother_name' => 'Nama Ibu',
                'mother_birth_date' => 'Tgl Lahir Ibu',
                'parents_rt' => 'RT',
                'parents_rw' => 'RW',
                'parents_city' => 'Kabupaten/Kota',
                'parents_postal_code' => 'Kode Pos',
                'parents_phone' => 'Telepon',
                'parents_mobile' => 'HP',
            ]),
            self::group('in_laws', '19. Data Mertua', [
                'father_in_law_name' => 'Nama Ayah Mertua',
                'father_in_law_birth_date' => 'Tgl Lahir Ayah Mertua',
                'mother_in_law_name' => 'Nama Ibu Mertua',
                'mother_in_law_birth_date' => 'Tgl Lahir Ibu Mertua',
                'in_laws_rt' => 'RT',
                'in_laws_rw' => 'RW',
                'in_laws_city' => 'Kabupaten/Kota',
                'in_laws_postal_code' => 'Kode Pos',
                'in_laws_mobile' => 'HP',
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
