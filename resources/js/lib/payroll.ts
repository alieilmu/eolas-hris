/** Payroll runs: statuses and their colours, shared by Proses Gaji pages. */
export interface PayrollRunRow {
    id: number;
    period: string;
    label: string;
    range: string;
    type: string;
    work_location_id: number | null;
    location: string;
    status: 'draft' | 'calculating' | 'calculated' | 'submitted' | 'approved' | 'paid' | 'locked';
    status_label: string;
    employee_count: number;
    progress: number;
    pay_date: string | null;
    take_home_pay: number | null;
    errors: number;
    notes: string | null;
    rejection_note: string | null;
    created_by: string | null;
    created_at: string | null;
}

export const RUN_TONE: Record<PayrollRunRow['status'], string> = {
    draft: 'bg-slate-100 text-slate-600',
    calculating: 'bg-sky-100 text-sky-700',
    calculated: 'bg-amber-100 text-amber-700',
    submitted: 'bg-violet-100 text-violet-700',
    approved: 'bg-brand-100 text-brand-700',
    paid: 'bg-emerald-100 text-emerald-700',
    locked: 'bg-slate-800 text-white',
};

/** The steps a run goes through, in order (calculating is shown as part of "Dihitung"). */
export const RUN_STEPS: { status: PayrollRunRow['status']; label: string }[] = [
    { status: 'draft', label: 'Draft' },
    { status: 'calculated', label: 'Dihitung' },
    { status: 'submitted', label: 'Diajukan' },
    { status: 'approved', label: 'Disetujui' },
    { status: 'paid', label: 'Dibayar' },
    { status: 'locked', label: 'Terkunci' },
];
