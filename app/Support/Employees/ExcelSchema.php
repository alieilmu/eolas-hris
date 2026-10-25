<?php

namespace App\Support\Employees;

use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\Organization\PositionComposer;

/**
 * The canonical column list shared by the Excel template, the export and the
 * import. Keeping one source for all three is what makes an exported file
 * re-importable. Keys match KaryawanRow / ColumnGroups.
 */
class ExcelSchema
{
    public const DATA_SHEET = 'Employee Data';

    /** The data sheet's name in files exported before the headers were in English. */
    public const LEGACY_DATA_SHEET = 'Data Karyawan';

    /** Identity column — every row is matched to an employee by this. */
    public const KEY_LABEL = 'Employee Number';

    /** The position title: composed from the placement columns, never typed. */
    public const POSITION_LABEL = 'Position Name';

    /** Suffix of the system-calculated columns in an export; ignored on import. */
    public const AUTO_SUFFIX = ' (auto)';

    private const LEGACY_AUTO_SUFFIX = ' (otomatis)';

    /** Group bands of the data sheet (row 1), by group id. */
    public const GROUP_TITLES = [
        'identity' => '1. Identity & Job',
        'birth_info' => '2. Birth & Service',
        'employment' => '3. Employment & Marital Status',
        'grade' => '4. Grade & Entitlements',
        'contract' => '5. Employment Contracts (PKWT)',
        'appointment' => '6. Permanent Appointment',
        'uniform' => '7. Uniform & PPE Sizes',
        'identification' => '8. Identification & Memberships',
        'bank_account' => '9. Bank Account',
        'personal' => '10. Personal Data',
        'education_level' => '11. Education',
        'residential_address' => '12. Residential Address',
        'mailing_address' => '13. Mailing Address',
        'contact' => '14. Emergency & Personal Contacts',
        'email' => '15. Email',
        'spouse' => '16. Spouse',
        'children' => '17. Children',
        'parents' => '18. Parents',
        'in_laws' => '19. Parents-in-Law',
    ];

    /** Header words written in capitals. */
    private const ACRONYMS = ['sap' => 'SAP', 'id' => 'ID', 'bpjs' => 'BPJS', 'rt' => 'RT', 'rw' => 'RW', 'ppe' => 'PPE', 'no' => 'No.', 'of' => 'of', 'in' => 'in', 'law' => 'Law'];

    /** Headers that read better than the key spelled out. */
    private const HEADERS = [
        'work_location_name' => 'Work Location',
        'sub_location_name' => 'Sub Location',
        'directorate_name' => 'Directorate',
        'division_name' => 'Division',
        'department_name' => 'Department',
        'section_name' => 'Section',
        'discipline' => 'Discipline (Position Name)',
        'medical_benefit_limit' => 'Medical Benefit Limit',
        'email' => 'Work Email',
        'phone' => 'Mobile Number',
    ];

    /** Highest child block the importer looks for. */
    public const MAX_ANAK_COLUMNS = 20;

    /** Marks a required column header; stripped again when reading a file. */
    public const REQUIRED_MARK = ' *';

    /**
     * Groups 1 (Identitas & Pekerjaan) and 2 (Kelahiran & Masa Kerja) must be
     * complete for every imported employee — from the file, or already stored.
     */
    public const REQUIRED_KEYS = [
        'sequence_no', 'employee_number', 'name', 'sap_employee_number', 'work_location_name', 'job_level', 'employee_class', 'technical_class',
        'birth_place', 'birth_date', 'hire_date',
    ];

    /** Derived on read, or owned by another table — shown in exports, never written back. */
    private const READ_ONLY = [
        'age', 'years_of_service', 'contract_end_status',
        // Composed from Work Location + SubGroup + unit / bidang.
        'position_name',
        // Read from the NRP.
        'employee_category', 'hire_location', 'origin_status',
        // From Pengaturan Kompensasi: SubGroup + status keluarga.
        'medical_benefit_limit',
    ];

    private const NUMBER_KEYS = ['sequence_no', 'graduation_year', 'medical_benefit_limit'];

    private const BOOLEAN_KEYS = ['is_permanent'];

    /** Official numbers whose leading zeros must survive: written as text. */
    public const TEXT_NUMBER_KEYS = [
        'employee_number', 'sap_employee_number', 'national_id_number', 'tax_id_number', 'bpjs_employment_number', 'bpjs_health_number', 'bank_account_number',
        'phone', 'whatsapp_number', 'emergency_contact_phone', 'mailing_phone', 'mailing_mobile', 'parents_phone', 'parents_mobile', 'in_laws_mobile',
    ];

