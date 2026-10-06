/** Shapes of the Man Power props sent by App\Http\Controllers\Manpower\KaryawanController. */

export interface ColumnDef {
    key: string;
    label: string;
    /** Frozen to the left so the row stays identifiable while scrolling sideways. */
    sticky?: boolean;
}

export interface ColumnGroup {
    id: string;
    title: string;
    /** Cannot be hidden — carries the NRP / Nama identity columns. */
    locked?: boolean;
    columns: ColumnDef[];
}

export type KaryawanRow = Record<string, string | number | boolean | null> & { id: number; nrp: string; name: string };

export interface GroupCompleteness {
    id: string;
    title: string;
    /** null when no employee currently needs to fill this group. */
    percent: number | null;
    filled: number;
    total: number;
    applicable: number;
}

interface PersonRef {
    karyawan_id: number;
    nrp: string;
    name: string;
    jabatan: string;
}

export interface PkwtListItem extends PersonRef {
    periode_label: string;
    tanggal_kontrak: string;
    tanggal_berakhir: string;
    /** Negative once the contract has ended. */
    sisa_hari: number;
    period_count: number;
    next_start: string;
}

export interface PensiunItem extends PersonRef {
    tanggal_lahir: string;
    tanggal_pensiun: string;
    sisa_hari: number;
}

export interface UlangTahunItem extends PersonRef {
    tanggal_lahir: string;
    hari: number;
    usia_baru: number;
    hari_ini: boolean;
}

export interface DashboardData {
    today: string;
    total_aktif: number;
    completeness: { overall_percent: number | null; groups: GroupCompleteness[] };
    asal: { lokal: number; kiriman: number; belum_diisi: number };
    status_pegawai: { pkwt: number; tetap: number };
    pkwt_akan_berakhir: PkwtListItem[];
    pkwt_berakhir: PkwtListItem[];
    pensiun_age: number;
    pensiun: PensiunItem[];
    ulang_tahun_bulan: string;
    ulang_tahun: UlangTahunItem[];
}

export type ImportAction = 'baru' | 'perbarui' | 'tanpa-perubahan' | 'dilewati';

export interface FieldChange {
    group_id: string;
    group_title: string;
    label: string;
    before: string | null;
    after: string | null;
}

export interface ImportRowResult {
    /** Row number as it appears in Excel (the header is row 1). */
    row: number;
    nrp: string;
    nama: string;
    action: ImportAction;
    /** Why the row was skipped. */
    reason: string | null;
    changes: FieldChange[];
    /** Titles of the groups this row actually changes. */
    groups: string[];
    warnings: string[];
    completeness_before: number | null;
    completeness_after: number | null;
    /** percent is null when the group does not apply to this employee. */
    groups_after: { id: string; title: string; percent: number | null }[];
}

export interface ImportOutcome {
    dry_run: boolean;
    update_only: boolean;
    total_rows: number;
    created: number;
    updated: number;
    unchanged: number;
    rows: ImportRowResult[];
    recognized_groups: { id: string; title: string; columns: number }[];
    /** Headers in the file that match no known column (likely typos). */
    unknown_columns: string[];
    skipped: { row: number; reason: string }[];
}

export interface KeluarRow {
    id: number;
    nrp: string;
    name: string;
    jabatan_name: string;
    departemen_name: string;
    work_location_name: string;
    tanggal_masuk: string | null;
    tanggal_keluar: string | null;
    masa_kerja: string | null;
    alasan_keluar: string | null;
    keterangan_keluar: string | null;
}
