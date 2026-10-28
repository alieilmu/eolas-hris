import { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Loader2, Plus, Search, Trash2, Wallet, X } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { LoanSchedule } from '@/components/compensation/LoanSchedule';
import { LoanFields } from '@/components/compensation/LoanFields';
import { FlowNote } from '@/components/ApprovalHistory';
import { rupiah } from '@/lib/compensation';
import { LOAN_TONE, periodLabel, periodsFrom, type LoanRow } from '@/lib/loans';

interface Props {
    loans: LoanRow[];
    status: string | null;
    statuses: Record<string, string>;
    employees: { id: number; label: string }[];
    next_period: string;
    max_ratio: number;
    can: { edit: boolean; approve: boolean; approve_own: boolean };
}

const BASE = '/compensation/loans';

/** Pinjaman Karyawan: loans waiting for approval, running and paid off, with their installments. */
export default function Loans({ loans, status, statuses, employees, next_period, max_ratio, can }: Props) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState<number | null>(loans.find((l) => l.status === 'pending')?.id ?? null);
    const [adding, setAdding] = useState(false);
    const [dialog, setDialog] = useState<{ type: 'reject' | 'payoff'; loan: LoanRow } | null>(null);
    const shown = useMemo(() => {
        const t = q.trim().toLowerCase();
        return loans.filter((l) => !t || `${l.employee_number} ${l.name} ${l.purpose ?? ''}`.toLowerCase().includes(t));
    }, [loans, q]);
    const loan = loans.find((l) => l.id === open) ?? (open === null ? (shown[0] ?? null) : null);
    const visit = { preserveScroll: true, preserveState: true } as const;

    return (
        <>
            <Head title="Pinjaman Karyawan" />
            <AttendanceHeader title="Pinjaman Karyawan" subtitle={`Angsuran dipotong otomatis di Proses Gaji · batas angsuran ${max_ratio}% gaji bersih`}>
                <select value={status ?? ''} onChange={(e) => router.get(BASE, e.target.value ? { status: e.target.value } : {})} className={smallInput} aria-label="Status">
                    <option value="">Semua status</option>
                    {Object.entries(statuses).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
                {can.edit && (
                    <button onClick={() => setAdding(true)} className={primaryButton}>
                        <Plus size={15} /> Catat pinjaman
                    </button>
                )}
            </AttendanceHeader>
            <div className="flex min-h-0 flex-1 bg-slate-50">
                <aside className="flex w-96 shrink-0 flex-col border-r border-slate-200 bg-white">
                    <div className="relative p-3">
                        <Search size={14} className="absolute top-1/2 left-6 -translate-y-1/2 text-slate-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP" className={`${smallInput} w-full pl-8`} />
                    </div>
                    <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                        {shown.length === 0 && <p className="py-10 text-center text-sm text-slate-400">Belum ada pinjaman.</p>}
                        {shown.map((l) => (
                            <button
                                key={l.id}
                                onClick={() => setOpen(l.id)}
                                className={`block w-full rounded-xl px-3 py-2 text-left ${l.id === open ? 'bg-brand-50' : 'hover:bg-slate-50'}`}
                            >
                                <span className="flex items-center justify-between gap-2">
                                    <span className="truncate text-sm font-semibold text-slate-800">{l.name}</span>
                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ${LOAN_TONE[l.status]}`}>{l.status_label}</span>
                                </span>
                                <span className="text-xs text-slate-400 tabular-nums">
                                    Rp {rupiah(l.principal)} · {l.tenor} bln{l.status === 'active' ? ` · sisa Rp ${rupiah(l.remaining)}` : ''}
                                    {l.source === 'portal' ? ' · dari portal' : ''}
                                </span>
                            </button>
                        ))}
                    </div>
                </aside>
                <main className="min-w-0 flex-1 overflow-y-auto p-6">
                    {!loan ? (
                        <p className="py-20 text-center text-sm text-slate-400">Pilih pinjaman di kiri.</p>
                    ) : (
                        <div className="max-w-3xl space-y-4">
                            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs font-bold text-slate-400 uppercase">
                                            {loan.employee_number} · dicatat {loan.created_at} {loan.source === 'portal' ? '(diajukan dari portal)' : ''}
                                        </p>
                                        <h2 className="text-lg font-bold text-slate-800">{loan.name}</h2>
                                        <p className="text-sm text-slate-500">{loan.purpose ?? 'Tanpa keterangan keperluan'}</p>
                                    </div>
                                    <div className="text-right">
                                        <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${LOAN_TONE[loan.status]}`}>{loan.status_label}</span>
                                        {loan.workflow && <FlowNote flow={loan.workflow} />}
                                    </div>
                                </div>
                                <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {[
                                        ['Pinjaman', `Rp ${rupiah(loan.principal)}`],
                                        ['Angsuran', `Rp ${rupiah(loan.installment)} × ${loan.tenor}`],
                                        ['Mulai dipotong', periodLabel(loan.start_period)],
                                        ['Sisa', `Rp ${rupiah(loan.status === 'pending' ? loan.principal : loan.remaining)}`],
                                    ].map(([l, v]) => (
                                        <div key={l}>
                                            <p className="text-xs text-slate-400">{l}</p>
                                            <p className="font-bold text-slate-800 tabular-nums">{v}</p>
                                        </div>
                                    ))}
                                </div>
                                {loan.rejection_note && <p className="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">Ditolak: {loan.rejection_note}</p>}
                                <div className="mt-4 flex flex-wrap gap-2">
                                    {loan.status === 'pending' && (loan.workflow ? loan.workflow.can_decide : can.approve) && (!loan.own || can.approve_own) && (
                                        <>
                                            <button
                                                onClick={() => router.post(`${BASE}/${loan.id}/approve`, {}, { ...visit, onError: (e) => alert(Object.values(e)[0]) })}
                                                className={primaryButton}
                                            >
                                                <Check size={15} /> Setujui
                                            </button>
                                            <button onClick={() => setDialog({ type: 'reject', loan })} className={secondaryButton}>
                                                <X size={15} /> Tolak
                                            </button>
                                        </>
                                    )}
                                    {loan.status === 'pending' && loan.own && !can.approve_own && can.approve && (
                                        <p className="text-xs text-slate-400">Pinjaman yang Anda catat disetujui oleh akun lain.</p>
                                    )}
                                    {loan.status === 'active' && can.edit && (
                                        <button onClick={() => setDialog({ type: 'payoff', loan })} className={secondaryButton}>
                                            <Wallet size={15} /> Lunasi sisa
                                        </button>
                                    )}
                                    {['pending', 'rejected'].includes(loan.status) && can.edit && (
                                        <button
                                            onClick={() => confirm('Hapus pinjaman ini?') && router.delete(`${BASE}/${loan.id}`, { ...visit, onSuccess: () => setOpen(null) })}
                                            className="rounded-xl p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            title="Hapus"
                                        >
                                            <Trash2 size={16} />
                                        </button>
                                    )}
                                </div>
                            </section>
                            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                                <h3 className="mb-2 text-sm font-bold text-slate-700">Jadwal angsuran</h3>
                                <LoanSchedule
                                    loan={loan}
                                    onPostpone={
                                        can.edit
                                            ? (id) => confirm('Tunda angsuran ini satu bulan? Angsuran sesudahnya ikut mundur.') && router.post(`${BASE}/installments/${id}/postpone`, {}, visit)
                                            : undefined
                                    }
                                />
                            </section>
                        </div>
                    )}
                </main>
            </div>
            {adding && <NewLoan employees={employees} next={next_period} onClose={() => setAdding(false)} />}
            {dialog?.type === 'reject' && <RejectLoan loan={dialog.loan} onClose={() => setDialog(null)} />}
            {dialog?.type === 'payoff' && <PayOff loan={dialog.loan} next={next_period} onClose={() => setDialog(null)} />}
        </>
    );
}

