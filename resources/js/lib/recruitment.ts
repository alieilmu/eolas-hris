/** Modul Recruitment: shapes shared by Tracking, Pelamar, the forms and the public pages. */

export interface StageCard {
    id: number;
    name: string;
    position: string;
    email: string | null;
    phone: string | null;
    applied_date: string | null;
    days_in_stage: number;
    notes_count: number;
    has_cv: boolean;
    rejected: boolean;
    employee_number: string | null;
}

export interface Stage {
    id: number;
    title: string;
    color: string;
    candidates: StageCard[];
}

export interface Answers {
    field_id: string;
    label: string;
    type: string;
    field_key: string | null;
    value: string | null;
    file: { name: string; size: number | null; mime: string | null; url: string } | null;
}

export interface CandidateDetail {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    position: string;
    portfolio_url: string | null;
    applied_date: string | null;
    stage: { id: number; title: string; color: string | null };
    last_stage: boolean;
    cv: { name: string; url: string } | null;
    rejected: { date: string; reason: string | null } | null;
    employee: { id: number; employee_number: string } | null;
    form: string | null;
    /** The position of a vacancy opened from Man Power Planning. */
    job_position: { id: number; name: string; work_location_id: number | null } | null;
    answers: Answers[];
    notes: { id: number; author: string; content: string; created_at: string }[];
    history: { from: string; to: string; by: string; created_at: string }[];
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
