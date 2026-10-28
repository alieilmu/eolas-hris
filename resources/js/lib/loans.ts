import type { WorkflowBrief } from '@/components/ApprovalHistory';
/** Loans: shared shapes of Pinjaman Karyawan (HR) and the portal. */
export interface LoanInstallmentRow {
    id: number;
    sequence: number;
    period: string;
    amount: number;
    status: 'scheduled' | 'deducted' | 'settled';
    status_label: string;
    notes: string | null;
}

export interface LoanRow {
    id: number;
    employee_id: number;
    employee_number: string | null;
    name: string | null;
    principal: number;
    tenor: number;
    installment: number;
    start_period: string;
    purpose: string | null;
    status: 'pending' | 'active' | 'paid_off' | 'rejected';
    status_label: string;
    source: 'hr' | 'portal';
    rejection_note: string | null;
    created_at: string | null;
    paid: number;
    remaining: number;
    installments: LoanInstallmentRow[];
    own?: boolean;
    /** Where a pending one waits in its approval flow. */
    workflow?: WorkflowBrief | null;
}

export const LOAN_TONE: Record<LoanRow['status'], string> = {
    pending: 'bg-amber-100 text-amber-700',
    active: 'bg-sky-100 text-sky-700',
    paid_off: 'bg-brand-100 text-brand-700',
    rejected: 'bg-rose-100 text-rose-600',
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

/** "2026-11" → "Nov 2026". */
export function periodLabel(code: string): string {
    const [y, m] = code.split('-').map(Number);
    return `${MONTHS[m - 1]} ${y}`;
}

/** Equal installments rounded up to a thousand, as the server works them out. */
export function firstInstallment(principal: number, tenor: number): number {
    if (!principal || !tenor) return 0;
    return Math.min(principal, Math.ceil(principal / tenor / 1000) * 1000);
}

/** The next n periods from a code, for a "starts in" choice. */
export function periodsFrom(code: string, n: number): string[] {
    const [y, m] = code.split('-').map(Number);
    return Array.from({ length: n }, (_, i) => {
        const k = y * 12 + (m - 1) + i;
        return `${Math.floor(k / 12)}-${String((k % 12) + 1).padStart(2, '0')}`;
    });
}