function NewLoan({ employees, next, onClose }: { employees: Props['employees']; next: string; onClose: () => void }) {
    const form = useForm({ employee_id: 0, principal: 0, tenor: 12, start_period: next, purpose: '' });
    const [q, setQ] = useState('');
    const found = useMemo(() => employees.filter((e) => e.label.toLowerCase().includes(q.toLowerCase())).slice(0, 50), [employees, q]);
    const chosen = employees.find((e) => e.id === form.data.employee_id);

    return (
        <Modal
            title="Catat pinjaman"
            subtitle="Pinjaman disetujui oleh akun Persetujuan Payroll, lalu angsurannya dipotong di Proses Gaji."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(BASE, { onSuccess: onClose })} disabled={form.processing || !form.data.employee_id || !form.data.principal} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Karyawan">
                    {chosen && !q ? (
                        <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                            {chosen.label}
                            <button onClick={() => form.setData('employee_id', 0)} className="text-xs font-bold text-brand-600">
                                Ganti
                            </button>
                        </div>
                    ) : (
                        <>
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ketik nama atau NRP" className={inputClass} autoFocus />
                            {q && (
                                <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200">
                                    {found.map((e) => (
                                        <button key={e.id} onClick={() => (form.setData('employee_id', e.id), setQ(''))} className="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50">
                                            {e.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </Field>
                <LoanFields data={form.data} setData={form.setData as never} errors={form.errors} next={next} />
            </div>
        </Modal>
    );
}

function RejectLoan({ loan, onClose }: { loan: LoanRow; onClose: () => void }) {
    const form = useForm({ note: '' });

    return (
        <Modal
            title={`Tolak pinjaman ${loan.name}`}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(`${BASE}/${loan.id}/reject`, { preserveScroll: true, onSuccess: onClose })} disabled={!form.data.note.trim()} className={primaryButton}>
                        <X size={15} /> Tolak
                    </button>
                </>
            }
        >
            <Field label="Alasan (terlihat oleh karyawan)">
                <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} className={inputClass} autoFocus />
            </Field>
        </Modal>
    );
}

function PayOff({ loan, next, onClose }: { loan: LoanRow; next: string; onClose: () => void }) {
    const form = useForm({ how: 'payroll' as 'payroll' | 'outside', period: next, note: '' });

    return (
        <Modal
            title={`Lunasi sisa pinjaman · Rp ${rupiah(loan.remaining)}`}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(`${BASE}/${loan.id}/pay-off`, { preserveScroll: true, onSuccess: onClose })} disabled={form.processing} className={primaryButton}>
                        <Wallet size={15} /> Lunasi
                    </button>
                </>
            }
        >
            <div className="space-y-3 text-sm">
                <label className="flex items-start gap-2">
                    <input type="radio" checked={form.data.how === 'payroll'} onChange={() => form.setData('how', 'payroll')} className="mt-1" />
                    <span>
                        Potong sekaligus dari gaji
                        <select value={form.data.period} onChange={(e) => form.setData('period', e.target.value)} className={`${smallInput} ml-2`} disabled={form.data.how !== 'payroll'}>
                            {periodsFrom(next, 6).map((p) => (
                                <option key={p} value={p}>
                                    {periodLabel(p)}
                                </option>
                            ))}
                        </select>
                    </span>
                </label>
                <label className="flex items-start gap-2">
                    <input type="radio" checked={form.data.how === 'outside'} onChange={() => form.setData('how', 'outside')} className="mt-1" />
                    <span>Sudah dibayar di luar gaji (tunai / transfer)</span>
                </label>
                {form.data.how === 'outside' && (
                    <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Catatan, mis. transfer 12 Nov" className={inputClass} />
                )}
                {form.errors.period && <p className="text-xs font-semibold text-rose-600">{form.errors.period}</p>}
            </div>
        </Modal>
    );
}