    /**
     * @return list<array{key: string, label: string, group_id: string, type: string, read_only: bool}>
     */
    public static function columns(int $anakCount = ColumnGroups::MIN_ANAK_COLUMNS): array
    {
        $columns = [];
        foreach (ColumnGroups::build($anakCount) as $group) {
            foreach ($group['columns'] as $col) {
                $columns[] = [
                    'key' => $col['key'],
                    'label' => self::header($col['key']),
                    'group_id' => $group['id'],
                    'type' => self::typeOf($col['key']),
                    'read_only' => in_array($col['key'], self::READ_ONLY, true),
                ];
            }
        }

        return $columns;
    }

    /** The English column header of a key: "bpjs_health_number" → "BPJS Health Number", "child_2_name" → "Child 2 Name". */
    public static function header(string $key): string
    {
        return self::HEADERS[$key] ?? implode(' ', array_map(fn (string $w) => self::ACRONYMS[$w] ?? ucfirst($w), explode('_', $key)));
    }

    /**
     * Indonesian header => English header, so files exported before the headers were
     * translated still import. Those labels were the screen labels, prefixed with the
     * group name where several groups shared one ("Keluarga Inti — Tanggal Lahir").
     *
     * @return array<string, string>
     */
    public static function legacyHeaders(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $groups = ColumnGroups::build(self::MAX_ANAK_COLUMNS);
        $map = [];
        $labelCount = array_count_values(array_merge(...array_map(fn (array $g) => array_column($g['columns'], 'label'), $groups)));
        foreach ($groups as $group) {
            $shortTitle = preg_replace(['/^\d+\.\s*/', '/\s*\(.*\)$/'], '', $group['title']);
            foreach ($group['columns'] as $col) {
                $old = $labelCount[$col['label']] > 1 && $group['id'] !== 'birth_info' ? "{$shortTitle} — {$col['label']}" : $col['label'];
                $map[$old] = self::header($col['key']);
                $map[$old.self::LEGACY_AUTO_SUFFIX] = self::header($col['key']).self::AUTO_SUFFIX;
            }
        }

        return $map;
    }

    /** Header text as written to a file: required columns carry the * mark. */
    public static function headerText(array $column): string
    {
        return in_array($column['key'], self::REQUIRED_KEYS, true) ? $column['label'].self::REQUIRED_MARK : $column['label'];
    }

    /** A header as read from a file, back to the plain label (tolerates "Nama*", "Nama *", "Nama  "). */
    public static function normalizeHeader(mixed $header): string
    {
        return is_string($header) ? rtrim(trim($header), ' *') : ($header === null ? '' : (string) $header);
    }

    /** Normalized, and an Indonesian header of an older file read as its English one. */
    public static function readHeader(mixed $header, array $legacy): string
    {
        $h = self::normalizeHeader($header);

        return $legacy[$h] ?? $h;
    }

    /** @return list<array{key: string, label: string, group_id: string, type: string, read_only: bool}> */
    public static function writable(int $anakCount = ColumnGroups::MIN_ANAK_COLUMNS): array
    {
        return array_values(array_filter(self::columns($anakCount), fn (array $c) => ! $c['read_only']));
    }

    private static function typeOf(string $key): string
    {
        return match (true) {
            in_array($key, self::NUMBER_KEYS, true) => 'number',
            in_array($key, self::BOOLEAN_KEYS, true) => 'boolean',
            str_ends_with($key, '_date') => 'date',
            default => 'text',
        };
    }

    /** Fixed choice lists, mirrored from the profile form, for the "Referensi" sheet. */
    public static function referenceEnums(): array
    {
        $lists = [
            self::header('work_location_name') => WorkLocation::orderBy('sort_order')->pluck('name')->all(),
            self::header('job_level') => JobLevel::orderByDesc('layer')->pluck('name')->all(),
            self::header('is_permanent') => ['Ya', 'Tidak'],
        ];
        foreach (LookupValues::CATEGORIES as $category => $config) {
            // Nama Jabatan is a long list: it has its own table on the sheet.
            if (! in_array($category, ['termination_reason', 'cash_advance_status', PositionComposer::BIDANG], true)) {
                $lists[$category === 'child_education_level' ? 'Child N Education Level' : self::header($category)] = LookupValues::values($category);
            }
        }

        return $lists + [
            self::header('gender') => ProfileSections::JENIS_KELAMIN,
            'Child N Employment Status' => ProfileSections::STATUS_BEKERJA_ANAK,
            'Child N Marital Status' => ProfileSections::STATUS_MENIKAH_ANAK,
        ];
    }

