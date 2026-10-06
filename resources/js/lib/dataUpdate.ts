import { fmtDate } from '@/lib/format';
import type { LampiranFile } from '@/lib/lampiran';

export type FieldValue = string | number | boolean | null;

export interface ProfileField {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'select' | 'checkbox' | 'textarea';
    options?: string[];
    hint?: string;
}

export interface ProfileSection {
    id: string;
    title: string;
    kind?: 'anak';
    fields?: ProfileField[];
}

export interface AnakData {
    id?: number | null;
    nama: string | null;
    tanggal_lahir: string | null;
    pendidikan: string | null;
    status_bekerja: string | null;
    status_menikah: string | null;
}

export interface AnakOp {
    aksi: 'tambah' | 'ubah' | 'hapus';
    anak_id: number | null;
    before: AnakData | null;
    after: AnakData | null;
}

export type Status = 'diajukan' | 'disetujui' | 'ditolak';

/** A Pengajuan Update Data as the server lists it. */
export interface PengajuanRow {
    id: number;
    karyawan_id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    work_location: string | null;
    perubahan: Record<string, { before: FieldValue; after: FieldValue }>;
    anak: AnakOp[];
    jumlah: number;
    catatan: string | null;
    status: Status;
    catatan_verifikasi: string | null;
    verifier: string | null;
    verified_at: string | null;
    created_at: string | null;
    lampiran: LampiranFile[];
    /** HR list: the stored value of fields that changed after the request was sent. */
    sekarang?: Record<string, FieldValue> | null;
}

/** key => what the diff table needs to show a field. */
export type FieldInfo = Record<string, { label: string; type: ProfileField['type']; section: string }>;

export const ANAK_LABELS: Record<keyof Omit<AnakData, 'id'>, string> = {
    nama: 'Nama',
    tanggal_lahir: 'Tgl Lahir',
    pendidikan: 'Pendidikan',
    status_bekerja: 'Status Bekerja',
    status_menikah: 'Status Menikah',
};

/** A value as read on screen: dates as dd.mm.yyyy, empty as "—". */
export function showValue(value: FieldValue | undefined, type?: ProfileField['type']): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
    if (type === 'date' || /^\d{4}-\d{2}-\d{2}$/.test(String(value))) return fmtDate(String(value));
    return String(value);
}

export const same = (a: FieldValue | undefined, b: FieldValue | undefined) => String(a ?? '').trim() === String(b ?? '').trim();

/** "06.10.2026 09:15" from a server timestamp. */
export function fmtDateTime(value: string | null): string {
    if (!value) return '';
    const [d, t] = value.split(' ');
    return `${fmtDate(d)}${t ? ` ${t.slice(0, 5)}` : ''}`;
}
