/** Modul Recruitment: shapes shared by Tracking, Pelamar, the forms and the public pages. */

export interface StageCard {
    id: number;
    name: string;
    position: string;
    email: string | null;
    no_hp: string | null;
    applied_date: string | null;
    hari_di_tahap: number;
    notes_count: number;
    has_cv: boolean;
    ditolak: boolean;
    nrp: string | null;
}

export interface Stage {
    id: number;
    title: string;
    color: string;
    candidates: StageCard[];
}

export interface Jawaban {
    field_id: string;
    label: string;
    type: string;
    kunci: string | null;
    value: string | null;
    file: { nama: string; ukuran: number | null; mime: string | null; url: string } | null;
}

export interface CandidateDetail {
    id: number;
    name: string;
    email: string | null;
    no_hp: string | null;
    position: string;
    portfolio_url: string | null;
    applied_date: string | null;
    stage: { id: number; title: string; color: string | null };
    tahap_terakhir: boolean;
    cv: { nama: string; url: string } | null;
    ditolak: { tanggal: string; alasan: string | null } | null;
    karyawan: { id: number; nrp: string } | null;
    form: string | null;
    /** The position of a vacancy opened from Man Power Planning. */
    jabatan: { id: number; name: string; work_location_id: number | null } | null;
    jawaban: Jawaban[];
    notes: { id: number; author: string; content: string; waktu: string }[];
    history: { from: string; to: string; by: string; waktu: string }[];
}

export type FieldType = 'text' | 'textarea' | 'email' | 'tel' | 'number' | 'date' | 'select' | 'url' | 'file';

export interface PublicField {
    id: number;
    label: string;
    type: FieldType;
    required: boolean;
    options: string[];
    placeholder: string | null;
}

/** Text on a light tint of the stage colour (cards, badges). */
export function tint(color: string | null | undefined, alpha = '1f'): { backgroundColor: string; color: string } {
    const c = color && /^#[0-9a-f]{6}$/i.test(color) ? color : '#64748b';
    return { backgroundColor: `${c}${alpha}`, color: c };
}

/** "5 hari", "hari ini". */
export function hariLabel(n: number): string {
    return n <= 0 ? 'hari ini' : `${n} hari`;
}

export function fileSize(bytes: number | null | undefined): string {
    if (!bytes) return '';
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
