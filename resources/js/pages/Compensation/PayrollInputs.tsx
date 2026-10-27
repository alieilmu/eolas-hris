import { useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Download, FileUp, Loader2, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { rupiah } from '@/lib/compensation';

interface Input {
    id: number;
    employee_id: number;
    employee_number: string | null;
    name: string | null;
    component_id: number;
    component: string;
    kind: 'earning' | 'deduction';
    amount: number;
    notes: string | null;
    status: 'pending' | 'approved' | 'rejected';
    status_label: string;
    source: string;
    created_by: string | null;
    own: boolean;
    locked: boolean;
    rejection_note: string | null;
}

interface Component {
    id: number;
    code: string;
    name: string;
    kind: 'earning' | 'deduction';
    taxable: boolean;
}

interface Props {
    period: string;
    period_label: string;
    periods: { code: string; label: string }[];
    inputs: Input[];
    employees: { id: number; label: string }[];
    components: Component[];
    can: { edit: boolean; approve: boolean; approve_own: boolean };
}

const TONE = { pending: 'bg-amber-100 text-amber-700', approved: 'bg-brand-100 text-brand-700', rejected: 'bg-rose-100 text-rose-600' } as const;
const BASE = '/compensation/payroll-inputs';

/** Input Variabel: bonus, insentif and deductions of one period, entered or imported, then approved. */
export default function PayrollInputs({ period, period_label, periods, inputs, employees, components, can }: Props) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState<'all' | Input['status']>('all');
    const [picked, setPicked] = useState<number[]>([]);
    const [editing, setEditing] = useState<Input | 'new' | null>(null);
    const [importing, setImporting] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    const shown = useMemo(() => {
        const t = q.trim().toLowerCase();
        return inputs.filter((i) => (status === 'all' || i.status === status) && (!t || `${i.employee_number} ${i.name} ${i.component} ${i.notes ?? ''}`.toLowerCase().includes(t)));
    }, [inputs, q, status]);
    const decidable = (i: Input) => can.approve && i.status === 'pending' && (!i.own || can.approve_own);
    const totals = useMemo(() => {
        const by = new Map<string, { kind: string; amount: number; count: number }>();
        inputs.filter((i) => i.status !== 'rejected').forEach((i) => {
            const t = by.get(i.component) ?? { kind: i.kind, amount: 0, count: 0 };
            by.set(i.component, { ...t, amount: t.amount + i.amount, count: t.count + 1 });
        });
        return [...by.entries()];
    }, [inputs]);
    const decide = (decision: 'approved' | 'rejected', note?: string) =>
        router.post(`${BASE}/decide`, { ids: picked, decision, note } as never, { preserveScroll: true, onSuccess: () => (setPicked([]), setRejecting(false)) });

    return (
        <>
            <Head title="Input Variabel" />
            <AttendanceHeader title="Input Variabel" subtitle="Bonus, insentif, uang makan dan potongan per periode; dibayar lewat Proses Gaji setelah disetujui">
                <select value={period} onChange={(e) => router.get(BASE, { period: e.target.value }, { preserveState: false })} className={smallInput} aria-label="Periode">
                    {periods.map((p) => (
                        <option key={p.code} value={p.code}>
                            {p.label}
                        </option>
                    ))}
                </select>
                {can.edit && (
                    <>
                        <button onClick={() => setImporting(true)} className={secondaryButton}>
                            <FileUp size={15} /> Import Excel
                        </button>
                        <button onClick={() => setEditing('new')} className={primaryButton}>
                            <Plus size={15} /> Tambah
                        </button>
                    </>
                )}
            </AttendanceHeader>
            <main className="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-6">
                {totals.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {totals.map(([name, t]) => (
                            <div key={name} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <span className="text-xs text-slate-400">
                                    {name} · {t.count} orang
                                </span>
                                <p className={`font-bold tabular-nums ${t.kind === 'deduction' ? 'text-rose-600' : 'text-slate-800'}`}>
                                    {t.kind === 'deduction' ? '−' : ''}Rp {rupiah(t.amount)}
                                </p>
                            </div>
                        ))}
                    </div>
                )}
                {errors.ids && <p className="rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">{errors.ids}</p>}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                        <div className="relative">
                            <Search size={14} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP / komponen" className={`${smallInput} w-60 pl-8`} />
                        </div>
                        <div className="flex gap-1 rounded-lg bg-slate-100 p-1 text-xs font-bold">
                            {(['all', 'pending', 'approved', 'rejected'] as const).map((s) => (
                                <button key={s} onClick={() => setStatus(s)} className={`rounded-md px-2.5 py-1 ${status === s ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'}`}>
                                    {{ all: 'Semua', pending: 'Menunggu', approved: 'Disetujui', rejected: 'Ditolak' }[s]} (
                                    {s === 'all' ? inputs.length : inputs.filter((i) => i.status === s).length})
                                </button>
                            ))}
                        </div>
                        {picked.length > 0 && (
                            <div className="ml-auto flex gap-2">
                                <button onClick={() => setRejecting(true)} className={secondaryButton}>
                                    <X size={15} /> Tolak {picked.length}
                                </button>
                                <button onClick={() => decide('approved')} className={primaryButton}>
                                    <Check size={15} /> Setujui {picked.length}
                                </button>
                            </div>
                        )}
                    </div>
                    {shown.length === 0 ? (
                        <p className="py-14 text-center text-sm text-slate-400">Belum ada input untuk {period_label}.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-bold text-slate-500">
                                <tr>
                                    <th className="w-10 px-3 py-2.5">
                                        {can.approve && (
                                            <input
                                                type="checkbox"
                                                aria-label="Pilih semua"
                                                checked={shown.filter(decidable).length > 0 && shown.filter(decidable).every((i) => picked.includes(i.id))}
                                                onChange={(e) => setPicked(e.target.checked ? shown.filter(decidable).map((i) => i.id) : [])}
                                            />
                                        )}
                                    </th>
                                    <th className="px-3 py-2.5">Karyawan</th>
                                    <th className="px-3 py-2.5">Komponen</th>
                                    <th className="px-3 py-2.5 text-right">Jumlah</th>
                                    <th className="px-3 py-2.5">Keterangan</th>
                                    <th className="px-3 py-2.5">Status</th>
                                    <th className="px-3 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((i) => (
                                    <tr key={i.id} className="border-t border-slate-100 align-top">
                                        <td className="px-3 py-2">
                                            {decidable(i) && (
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Pilih ${i.name}`}
                                                    checked={picked.includes(i.id)}
                                                    onChange={(e) => setPicked((p) => (e.target.checked ? [...p, i.id] : p.filter((x) => x !== i.id)))}
                                                />
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="font-semibold text-slate-800">{i.name}</span>
                                            <span className="block text-xs text-slate-400">{i.employee_number}</span>
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">{i.component}</td>
                                        <td className={`px-3 py-2 text-right font-semibold tabular-nums ${i.kind === 'deduction' ? 'text-rose-600' : 'text-slate-800'}`}>
                                            {i.kind === 'deduction' ? '−' : ''}
                                            {rupiah(i.amount)}
                                        </td>
                                        <td className="max-w-xs px-3 py-2 text-xs text-slate-500">
                                            {i.notes}
                                            <span className="block text-[11px] text-slate-400">
                                                {i.source === 'import' ? 'Import' : 'Manual'} · {i.created_by}
                                            </span>
                                            {i.rejection_note && <span className="block text-rose-600">Ditolak: {i.rejection_note}</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap ${TONE[i.status]}`}>{i.locked ? 'Dibayar' : i.status_label}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {can.edit && !i.locked && (
                                                <div className="flex justify-end gap-1">
                                                    {i.status !== 'approved' && (
                                                        <button onClick={() => setEditing(i)} className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Ubah">
                                                            <Pencil size={14} />
                                                        </button>
                                                    )}
                                                    <button
                                                        onClick={() => confirm('Hapus input ini?') && router.delete(`${BASE}/${i.id}`, { preserveScroll: true })}
                                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                                        title="Hapus"
                                                    >
                                                        <Trash2 size={14} />
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
                {can.approve && !can.approve_own && inputs.some((i) => i.own && i.status === 'pending') && (
                    <p className="text-xs text-slate-400">Input yang Anda buat sendiri disetujui oleh akun lain.</p>
                )}
            </main>
            {editing && <InputForm input={editing === 'new' ? null : editing} period={period} employees={employees} components={components} onClose={() => setEditing(null)} />}
            {importing && <ImportForm period={period} periodLabel={period_label} onClose={() => setImporting(false)} />}
            {rejecting && <RejectForm count={picked.length} onClose={() => setRejecting(false)} onSubmit={(note) => decide('rejected', note)} />}
        </>
    );
}

function InputForm({ input, period, employees, components, onClose }: { input: Input | null; period: string; employees: Props['employees']; components: Component[]; onClose: () => void }) {
    const form = useForm({
        employee_id: input?.employee_id ?? 0,
        period,
        payroll_component_id: input?.component_id ?? components[0]?.id ?? 0,
        amount: input?.amount ?? 0,
        notes: input?.notes ?? '',
    });
    const [q, setQ] = useState('');
    const found = useMemo(() => employees.filter((e) => e.label.toLowerCase().includes(q.toLowerCase())).slice(0, 50), [employees, q]);
    const chosen = employees.find((e) => e.id === form.data.employee_id);
    const component = components.find((c) => c.id === form.data.payroll_component_id);
    const submit = () => (input ? form.put(`${BASE}/${input.id}`, { onSuccess: onClose }) : form.post(BASE, { onSuccess: onClose }));

    return (
        <Modal
            title={input ? 'Ubah input' : 'Tambah input'}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={form.processing || !form.data.employee_id || form.data.amount <= 0} className={primaryButton}>
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
                                        <button
                                            key={e.id}
                                            onClick={() => (form.setData('employee_id', e.id), setQ(''))}
                                            className="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50"
                                        >
                                            {e.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                    {form.errors.employee_id && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors.employee_id}</p>}
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Komponen" hint={component ? `${component.kind === 'earning' ? 'Pendapatan' : 'Potongan'} · ${component.taxable ? 'kena PPh21' : 'tidak kena PPh21'}` : undefined}>
                        <select value={form.data.payroll_component_id} onChange={(e) => form.setData('payroll_component_id', Number(e.target.value))} className={inputClass}>
                            {components.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Jumlah (Rp)">
                        <input type="number" min={1} value={form.data.amount || ''} onChange={(e) => form.setData('amount', Number(e.target.value))} className={inputClass} />
                        {form.errors.amount && <p className="mt-1 text-xs font-semibold text-rose-600">{form.errors.amount}</p>}
                    </Field>
                </div>
                <Field label="Keterangan (tampil di slip)">
                    <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} maxLength={255} className={inputClass} />
                </Field>
                {form.errors.period && <p className="text-xs font-semibold text-rose-600">{form.errors.period}</p>}
            </div>
        </Modal>
    );
}

function ImportForm({ period, periodLabel, onClose }: { period: string; periodLabel: string; onClose: () => void }) {
    const form = useForm<{ period: string; file: File | null }>({ period, file: null });
    const fileErrors = Object.entries(form.errors)
        .filter(([k]) => k.startsWith('file'))
        .map(([, v]) => v);

    return (
        <Modal
            title={`Import input variabel · ${periodLabel}`}
            subtitle="Satu baris per jumlah: Employee Number, Component Code, Amount, Notes. Semua baris masuk sebagai Menunggu persetujuan."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(`${BASE}/import`, { forceFormData: true, onSuccess: onClose })} disabled={!form.data.file || form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <FileUp size={15} />} Import
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <a href={`${BASE}/template?period=${period}`} className={secondaryButton}>
                    <Download size={15} /> Unduh template
                </a>
                <input type="file" accept=".xlsx,.xls,.csv" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="block w-full text-sm" />
                {fileErrors.length > 0 && (
                    <div className="space-y-0.5 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700">
                        <p className="font-bold">Tidak ada yang disimpan. Perbaiki baris berikut lalu import lagi:</p>
                        {fileErrors.map((e) => (
                            <p key={e}>{e}</p>
                        ))}
                    </div>
                )}
            </div>
        </Modal>
    );
}

function RejectForm({ count, onClose, onSubmit }: { count: number; onClose: () => void; onSubmit: (note: string) => void }) {
    const [note, setNote] = useState('');

    return (
        <Modal
            title={`Tolak ${count} input`}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => onSubmit(note)} disabled={!note.trim()} className={primaryButton}>
                        <X size={15} /> Tolak
                    </button>
                </>
            }
        >
            <Field label="Alasan">
                <input value={note} onChange={(e) => setNote(e.target.value)} className={inputClass} autoFocus />
            </Field>
        </Modal>
    );
}