    /** @return list<string> sheet "Petunjuk" of the full template / export */
    public static function importInstructions(): array
    {
        $key = self::KEY_LABEL;
        $position = self::POSITION_LABEL;

        return [
            'PETUNJUK IMPORT DATA KARYAWAN',
            '',
            'Susunan sheet "Employee Data" sama dengan tabel Data Lengkap di website (judul kolom dalam bahasa Inggris):',
            '   • Baris 1 = nama kelompok data, baris 2 = judul kolom, data mulai baris 3.',
            '   • Kolom Sequence No., Employee Number (NRP) dan Name dibekukan agar tetap terlihat saat digeser ke samping.',
            '',
            '1. Kolom kunci',
            "   • \"{$key}\" (NRP) wajib diisi di setiap baris. Baris dicocokkan ke karyawan lewat NRP.",
            '   • Jika NRP sudah ada  → data karyawan tersebut DIPERBARUI.',
            '   • Jika NRP belum ada  → karyawan BARU dibuat (wajib isi Name, Work Location, Job Level, dan kolom unit-nya).',
            '',
            '   STRUKTUR NRP (8 digit) — untuk karyawan baru, NRP yang tidak sesuai akan DITOLAK:',
            '   • Digit 1     : jenis karyawan      (lihat sheet Referensi, mis. 1 = Karyawan Normal)',
            '   • Digit 2–3   : lokasi penerimaan   (lihat sheet Referensi, mis. 15 = Murung Raya)',
            '   • Digit 4–5   : tahun masuk          (mis. 24 = 2024; sebaiknya sama dengan Hire Date)',
            '   • Digit 6–8   : nomor urut',
            '   • Employee Category, Hire Location dan Origin Status terisi otomatis dari NRP.',
            '     Status Asal = Lokal bila lokasi penerimaan sama dengan Work Location karyawan, selain itu Kiriman.',
            '',
            '   KOLOM WAJIB (judulnya bertanda * dan berwarna ungu):',
            '   • Kelompok 1 Identity & Job: Sequence No., Employee Number, Name, SAP Employee Number, Work Location,',
            '     Job Level, Employee Class, Technical Class — plus kolom unit yang diminta Job Level-nya (lihat no. 2).',
            '   • Kelompok 2 Birth & Service: Birth Place, Birth Date, Hire Date.',
            '   • Karyawan baru harus mengisi semuanya di file. Untuk karyawan yang sudah ada, kolom wajib',
            '     yang kosong boleh dilewati selama datanya sudah tersimpan di sistem.',
            '   • Baris yang kolom wajibnya belum lengkap akan dilewati, dengan daftar kolom yang kurang.',
            '',
            '2. Penempatan: Work Location, Job Level dan unit — nama Jabatan tersusun otomatis',
            '   • Isi Work Location (nama atau kodenya) dan Job Level. Job Level menentukan kolom mana lagi yang diisi:',
            '       - dinamai dari Directorate / Division / Department / Section → isi kolom itu,',
            '         mis. Section Head + Section PRODUKSI → SECTION HEAD PRODUKSI',
            '       - dinamai dari Work Location (mis. Project Manager) → tidak perlu kolom unit → PROJECT MANAGER RMTU',
            '       - Nama Jabatan → isi "Discipline (Position Name)" dari daftar di sheet Referensi; Department/Section opsional,',
            '         mis. Officer/Group Leader + HSE → OFFICER HSE (di Head Office) atau GROUP LEADER HSE (di site)',
            '   • Aturan tiap Job Level ada di sheet Referensi. Kolom Department hanya perlu diisi untuk Section',
            '     yang namanya ada di beberapa departemen.',
            '   • Sub Location diisi bila karyawan ditugaskan di luar site induknya (nama Sub Lokasi dari Master Data).',
            "   • Kolom \"{$position}\" terisi otomatis bila jabatan yang cocok sudah ada di Plan Struktur Organisasi lokasi itu;",
            '     bila belum, karyawan tetap tersimpan dan menunggu ditempatkan di menu Struktur Organisasi.',
            '',
            '3. Yang TIDAK perlu diisi (terisi otomatis oleh sistem)',
            '   • Age, Years Of Service, Contract End Status, Position Name,',
            '     Employee Category, Hire Location, Origin Status, Medical Benefit Limit.',
            '   • Kolom-kolom ini tidak ada di template. Di file hasil Export muncul di paling kanan',
            '     dengan tanda "(auto)" hanya sebagai informasi — diabaikan saat import.',
            '   • Karyawan baru otomatis berstatus Aktif; karyawan keluar dicatat lewat menu Karyawan Keluar.',
            '',
            '   Setiap judul kolom unik, mis. "Residential RT", "Parents RT", "Spouse Birth Date". Jangan ubah nama kolomnya.',
            '   File lama dengan judul kolom bahasa Indonesia tetap bisa di-import.',
            '',
            '4. Format isian',
            '   • Tanggal      : DD.MM.YYYY (contoh 15.05.1990); YYYY-MM-DD, DD/MM/YYYY dan format tanggal Excel juga diterima.',
            '   • Is Permanent : Ya / Tidak',
            '   • Kolom pilihan (Employee Class, Religion, Education Level, Size, dst.) harus sesuai daftar di sheet',
            '     Referensi (huruf besar/kecil bebas); nilai di luar daftar membuat baris dilewati.',
            '   • National ID Number (NIK): 16 digit angka, unik antar karyawan.',
            '   • Tax ID Number (NPWP)    : 15 atau 16 digit angka, unik antar karyawan.',
            '   • BPJS Employment / Health: angka saja, unik antar karyawan.',
            '   • Kolom Employee Number, NIK, NPWP, BPJS, rekening & nomor telepon di file ini sudah berformat "Teks"',
            '     agar angka 0 di depan tidak hilang. Pertahankan format itu saat menambah baris.',
            '',
            '5. Aturan lain',
            '   • Kolom kosong dibiarkan kosong — tidak akan menimpa data yang sudah ada.',
            '   • Baris yang datanya bermasalah akan dilewati; baris lain tetap diproses.',
            '   • Sebelum tersimpan, sistem menampilkan pratinjau per baris. Data baru tersimpan setelah Konfirmasi.',
            '   • Jangan mengubah baris kelompok & judul kolom (baris 1–2 sheet Employee Data).',
            '',
            '6. Cara termudah',
            '   • Klik Export di halaman Data Lengkap, edit file hasilnya, lalu import kembali.',
            '   • Kolom isian di file export sudah sama persis dengan yang dibutuhkan import.',
        ];
    }

