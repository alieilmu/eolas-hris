import { fmtDate } from '@/lib/format';

export type KategoriCuti = 'field' | 'annual' | 'long_service' | 'other';

export const KATEGORI_LABEL: Record<KategoriCuti, string> = {
    field: 'Cuti Lapangan',
    annual: 'Cuti Tahunan',
    long_service: 'Cuti Besar',
    other: 'Cuti Lainnya',
};

export interface Tahunan {
    present: boolean;
    reason?: string;
    hired?: string;
    balance?: number;
    debt?: number;
    pending: number;
    available?: number;
    entitlement_this_year?: number;
    taken_this_year?: number;
    taken_total: number;
    expires_end_of_year?: number;
    next_entitlement_date?: string;
    next_entitlement?: number;
    /** The quotas still alive (used oldest first) and what is left of each. */
    period?: { year: number; start_date: string; effective_until: string; entitlement: number; used: number; remaining: number }[];
    year?: { year: number; entitlement: number; taken: number; remaining: number; debt: number }[];
}

export type BesarStatus = 'not_chosen' | 'pending' | 'taken' | 'cashed_out' | 'auto_cashed_out';

export interface BesarPeriode {
    /** The choice record, when there is one. */
    id: number | null;
    sequence_no: number;
    years_of_service: number;
    entitlement_date: string;
    limit: string;
    status: BesarStatus;
    decision: 'taken' | 'cashed_out' | null;
    decision_status: 'pending' | 'approved' | null;
    days: number;
    used: number;
    pending: number;
    remaining: number;
    base_pay: number | null;
}

export interface Besar {
    period: BesarPeriode[];
    next: string | null;
    next_years_of_service?: number;
}

export interface CutiItem {
    id: number | null;
    leave_travel_request_id?: number;
    source: 'import' | 'manual' | 'leave_travel';
    type: KategoriCuti;
    year: number;
    start_date: string | null;
    end_date: string | null;
    description: string | null;
    days: number;
    deduct: number;
    long_service_leave_deducted?: number;
    status: 'pending' | 'approved' | 'rejected';
    notes: string | null;
    /** The quota (year) each part was taken from; hutang = beyond the balance, paid by that later quota (none yet: jatah null). */
    deducted_from: { entitlement: number | null; days: number; debt: boolean }[];
}

/** What the cuti form needs: balance, the location's field-leave allowance, the Cuti Besar to take. */
export interface CutiInfo {
    annual: Tahunan;
    field: { leave: number; departure: number; back: number; total: number } | null;
    /** The lumpsum a Cuti Lapangan gets now (site × POH × klasifikasi), or why there is none. */
    field_leave_allowance: { amount: number | null; point_of_hire: string | null; employee_class: string | null; reason: string | null };
    long_service: BesarPeriode[];
    category: Record<KategoriCuti, string>;
}

export const BESAR_STATUS: Record<BesarStatus, { label: string; className: string }> = {
    not_chosen: { label: 'Belum dipilih', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    pending: { label: 'Menunggu HR', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    taken: { label: 'Diambil', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    cashed_out: { label: 'Diuangkan', className: 'bg-violet-50 text-violet-700 ring-violet-200' },
    auto_cashed_out: { label: 'Lewat batas → diuangkan', className: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

/** "07.06.2023 – 09.06.2023", the old free text, or the year. */
export function itemWhen(i: Pick<CutiItem, 'start_date' | 'end_date' | 'description' | 'year'>): string {
    if (!i.start_date) return i.description ?? String(i.year);
    const a = fmtDate(i.start_date);
    const b = i.end_date ? fmtDate(i.end_date) : a;
    return a === b ? a : `${a} – ${b}`;
}

/** "1× gapok", "2× gapok". */
export const base_pay = (n: number | null) => (n === null ? '—' : `${String(n).replace('.', ',').replace(/,0$/, '')}× gapok`);
