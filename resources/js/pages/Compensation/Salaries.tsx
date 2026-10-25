import { Fragment, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, Loader2, Pencil, Search, Trash2 } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, smallInput } from '@/components/time/ui';
import { rupiah } from '@/lib/compensation';

interface Riwayat {
    id: number;
    effective_date: string;
    grade: string | null;
    step: string | null;
    reason: string;
    notes: string | null;
    amount: number | null;
}

interface Row {
    id: number;
    employee_number: string;
    name: string;
    location: string | null;
    job_level: string | null;
    position: string | null;
    employee_class: string | null;
    gender: string | null;
    marital_status: string | null;
    children: number;
    grade: string | null;
    step: string | null;
    salary_scale_id: number | null;
    effective_date: string | null;
    base_salary: number | null;
    position_allowance: number;
    fixed_pay: number | null;
    ptkp: string;
    ter: string;
    issues: string | null;
    history: Riwayat[];
}

interface Props {
    rows: Row[];
    locations: { id: number; name: string }[];
    location: number | null;
    summary: { employee: number; total_base_salary: number; total_allowance: number; total_fixed_pay: number; issues: number };
    scale: { id: number; grade_group: string; grade: string; step: string | null }[];
    reason: Record<string, string>;
    can_edit: boolean;
}

const fmtTgl = (iso: string | null) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '–');