    /** @return list<string> sheet "Petunjuk" of the per-group update template */
    public static function groupUpdateInstructions(): array
    {
        $key = self::KEY_LABEL;

        return [
            'PETUNJUK UPDATE DATA PER KELOMPOK',
            '',
            'File ini hanya berisi kolom dari kelompok data yang Anda pilih, supaya pembaruan cukup singkat.',
            '',
            '1. Cara kerja',
            "   • Setiap baris dicocokkan ke karyawan lewat \"{$key}\".",
            '   • Hanya kolom yang ada di file ini yang diperbarui — data kelompok lain TIDAK tersentuh.',
            '   • Sel yang dikosongkan tidak menghapus data lama.',
            '   • Kolom "Name" hanya sebagai penanda; ubah hanya jika memang ingin mengganti nama.',
            '   • NRP yang belum terdaftar akan dilewati (karyawan baru ditambah lewat template lengkap).',
            '   • Kolom wajib kelompok 1 & 2 (bertanda *) tidak perlu ada di file ini selama datanya sudah',
            '     tersimpan; karyawan yang datanya belum lengkap akan dilewati dengan keterangan.',
            '',
            '2. Sebelum tersimpan',
            '   • Setelah file dipilih, sistem menampilkan pratinjau: data yang terbaca, perubahan per kolom,',
            '     dan status kelengkapan. Data baru tersimpan setelah Anda menekan Konfirmasi.',
            '',
            '3. Perhatian khusus',
            '   • Kelompok Data Anak dan Kontrak Kerja (PKWT): jika ada isinya di file, seluruh daftar lama diganti.',
            '     Jadi cantumkan SEMUA anak / periode, bukan hanya yang baru.',
            '',
            '4. Format isian',
            '   • Tanggal      : DD.MM.YYYY (contoh 15.05.1990); YYYY-MM-DD, DD/MM/YYYY dan format tanggal Excel juga diterima.',
            '   • Is Permanent : Ya / Tidak',
            '   • Pilihan nilai yang valid ada di sheet Referensi.',
        ];
    }
}
