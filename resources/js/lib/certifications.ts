import type { LampiranFile } from '@/lib/attachment';

/** Sertifikasi & Izin: a certificate as the pages get it. */
export interface CertificationRow {
    id: number;
    employee_id: number;
    employee_number: string | null;
    name: string | null;
    position: string | null;
    location: string | null;
    type_id: number;
    type: string | null;
    type_code: string | null;
    number: string | null;
    issuer: string | null;
    issued_at: string | null;
    expires_at: string | null;
    notes: string | null;
    days_left: number | null;
    status: 'expired' | 'soon' | 'upcoming' | 'valid' | 'lifetime';
    attachments: LampiranFile[];
}

export const CERT_TONE: Record<CertificationRow['status'], string> = {
    expired: 'bg-rose-100 text-rose-700',
    soon: 'bg-amber-100 text-amber-700',
    upcoming: 'bg-sky-100 text-sky-700',
    valid: 'bg-brand-100 text-brand-700',
    lifetime: 'bg-slate-100 text-slate-600',
};

export function daysText(d: number | null): string {
    if (d === null) return 'tanpa masa berlaku';
    if (d < 0) return `lewat ${-d} hari`;
    if (d === 0) return 'habis hari ini';
    return `${d} hari lagi`;
}