export default function Salaries({ rows, locations, location, summary, scale, reason, can_edit }: Props) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const [editing, setEditing] = useState<Row | null>(null);
    const [hanyaMasalah, setHanyaMasalah] = useState(false);
    const shown = useMemo(
        () =>
            rows.filter(
                (r) =>
                    (!hanyaMasalah || r.issues) &&
                    `${r.employee_number} ${r.name} ${r.grade ?? ''} ${r.job_level ?? ''}`.toLowerCase().includes(q.toLowerCase()),
            ),
        [rows, q, hanyaMasalah],
    );

    return (
        <>
            <Head title="Data Gaji" />
            <AttendanceHeader title="Data Gaji" subtitle="Gaji pokok dari Struktur Skala Upah, tunjangan jabatan per SubGroup, dan status PTKP" />
            <div className="flex-1 space-y-5 overflow-y-auto bg-slate-50 p-6">
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    {[
                        ['Karyawan aktif', summary.employee.toLocaleString('id-ID')],
                        ['Total gaji pokok', `Rp ${rupiah(summary.total_base_salary)}`],
                        ['Total tunjangan jabatan', `Rp ${rupiah(summary.total_allowance)}`],
                        ['Total upah tetap / bulan', `Rp ${rupiah(summary.total_fixed_pay)}`],
                    ].map(([l, v]) => (
                        <div key={l} className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold text-slate-400">{l}</p>
                            <p className="mt-1 text-lg font-black text-slate-800">{v}</p>
                        </div>
                    ))}
                    <button
                        onClick={() => setHanyaMasalah((v) => !v)}
                        className={`rounded-2xl border p-4 text-left shadow-sm ${hanyaMasalah ? 'border-amber-300 bg-amber-50' : 'border-slate-100 bg-white'}`}
                    >
                        <p className="flex items-center gap-1 text-[11px] font-bold text-amber-600">
                            <AlertTriangle size={13} /> Perlu dilengkapi
                        </p>
                        <p className="mt-1 text-lg font-black text-slate-800">{summary.issues}</p>
                    </button>
                </div>

                <div className="rounded-2xl border border-slate-100 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                        <div className="relative">
                            <Search size={14} className="absolute top-2.5 left-2.5 text-slate-400" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama, NRP, golongan…" className={`${smallInput} w-60 pl-8`} />
                        </div>
                        {locations.length > 1 && (
                            <select
                                value={location ?? ''}
                                onChange={(e) => router.get('/compensation/salaries', e.target.value ? { location: e.target.value } : {}, { preserveState: true })}
                                className={smallInput}
                            >
                                <option value="">Semua lokasi</option>
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <span className="ml-auto text-xs text-slate-400">
                            PTKP otomatis: pria menikah K/n (maks. 3 tanggungan), wanita TK/0. Plafon Comben tetap mengikuti status nikah di data karyawan.
                        </span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-3 py-2">Karyawan</th>
                                    <th className="px-3 py-2">SubGroup / jabatan</th>
                                    <th className="px-3 py-2">Golongan · step</th>
                                    <th className="px-3 py-2 text-right">Gaji pokok</th>
                                    <th className="px-3 py-2 text-right">Tunj. jabatan</th>
                                    <th className="px-3 py-2 text-right">Upah tetap</th>
                                    <th className="px-3 py-2">PTKP</th>
                                    {can_edit && <th className="px-3 py-2" />}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((r) => (
                                    <Fragment key={r.id}>
                                        <tr className="border-t border-slate-100 align-top hover:bg-slate-50/60">
                                            <td className="px-3 py-2">
                                                <button onClick={() => setOpen(open === r.id ? null : r.id)} className="flex items-start gap-1.5 text-left">
                                                    <ChevronDown size={14} className={`mt-1 shrink-0 text-slate-400 transition ${open === r.id ? 'rotate-180' : ''}`} />
                                                    <span>
                                                        <span className="block font-semibold text-slate-700">{r.name}</span>
                                                        <span className="text-[11px] text-slate-400">
                                                            {r.employee_number} · {r.location}
                                                        </span>
                                                    </span>
                                                </button>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <span className="block font-semibold text-slate-600">{r.job_level ?? '–'}</span>
                                                <span className="text-slate-400">{r.position ?? '–'}</span>
                                            </td>
                                            <td className="px-3 py-2 text-xs">
                                                <span className="font-bold text-slate-700">{r.grade ?? '–'}</span>
                                                {r.step && <span className="text-slate-500"> · {r.step}</span>}
                                                {r.effective_date && <span className="block text-[10px] text-slate-400">sejak {fmtTgl(r.effective_date)}</span>}
                                                {r.issues && (
                                                    <span className="mt-0.5 flex items-center gap-1 text-[10px] font-bold text-amber-600">
                                                        <AlertTriangle size={11} /> {r.issues}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-2 text-right font-mono text-xs">{r.base_salary !== null ? rupiah(r.base_salary) : '–'}</td>
                                            <td className="px-3 py-2 text-right font-mono text-xs">{rupiah(r.position_allowance)}</td>
                                            <td className="px-3 py-2 text-right font-mono text-xs font-bold text-slate-800">{r.fixed_pay !== null ? rupiah(r.fixed_pay) : '–'}</td>
                                            <td className="px-3 py-2 text-xs">
                                                <span className="rounded bg-brand-50 px-1.5 py-0.5 font-bold text-brand-700">{r.ptkp}</span>
                                                <span className="ml-1 text-[10px] text-slate-400">TER {r.ter}</span>
                                                <span className="block text-[10px] text-slate-400">
                                                    {r.gender ?? '?'} · {r.marital_status ?? '?'} · {r.children} anak
                                                </span>
                                            </td>
                                            {can_edit && (
                                                <td className="px-3 py-2">
                                                    <button onClick={() => setEditing(r)} title="Ubah golongan / step" className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600">
                                                        <Pencil size={14} />
                                                    </button>
                                                </td>
                                            )}
                                        </tr>
                                        {open === r.id && (
                                            <tr className="bg-slate-50/70">
                                                <td colSpan={8} className="px-10 py-3">
                                                    <p className="mb-2 text-[11px] font-bold tracking-wide text-slate-500 uppercase">Riwayat golongan & gaji pokok</p>
                                                    {r.history.length === 0 && <p className="text-xs text-slate-400">Belum ada riwayat.</p>}
                                                    <ul className="space-y-1">
                                                        {r.history.map((g) => (
                                                            <li key={g.id} className="flex items-center gap-3 text-xs text-slate-600">
                                                                <span className="w-24 text-slate-400">{fmtTgl(g.effective_date)}</span>
                                                                <span className="w-32 font-bold">
                                                                    {g.grade}
                                                                    {g.step ? ` · ${g.step}` : ''}
                                                                </span>
                                                                <span className="w-32 font-mono">{g.amount !== null ? `Rp ${rupiah(g.amount)}` : '–'}</span>
                                                                <span>{g.reason}</span>
                                                                {g.notes && <span className="text-slate-400">— {g.notes}</span>}
                                                                {can_edit && (
                                                                    <button
                                                                        onClick={() => confirm('Hapus riwayat ini?') && router.delete(`/compensation/salaries/history/${g.id}`, { preserveScroll: true, preserveState: true })}
                                                                        className="ml-auto rounded p-1 text-slate-300 hover:text-rose-500"
                                                                        title="Hapus"
                                                                    >
                                                                        <Trash2 size={13} />
                                                                    </button>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                                {shown.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-10 text-center text-sm text-slate-400">
                                            Tidak ada karyawan.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            {editing && <GajiModal row={editing} scale={scale} reason={reason} onClose={() => setEditing(null)} />}
        </>
    );
}

function GajiModal({ row, scale, reason, onClose }: { row: Row; scale: Props['scale']; reason: Record<string, string>; onClose: () => void }) {
    const form = useForm({
        salary_scale_id: row.salary_scale_id ? String(row.salary_scale_id) : '',
        effective_date: new Date().toISOString().slice(0, 10),
        reason: row.salary_scale_id ? 'step_increase' : 'initial',
        notes: '',
    });
    const grade_groups = useMemo(() => [...new Set(scale.map((s) => s.grade_group))], [scale]);

    return (
        <Modal
            title={`Golongan & step — ${row.name}`}
            subtitle="Gaji pokok dibaca dari Struktur Skala Upah untuk lokasi kerja dan tahun berjalan. Riwayat lama tetap tersimpan."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(`/compensation/salaries/${row.id}`, { preserveScroll: true, preserveState: true, onSuccess: onClose })} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Golongan · step (Struktur Skala Upah)">
                    <select value={form.data.salary_scale_id} onChange={(e) => form.setData('salary_scale_id', e.target.value)} className={inputClass}>
                        <option value="">Pilih…</option>
                        {grade_groups.map((g) => (
                            <optgroup key={g} label={g}>
                                {scale
                                    .filter((s) => s.grade_group === g)
                                    .map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.grade}
                                            {s.step ? ` · ${s.step}` : ''}
                                        </option>
                                    ))}
                            </optgroup>
                        ))}
                    </select>
                    {form.errors.salary_scale_id && <p className="mt-1 text-xs text-rose-600">{form.errors.salary_scale_id}</p>}
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Berlaku mulai">
                        <input type="date" value={form.data.effective_date} onChange={(e) => form.setData('effective_date', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Alasan">
                        <select value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={inputClass}>
                            {Object.entries(reason).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                <Field label="Catatan">
                    <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={inputClass} />
                </Field>
            </div>
        </Modal>
    );
}
