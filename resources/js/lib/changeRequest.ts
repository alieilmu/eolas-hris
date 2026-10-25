import { fmtDate } from '@/lib/format';
import type { LampiranFile } from '@/lib/attachment';

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
    kind?: 'children';
    fields?: ProfileField[];
}

export interface AnakData {
    id?: number | null;
    name: string | null;
    birth_date: string | null;
    education_level: string | null;
    employment_status: string | null;
    marital_status: string | null;
}

export interface AnakOp {
    action: 'add' | 'edit' | 'delete';
    child_id: number | null;
    before: AnakData | null;
    after: AnakData | null;
}

export type Status = 'pending' | 'approved' | 'rejected';

/** A Pengajuan Update Data as the server lists it. */
export interface PengajuanRow {
    id: number;
    employee_id: number;
    employee_number: string;
    name: string;
    position: string | null;
    work_location: string | null;
    changes: Record<string, { before: FieldValue; after: FieldValue }>;
    children: AnakOp[];
    quantity: number;
    notes: string | null;
    status: Status;
    verification_notes: string | null;
    verifier: string | null;
    verified_at: string | null;
    created_at: string | null;
    attachment: LampiranFile[];
    /** HR list: the stored value of fields that changed after the request was sent. */
    now?: Record<string, FieldValue> | null;
}

/** key => what the diff table needs to show a field. */
export type FieldInfo = Record<string, { label: string; type: ProfileField['type']; section: string }>;

export const ANAK_LABELS: Record<keyof Omit<AnakData, 'id'>, string> = {
    name: 'Nama',
    birth_date: 'Tgl Lahir',
    education_level: 'Pendidikan',
    employment_status: 'Status Bekerja',
    marital_status: 'Status Menikah',
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
