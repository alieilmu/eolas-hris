<?php

namespace App\Support\Karyawan;

use App\Models\SubGroup;
use App\Models\WorkLocation;
use App\Support\Org\JabatanComposer;

/**
 * The canonical column list shared by the Excel template, the export and the
 * import. Keeping one source for all three is what makes an exported file
 * re-importable. Keys match KaryawanRow / ColumnGroups.
 */
class ExcelSchema
{
    public const DATA_SHEET = 'Data Karyawan';

    /** Identity column — every row is matched to an employee by this. */
    public const KEY_LABEL = 'NRP';

    /** The position title: composed from the placement columns, never typed. */
    public const JABATAN_LABEL = 'Jabatan';

    /** Suffix of the system-calculated columns in an export; ignored on import. */
    public const AUTO_SUFFIX = ' (otomatis)';

    /** Highest child block the importer looks for. */
    public const MAX_ANAK_COLUMNS = 20;

    /** Marks a required column header; stripped again when reading a file. */
    public const REQUIRED_MARK = ' *';

    /**
     * Groups 1 (Identitas & Pekerjaan) and 2 (Kelahiran & Masa Kerja) must be
     * complete for every imported employee — from the file, or already stored.
     */
    public const REQUIRED_KEYS = [
        'no_urut', 'nrp', 'name', 'nrp_sap', 'work_location_name', 'sub_group', 'klasifikasi', 'klasifikasi_teknis',
        'tempat_lahir', 'tanggal_lahir', 'tanggal_masuk',
    ];

    /** Derived on read, or owned by another table — shown in exports, never written back. */
    private const READ_ONLY = [
        'usia', 'masa_kerja', 'status_akhir_pkwt',
        // Composed from Work Location + SubGroup + unit / bidang.
        'jabatan_name',
        // Read from the NRP.
        'jenis_karyawan', 'tempat_penerimaan', 'status_asal',
        // From Master Data Comben: SubGroup + status keluarga.
        'plafon_pengobatan',
    ];

    private const NUMBER_KEYS = ['no_urut', 'tahun_lulus', 'plafon_pengobatan'];

    private const BOOLEAN_KEYS = ['status_pegawai_tetap'];

    /** Official numbers whose leading zeros must survive: written as text. */
    public const TEXT_NUMBER_KEYS = [
        'nrp', 'nrp_sap', 'nik', 'npwp', 'bpjs_tk', 'bpjs_kesehatan', 'no_rekening',
        'no_hp', 'no_whatsapp', 'emergency_telepon', 'alamat_surat_telepon', 'alamat_surat_hp', 'ortu_telepon', 'ortu_hp', 'mertua_hp',
    ];

