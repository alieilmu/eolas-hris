/** Man Power Planning: shapes of the page. */

export type Kategori = 'STAFF' | 'NONOM' | 'OPERATOR' | 'MEKANIK';

export interface MppRow {
    position_id: number;
    position: string;
    category: Kategori;
    job_level: string | null;
    sub_location: string | null;
    department: string;
    plan: number;
    actual: number;
    promotions: number;
    rotations: number;
    demotions: number;
    termination: number;
    retirements: number;
    retirements_auto: number;
    /** Typed in the plan instead of counted. */
    retirements_manual: boolean;
    retirements_list: { employee_number: string; name: string; date: string; month: number }[];
    sick: number;
    sick_auto: number;
    sick_manual: boolean;
    sick_list: { employee_number: string; name: string; start_date: string; end_date: string | null; description: string | null }[];
    gap: number;
    movement_month: number | null;
    fulfilment_month: number | null;
    source: 'internal' | 'external' | null;
    grade_id: number | null;
    grade: string | null;
    grade_manual: boolean;
    wage: number | null;
    cost: number;
    description: string | null;
    employee: { employee_number: string; name: string }[];
    careers: { form_id: number; title: string; is_open: boolean; applicants: number; candidate: number } | null;
}

export interface Totals {
    plan: number;
    actual: number;
    promotions: number;
    rotations: number;
    demotions: number;
    termination: number;
    retirements: number;
    sick: number;
    gap: number;
    short: number;
    over: number;
    cost: number;
}

export interface Revisi {
    id: number;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    is_direct: boolean;
    notes: string | null;
    verification_notes: string | null;
    creator: string | null;
    created_by: number | null;
    approver: string | null;
    created_at: string | null;
    decided: string | null;
    changes: { position_id: number; position: string; fields: { field: string; before: string | number; after: string | number }[] }[];
}

/** The fields an edit may change (pensiun / sakit: null = counted from the employees). */
export type Editable = Partial<
    Pick<MppRow, 'plan' | 'promotions' | 'rotations' | 'demotions' | 'termination' | 'movement_month' | 'fulfilment_month' | 'source' | 'grade_id' | 'description'> & {
        retirements: number | null;
        sick: number | null;
    }
>;

export const FIELD_LABEL: Record<string, string> = {
    plan: 'Plan',
    promotions: 'Promosi',
    rotations: 'Rotasi/Mutasi',
    demotions: 'Demosi',
    termination: 'Keluar',
    retirements: 'Pensiun',
    sick: 'Sakit berkepanjangan',
    movement_month: 'Bulan pindah',
    fulfilment_month: 'Bulan pemenuhan',
    source: 'Pemenuhan dari',
    grade_id: 'Golongan',
    description: 'Keterangan',
};

export const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

export const rupiah = (n: number) => n.toLocaleString('id-ID');

/** Rp 12,5 jt / Rp 850 rb, for tiles. */
export function rupiahShort(n: number): string {
    if (n >= 1_000_000_000) return `Rp ${(n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} M`;
    if (n >= 1_000_000) return `Rp ${(n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    if (n >= 1_000) return `Rp ${Math.round(n / 1_000)} rb`;
    return `Rp ${n}`;
}
