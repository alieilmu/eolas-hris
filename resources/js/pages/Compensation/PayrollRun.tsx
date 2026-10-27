import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    Calculator,
    Check,
    CheckCircle2,
    Download,
    FileText,
    Loader2,
    Lock,
    Search,
    Send,
    Trash2,
    Undo2,
    XCircle,
} from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { DateInput } from '@/components/DateInput';
import { SlipView, type Slip } from '@/components/compensation/SlipView';
import { rupiah } from '@/lib/compensation';
import { RUN_STEPS, RUN_TONE, type PayrollRunRow } from '@/lib/payroll';

interface Totals {
    employees: number;
    errors: number;
    warnings: number;
    total_earnings: number;
    total_deductions: number;
    tax: number;
    company_cost: number;
    take_home_pay: number;
}

interface Row {
    id: number;
    employee_id: number;
    employee_number: string | null;
    name: string;
    position: string | null;
    department: string | null;
    location: string | null;
    ptkp: string | null;
    total_earnings: number;
    total_deductions: number;
    tax: number;
    company_cost: number;
    take_home_pay: number;
    prorate: number;
    error: string | null;
    warnings: string[];
}

interface Props {
    run: PayrollRunRow & { totals: { all: Totals; locations: Record<string, Totals> } | null; steps: { label: string; by: string | null; at: string }[] };
    slips: Row[];
    locations: { id: number; name: string }[];
    can: { run: boolean; approve: boolean; approval_menu: boolean };
}

type Filter = 'all' | 'warnings' | 'errors';