    /**
     * @return list<array{key: string, label: string, group_id: string, type: string, read_only: bool}>
     */
    public static function columns(int $anakCount = ColumnGroups::MIN_ANAK_COLUMNS): array
    {
        $groups = ColumnGroups::build($anakCount);

        // Columns are matched by their label alone (the group row above it is only
        // visual), so labels shared by several groups ("RT", "Kode Pos", "HP",
        // "Tanggal Lahir", ...) get the group name as a prefix; the employee's own
        // "Tanggal Lahir" stays plain.
        $labelCount = array_count_values(array_merge(...array_map(
            fn (array $g) => array_column($g['columns'], 'label'),
            $groups,
        )));

        $columns = [];
        foreach ($groups as $group) {
            $shortTitle = preg_replace(['/^\d+\.\s*/', '/\s*\(.*\)$/'], '', $group['title']);

            foreach ($group['columns'] as $col) {
                $columns[] = [
                    'key' => $col['key'],
                    'label' => $labelCount[$col['label']] > 1 && $group['id'] !== 'kelahiran'
                        ? "{$shortTitle} — {$col['label']}"
                        : $col['label'],
                    'group_id' => $group['id'],
                    'type' => self::typeOf($col['key']),
                    'read_only' => in_array($col['key'], self::READ_ONLY, true),
                ];
            }
        }

        return $columns;
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
            str_starts_with($key, 'tanggal_'),
            in_array($key, ['npwp_tanggal_daftar', 'bpjs_tk_tanggal_daftar'], true),
            (bool) preg_match('/_(kontrak|berakhir|tanggal_lahir)$/', $key) => 'date',
            default => 'text',
        };
    }

    /** Fixed choice lists, mirrored from the profile form, for the "Referensi" sheet. */
    public static function referenceEnums(): array
    {
        $lists = [
            'Work Location' => WorkLocation::orderBy('order')->pluck('name')->all(),
            'SubGroup' => SubGroup::orderByDesc('layer')->pluck('nama')->all(),
            'Pegawai Tetap' => ['Ya', 'Tidak'],
        ];
        foreach (MasterOptions::CATEGORIES as $kategori => $config) {
            // Nama Jabatan is a long list: it has its own table on the sheet.
            if (! in_array($kategori, ['alasan_keluar', JabatanComposer::BIDANG], true)) {
                $lists[$config['label']] = MasterOptions::values($kategori);
            }
        }

        return $lists + [
            'Jenis Kelamin' => ProfileSections::JENIS_KELAMIN,
            'Status Bekerja (Anak)' => ProfileSections::STATUS_BEKERJA_ANAK,
            'Status Menikah (Anak)' => ProfileSections::STATUS_MENIKAH_ANAK,
        ];
    }

    /** @return list<string> sheet "Petunjuk" of the full template / export */
    public static function importInstructions(): array
    {
        $key = self::KEY_LABEL;
        $jabatan = self::JABATAN_LABEL;

        return [
            'PETUNJUK IMPORT DATA KARYAWAN',
            '',
            'Susunan sheet "Data Karyawan" sama dengan tabel Data Lengkap di website:',
            '   • Baris 1 = nama kelompok data, baris 2 = judul kolom, data mulai baris 3.',
            '   • Kolom No. Urut, NRP dan Nama dibekukan agar tetap terlihat saat digeser ke samping.',
            '',
            '1. Kolom kunci',
            "   • \"{$key}\" wajib diisi di setiap baris. Baris dicocokkan ke karyawan lewat NRP.",
            '   • Jika NRP sudah ada  → data karyawan tersebut DIPERBARUI.',
            '   • Jika NRP belum ada  → karyawan BARU dibuat (wajib isi Nama, Work Location, SubGroup, dan kolom unit-nya).',
            '',
            '   STRUKTUR NRP (8 digit) — untuk karyawan baru, NRP yang tidak sesuai akan DITOLAK:',
            '   • Digit 1     : jenis karyawan      (lihat sheet Referensi, mis. 1 = Karyawan Normal)',
            '   • Digit 2–3   : lokasi penerimaan   (lihat sheet Referensi, mis. 15 = Murung Raya)',
            '   • Digit 4–5   : tahun masuk          (mis. 24 = 2024; sebaiknya sama dengan Tanggal Masuk)',
            '   • Digit 6–8   : nomor urut',
            '   • Jenis Karyawan, Tempat Penerimaan dan Status Asal terisi otomatis dari NRP.',
            '     Status Asal = Lokal bila lokasi penerimaan sama dengan Work Location karyawan, selain itu Kiriman.',
            '',
            '   KOLOM WAJIB (judulnya bertanda * dan berwarna ungu):',
            '   • Kelompok 1 Identitas & Pekerjaan: No. Urut, NRP, Nama, NRP SAP, Work Location,',
            '     SubGroup, Klasifikasi, Klasifikasi Teknis — plus kolom unit yang diminta SubGroup-nya (lihat no. 2).',
            '   • Kelompok 2 Kelahiran & Masa Kerja: Tempat Lahir, Tanggal Lahir, Tanggal Masuk.',
            '   • Karyawan baru harus mengisi semuanya di file. Untuk karyawan yang sudah ada, kolom wajib',
            '     yang kosong boleh dilewati selama datanya sudah tersimpan di sistem.',
            '   • Baris yang kolom wajibnya belum lengkap akan dilewati, dengan daftar kolom yang kurang.',
            '',
            '2. Penempatan: Work Location, SubGroup dan unit — nama Jabatan tersusun otomatis',
            '   • Isi Work Location (nama atau kodenya) dan SubGroup. SubGroup menentukan kolom mana lagi yang diisi:',
            '       - dinamai dari BOD / Divisi / Departemen / Section → isi kolom itu,',
            '         mis. Section Head + Section PRODUKSI → SECTION HEAD PRODUKSI',
            '       - dinamai dari Work Location (mis. Project Manager) → tidak perlu kolom unit → PROJECT MANAGER RMTU',
            '       - Nama Jabatan → isi "Nama Jabatan (Bidang)" dari daftar di sheet Referensi; Departemen/Section opsional,',
            '         mis. Officer/Group Leader + HSE → OFFICER HSE (di Head Office) atau GROUP LEADER HSE (di site)',
            '   • Aturan tiap SubGroup ada di sheet Referensi. Kolom Departemen hanya perlu diisi untuk Section',
            '     yang namanya ada di beberapa departemen.',
            '   • Sub Lokasi Kerja diisi bila karyawan ditugaskan di luar site induknya (nama Sub Lokasi dari Master Data).',
            "   • Kolom \"{$jabatan}\" terisi otomatis bila jabatan yang cocok sudah ada di Plan Struktur Organisasi lokasi itu;",
            '     bila belum, karyawan tetap tersimpan dan menunggu ditempatkan di menu Struktur Organisasi.',
            '',
            '3. Yang TIDAK perlu diisi (terisi otomatis oleh sistem)',
            '   • Usia, Masa Kerja, Status Akhir PKWT, Jabatan,',
            '     Jenis Karyawan, Tempat Penerimaan, Status Asal.',
            '   • Kolom-kolom ini tidak ada di template. Di file hasil Export muncul di paling kanan',
            '     dengan tanda "(otomatis)" hanya sebagai informasi — diabaikan saat import.',
            '   • Karyawan baru otomatis berstatus Aktif; karyawan keluar dicatat lewat menu Karyawan Keluar.',
            '',
            '   Kolom yang namanya sama di beberapa kelompok diberi awalan nama kelompok,',
            '   mis. "Alamat Tinggal — RT", "Data Orang Tua — RT". Jangan ubah nama kolomnya.',
            '',
            '4. Format isian',
            '   • Tanggal      : DD.MM.YYYY (contoh 15.05.1990); YYYY-MM-DD, DD/MM/YYYY dan format tanggal Excel juga diterima.',
            '   • Pegawai Tetap: Ya / Tidak',
            '   • Kolom pilihan (Klasifikasi, Agama, Pendidikan, Ukuran, dst.) harus sesuai daftar di sheet',
            '     Referensi (huruf besar/kecil bebas); nilai di luar daftar membuat baris dilewati.',
            '   • NIK          : 16 digit angka, unik antar karyawan.',
            '   • NPWP         : 15 atau 16 digit angka, unik antar karyawan.',
            '   • BPJS TK/Kes  : angka saja, unik antar karyawan.',
            '   • Kolom NRP, NIK, NPWP, BPJS, rekening & nomor telepon di file ini sudah berformat "Teks"',
            '     agar angka 0 di depan tidak hilang. Pertahankan format itu saat menambah baris.',
            '',
            '5. Aturan lain',
            '   • Kolom kosong dibiarkan kosong — tidak akan menimpa data yang sudah ada.',
            '   • Baris yang datanya bermasalah akan dilewati; baris lain tetap diproses.',
            '   • Sebelum tersimpan, sistem menampilkan pratinjau per baris. Data baru tersimpan setelah Konfirmasi.',
            '   • Jangan mengubah baris kelompok & judul kolom (baris 1–2 sheet Data Karyawan).',
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
            '   • Kolom "Nama" hanya sebagai penanda; ubah hanya jika memang ingin mengganti nama.',
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
            '   • Pegawai Tetap: Ya / Tidak',
            '   • Pilihan nilai yang valid ada di sheet Referensi.',
        ];
    }
}
