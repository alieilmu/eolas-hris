import type { LampiranFile } from '@/lib/attachment';

/** Shared types and helpers of the Comben module (Deklarasi, Monitoring, Master Data). */

export type JenisItem = 'treatment' | 'medical' | 'frame' | 'lens';
export type Penerima = 'employee' | 'spouse' | 'children';

export const JENIS_ROWS: { value: JenisItem; label: string; benefit_limit: 'medical' | 'optical' }[] = [
    { value: 'treatment', label: 'Perawatan', benefit_limit: 'medical' },
    { value: 'medical', label: 'Pengobatan', benefit_limit: 'medical' },
    { value: 'frame', label: 'Kacamata – Bingkai', benefit_limit: 'optical' },
    { value: 'lens', label: 'Kacamata – Lensa', benefit_limit: 'optical' },
];

export interface Member {
    key: string;
    beneficiary: Penerima;
    child_id: number | null;
    name: string;
    relationship: string;
}

export interface Allowance {
    benefit_limit: number | null;
    used: number;
    /** From the portal, waiting for HR: already held from what is left. */
    pending?: number;
    remaining: number | null;
    last?: string | null;
}

/** GET /comben/karyawan/{id}/plafon */
export interface PlafonInfo {
    employee: {
        id: number;
        employee_number: string;
        name: string;
        position: string | null;
        job_level: string | null;
        department: string | null;
        work_location: string | null;
        grade: string | null;
        ptkp_status: string;
    };
    accounting_month: { value: string; label: string; span: string };
    year: number;
    ptkp_status: string;
    family: boolean;
    warning: string | null;
    medical: Allowance & { layer: string | null };
    optical: (Member & { frame: Allowance; lens: Allowance })[];
}

export interface DeklarasiItemData {
    type: JenisItem;
    beneficiary: Penerima;
    employee_child_id: number | null;
    children?: string | null;
    amount: number;
}

export interface DeklarasiRow {
    id: number;
    claim_number: string;
    date: string;
    provider_name: string | null;
    accounting_month: string;
    month_label: string;
    receipt_count: number;
    description: string | null;
    employee_id: number;
    employee_number: string;
    name: string;
    position: string | null;
    medical: number;
    optical: number;
    total: number;
    status: 'pending' | 'approved' | 'rejected';
    source: 'hr' | 'self_service';
    verification_notes: string | null;
    attachment: LampiranFile[];
    items: DeklarasiItemData[];
}

const number = new Intl.NumberFormat('id-ID');

/** 2250000 -> "2.250.000"; '-' for empty. */
export function rupiah(value: number | null | undefined): string {
    return value === null || value === undefined ? '-' : number.format(value);
}

/** The month part of a book month: "2026-10" -> "Okt". */
export function monthShort(month: string): string {
    return ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][Number(month.slice(5, 7)) - 1];
}
