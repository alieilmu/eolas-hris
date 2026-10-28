import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { HandCoins, Loader2, Send, X } from 'lucide-react';
import { LoanSchedule } from '@/components/compensation/LoanSchedule';
import { LoanFields } from '@/components/compensation/LoanFields';
import { rupiah } from '@/lib/compensation';
import { LOAN_TONE, periodLabel, type LoanRow } from '@/lib/loans';
import { FlowNote } from '@/components/ApprovalHistory';

interface Props {
    loans: LoanRow[];
    next_period: string;
    max_ratio: number;
    has_pending: boolean;
}

/** Portal Karyawan → Pinjaman: own loans with their installments, and asking HR for one. */
export default function Loans({ loans, next_period, max_ratio, has_pending }: Props) {
    const [asking, setAsking] = useState(false);
    const form = useForm({ principal: 0, tenor: 12, start_period: next_period, purpose: '' });

    return (
        <>
            <Head title="Pinjaman" />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-black text-slate-800">Pinjaman</h1>
                    {!has_pending && !asking && (
                        <button onClick={() => setAsking(true)} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700">
                            <HandCoins size={15} /> Ajukan pinjaman
                        </button>
                    )}
                </div>
                {asking && (
                    <section className="space-y-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-sm text-slate-500">
                            Angsuran dipotong dari gaji setiap bulan, paling banyak {max_ratio}% dari gaji bersih. HR meninjau pengajuan Anda.
                        </p>
                        <LoanFields data={form.data} setData={form.setData as never} errors={form.errors} next={next_period} />
                        <div className="flex gap-2">
                            <button
                                onClick={() => form.post('/self-service/loans', { onSuccess: () => (setAsking(false), form.reset()) })}
                                disabled={form.processing || !form.data.principal}
                                className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                            >
                                {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} Kirim ke HR
                            </button>
                            <button onClick={() => setAsking(false)} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-500 hover:bg-slate-100">
                                Batal
                            </button>
                        </div>
                    </section>
                )}
                {loans.length === 0 && !asking && <p className="py-10 text-center text-sm text-slate-400">Belum ada pinjaman.</p>}
                {loans.map((l) => (
                    <section key={l.id} className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p className="font-bold text-slate-800 tabular-nums">
                                    Rp {rupiah(l.principal)} · {l.tenor} bulan
                                </p>
                                <p className="text-xs text-slate-500">
                                    {l.purpose ?? 'Tanpa keterangan'} · mulai {periodLabel(l.start_period)}
                                    {l.status === 'active' && ` · sisa Rp ${rupiah(l.remaining)}`}
                                </p>
                            </div>
                            <div className="text-right">
                                <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${LOAN_TONE[l.status]}`}>{l.status_label}</span>
                                {l.workflow && <FlowNote flow={l.workflow} />}
                            </div>
                        </div>
                        {l.rejection_note && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{l.rejection_note}</p>}
                        <LoanSchedule loan={l} />
                        {l.status === 'pending' && (
                            <button
                                onClick={() => confirm('Batalkan pengajuan pinjaman ini?') && router.delete(`/self-service/loans/${l.id}`, { preserveScroll: true })}
                                className="inline-flex items-center gap-1 text-xs font-bold text-rose-600 hover:underline"
                            >
                                <X size={13} /> Batalkan pengajuan
                            </button>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}