/** One payroll run: its totals, every slip, and the next step of its approval. */
export default function PayrollRun({ run, slips, can }: Props) {
    const base = `/compensation/payroll/${run.id}`;
    const [q, setQ] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [location, setLocation] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const [dialog, setDialog] = useState<'reject' | 'paid' | null>(null);
    const [busy, setBusy] = useState(false);
    const editable = run.status === 'draft' || run.status === 'calculated';

    useEffect(() => {
        if (run.status !== 'calculating') return;
        const t = setInterval(() => router.reload({ only: ['run', 'slips'] }), 2000);
        return () => clearInterval(t);
    }, [run.status]);

    const post = (action: string, data: Record<string, unknown> = {}) =>
        router.post(`${base}/${action}`, data as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setDialog(null),
        });

    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();
        return slips.filter(
            (s) =>
                (filter === 'all' || (filter === 'errors' ? !!s.error : !s.error && s.warnings.length > 0)) &&
                (!location || s.location === location) &&
                (!term || `${s.employee_number} ${s.name} ${s.position ?? ''} ${s.department ?? ''}`.toLowerCase().includes(term)),
        );
    }, [slips, q, filter, location]);

    const totals = run.totals?.all;
    const slipLocations = useMemo(() => [...new Set(slips.map((s) => s.location).filter(Boolean))] as string[], [slips]);
    const stepIndex = RUN_STEPS.findIndex((s) => s.status === (run.status === 'calculating' ? 'calculated' : run.status));

    return (
        <>
            <Head title={`Proses Gaji ${run.label}`} />
            <AttendanceHeader title={`Proses Gaji ${run.label}`} subtitle={`${run.location} · periode ${run.range}`}>
                <Link href="/compensation/payroll" className={secondaryButton}>
                    <ArrowLeft size={15} /> Semua proses
                </Link>
                {slips.length > 0 && (
                    <a href={`${base}/export`} className={secondaryButton}>
                        <Download size={15} /> Rekap Excel
                    </a>
                )}
            </AttendanceHeader>
            <main className="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <ol className="flex flex-wrap items-center gap-1.5 text-xs font-bold">
                            {RUN_STEPS.map((s, i) => (
                                <li key={s.status} className="flex items-center gap-1.5">
                                    <span
                                        className={`rounded-full px-2.5 py-1 ${
                                            i < stepIndex ? 'bg-brand-50 text-brand-700' : i === stepIndex ? RUN_TONE[run.status] : 'bg-slate-50 text-slate-400'
                                        }`}
                                    >
                                        {i < stepIndex && <Check size={11} className="mr-1 inline" />}
                                        {i === stepIndex ? run.status_label : s.label}
                                    </span>
                                    {i < RUN_STEPS.length - 1 && <span className="text-slate-300">›</span>}
                                </li>
                            ))}
                        </ol>
                        <div className="flex flex-wrap gap-2">
                            {can.run && editable && (
                                <button onClick={() => post('calculate')} disabled={busy} className={run.status === 'draft' ? primaryButton : secondaryButton}>
                                    <Calculator size={15} /> {run.status === 'draft' ? 'Hitung slip' : 'Hitung ulang'}
                                </button>
                            )}
                            {can.run && run.status === 'calculated' && (
                                <button onClick={() => post('submit')} disabled={busy} className={primaryButton}>
                                    <Send size={15} /> Ajukan
                                </button>
                            )}
                            {can.approve && run.status === 'submitted' && (
                                <>
                                    <button onClick={() => setDialog('reject')} disabled={busy} className={secondaryButton}>
                                        <Undo2 size={15} /> Kembalikan
                                    </button>
                                    <button onClick={() => post('approve')} disabled={busy} className={primaryButton}>
                                        <CheckCircle2 size={15} /> Setujui
                                    </button>
                                </>
                            )}
                            {can.approval_menu && run.status === 'approved' && (
                                <button onClick={() => setDialog('paid')} disabled={busy} className={primaryButton}>
                                    <Banknote size={15} /> Tandai dibayar
                                </button>
                            )}
                            {can.approval_menu && run.status === 'paid' && (
                                <button onClick={() => post('lock')} disabled={busy} className={primaryButton}>
                                    <Lock size={15} /> Kunci
                                </button>
                            )}
                            {can.run && editable && (
                                <button
                                    onClick={() => confirm('Hapus proses gaji ini beserta slipnya?') && router.delete(base)}
                                    className="rounded-xl p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                    title="Hapus"
                                    aria-label="Hapus"
                                >
                                    <Trash2 size={16} />
                                </button>
                            )}
                        </div>
                    </div>
                    {run.status === 'submitted' && can.approval_menu && !can.approve && (
                        <p className="mt-3 text-xs text-slate-500">Anda menghitung atau mengajukan proses ini, jadi persetujuannya oleh akun lain.</p>
                    )}
                    {run.status === 'calculating' && (
                        <div className="mt-4">
                            <div className="flex justify-between text-xs text-slate-500">
                                <span className="flex items-center gap-1.5">
                                    <Loader2 size={13} className="animate-spin" /> Menghitung slip…
                                </span>
                                <span className="tabular-nums">
                                    {run.progress} / {run.employee_count}
                                </span>
                            </div>
                            <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                                <div className="h-full bg-brand-500 transition-all" style={{ width: `${run.employee_count ? (run.progress / run.employee_count) * 100 : 5}%` }} />
                            </div>
                        </div>
                    )}
                    {run.rejection_note && (
                        <p className="mt-3 flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            <AlertTriangle size={15} className="mt-0.5 shrink-0" /> {run.rejection_note}
                        </p>
                    )}
                    {run.status === 'draft' && !run.rejection_note && (
                        <p className="mt-3 text-sm text-slate-500">
                            {run.employee_count} karyawan masuk periode ini. Klik <b>Hitung slip</b> untuk menghitung gaji, BPJS dan PPh21 setiap karyawan.
                        </p>
                    )}
                    {run.steps.length > 0 && (
                        <p className="mt-3 text-[11px] text-slate-400">
                            {run.steps.map((s) => `${s.label} ${s.at.slice(0, 16)}${s.by ? ` oleh ${s.by}` : ''}`).join(' · ')}
                        </p>
                    )}
                </section>

                {totals && (
                    <section className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <Stat label="Karyawan" value={String(totals.employees)} note={totals.errors ? `${totals.errors} gagal dihitung` : `${totals.warnings} dengan catatan`} />
                        <Stat label="Total pendapatan" value={rupiah(totals.total_earnings)} />
                        <Stat label="Total potongan" value={rupiah(totals.total_deductions)} />
                        <Stat label="PPh21" value={rupiah(totals.tax)} />
                        <Stat label="Take home pay" value={rupiah(totals.take_home_pay)} strong />
                        <Stat label="Biaya perusahaan (BPJS dll.)" value={rupiah(totals.company_cost)} />
                    </section>
                )}

                {slips.length > 0 && (
                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                            <div className="relative">
                                <Search size={14} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP / jabatan" className={`${smallInput} w-64 pl-8`} />
                            </div>
                            {slipLocations.length > 1 && (
                                <select value={location} onChange={(e) => setLocation(e.target.value)} className={smallInput} aria-label="Lokasi">
                                    <option value="">Semua lokasi</option>
                                    {slipLocations.map((l) => (
                                        <option key={l}>{l}</option>
                                    ))}
                                </select>
                            )}
                            <div className="flex gap-1 rounded-lg bg-slate-100 p-1 text-xs font-bold">
                                {(
                                    [
                                        ['all', `Semua (${slips.length})`],
                                        ['warnings', `Ada catatan (${slips.filter((s) => !s.error && s.warnings.length).length})`],
                                        ['errors', `Gagal (${slips.filter((s) => s.error).length})`],
                                    ] as [Filter, string][]
                                ).map(([v, l]) => (
                                    <button
                                        key={v}
                                        onClick={() => setFilter(v)}
                                        className={`rounded-md px-2.5 py-1 ${filter === v ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'}`}
                                    >
                                        {l}
                                    </button>
                                ))}
                            </div>
                            <span className="ml-auto text-xs text-slate-400">{shown.length} slip</span>
                        </div>
                        <div className="max-h-[60vh] overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-slate-50 text-left text-xs font-bold text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2.5">Karyawan</th>
                                        <th className="px-4 py-2.5">Lokasi</th>
                                        <th className="px-4 py-2.5 text-right">Pendapatan</th>
                                        <th className="px-4 py-2.5 text-right">Potongan</th>
                                        <th className="px-4 py-2.5 text-right">PPh21</th>
                                        <th className="px-4 py-2.5 text-right">Take home pay</th>
                                        <th className="px-4 py-2.5">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((s) => (
                                        <tr key={s.id} onClick={() => setOpen(s.id)} className="cursor-pointer border-t border-slate-100 hover:bg-brand-50/40">
                                            <td className="px-4 py-2">
                                                <span className="font-semibold text-slate-800">{s.name}</span>
                                                <span className="block text-xs text-slate-400">
                                                    {s.employee_number} · {s.position}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-xs text-slate-500">{s.location}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(s.total_earnings)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(s.total_deductions)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(s.tax)}</td>
                                            <td className="px-4 py-2 text-right font-bold tabular-nums">{s.error ? '–' : rupiah(s.take_home_pay)}</td>
                                            <td className="max-w-xs px-4 py-2 text-xs">
                                                {s.error ? (
                                                    <span className="flex items-start gap-1 text-rose-600">
                                                        <XCircle size={13} className="mt-0.5 shrink-0" /> {s.error}
                                                    </span>
                                                ) : s.warnings.length ? (
                                                    <span className="flex items-start gap-1 text-amber-700" title={s.warnings.join('\n')}>
                                                        <AlertTriangle size={13} className="mt-0.5 shrink-0" />
                                                        <span className="line-clamp-2">{s.warnings.join(' ')}</span>
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </main>

            {open !== null && <SlipDrawer url={`${base}/slips/${open}`} onClose={() => setOpen(null)} />}
            {dialog === 'reject' && <RejectDialog busy={busy} onClose={() => setDialog(null)} onSubmit={(note) => post('reject', { note })} />}
            {dialog === 'paid' && <PaidDialog busy={busy} initial={run.pay_date} onClose={() => setDialog(null)} onSubmit={(pay_date) => post('paid', { pay_date })} />}
        </>
    );
}

function Stat({ label, value, note, strong }: { label: string; value: string; note?: string; strong?: boolean }) {
    return (
        <div className={`rounded-2xl border p-4 ${strong ? 'border-brand-200 bg-brand-50' : 'border-slate-200 bg-white'}`}>
            <p className="text-xs font-semibold text-slate-400">{label}</p>
            <p className={`mt-1 text-lg font-black tabular-nums ${strong ? 'text-brand-700' : 'text-slate-800'}`}>{value}</p>
            {note && <p className="text-[11px] text-slate-400">{note}</p>}
        </div>
    );
}

function SlipDrawer({ url, onClose }: { url: string; onClose: () => void }) {
    const [slip, setSlip] = useState<Slip | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let gone = false;
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((d) => !gone && setSlip(d))
            .catch(() => !gone && setFailed(true));
        return () => {
            gone = true;
        };
    }, [url]);

    return (
        <Modal
            title={slip ? `Slip ${slip.employee.name}` : 'Slip gaji'}
            onClose={onClose}
            width="max-w-5xl"
            footer={
                slip && !slip.error ? (
                    <a href={`${url}/pdf`} className={primaryButton}>
                        <FileText size={15} /> Unduh PDF
                    </a>
                ) : undefined
            }
        >
            {failed ? (
                <p className="py-10 text-center text-sm text-rose-600">Slip tidak bisa dibuka.</p>
            ) : slip ? (
                <SlipView slip={slip} />
            ) : (
                <p className="flex justify-center py-10 text-slate-400">
                    <Loader2 className="animate-spin" />
                </p>
            )}
        </Modal>
    );
}

function RejectDialog({ busy, onClose, onSubmit }: { busy: boolean; onClose: () => void; onSubmit: (note: string) => void }) {
    const [note, setNote] = useState('');

    return (
        <Modal
            title="Kembalikan proses gaji"
            subtitle="Proses kembali ke status Dihitung; yang mengajukan melihat catatan Anda."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => onSubmit(note)} disabled={busy || !note.trim()} className={primaryButton}>
                        <Undo2 size={15} /> Kembalikan
                    </button>
                </>
            }
        >
            <Field label="Yang perlu diperbaiki">
                <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} className={inputClass} autoFocus />
            </Field>
        </Modal>
    );
}

function PaidDialog({ busy, initial, onClose, onSubmit }: { busy: boolean; initial: string | null; onClose: () => void; onSubmit: (date: string) => void }) {
    const [date, setDate] = useState(initial ?? new Date().toISOString().slice(0, 10));

    return (
        <Modal
            title="Tandai sudah dibayar"
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => onSubmit(date)} disabled={busy || !date} className={primaryButton}>
                        <Banknote size={15} /> Simpan
                    </button>
                </>
            }
        >
            <Field label="Tanggal bayar">
                <DateInput value={date} onChange={setDate} />
            </Field>
        </Modal>
    );
}
