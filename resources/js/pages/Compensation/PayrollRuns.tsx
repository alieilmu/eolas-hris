import { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Banknote, ChevronRight, Loader2, Plus } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';
import { DateInput } from '@/components/DateInput';
import { rupiah } from '@/lib/compensation';
import { RUN_TONE, type PayrollRunRow } from '@/lib/payroll';

interface Props {
    types: Record<string, string>;
    holidays: { value: string; label: string }[];
    runs: PayrollRunRow[];
    periods: { code: string; label: string }[];
    current_period: string;
    locations: { id: number; name: string }[];
    all_locations: boolean;
    can_run: boolean;
}

/** Proses Gaji: the payroll runs, newest period first, and a new one. */
export default function PayrollRuns({ runs, periods, current_period, locations, all_locations, can_run, types, holidays }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="Proses Gaji" />
            <AttendanceHeader title="Proses Gaji" subtitle="Hitung gaji bulanan per periode, ajukan, setujui, bayar, lalu kunci">
                {can_run && (
                    <button onClick={() => setOpen(true)} className={primaryButton}>
                        <Plus size={16} /> Proses gaji baru
                    </button>
                )}
            </AttendanceHeader>
            <main className="flex-1 overflow-y-auto bg-slate-50 p-6">
                {runs.length === 0 ? (
                    <div className="mx-auto mt-16 max-w-md text-center">
                        <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                            <Banknote size={26} />
                        </span>
                        <h2 className="mt-4 font-bold text-slate-800">Belum ada proses gaji</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Buat proses gaji untuk satu periode. Slip setiap karyawan dihitung dari Data Gaji, Absensi, Lembur, BPJS dan PPh21, lalu
                            diajukan dan disetujui sebelum terlihat di portal karyawan.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-bold text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Periode</th>
                                    <th className="px-4 py-3">Lokasi</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3 text-right">Karyawan</th>
                                    <th className="px-4 py-3 text-right">Take home pay</th>
                                    <th className="px-4 py-3">Tanggal bayar</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id} className="border-t border-slate-100 hover:bg-slate-50/60">
                                        <td className="px-4 py-3">
                                            <Link href={`/compensation/payroll/${r.id}`} className="font-bold text-slate-800 hover:text-brand-700">
                                                {r.type === 'thr' ? `THR ${r.holiday}` : r.type === 'final' ? 'Final pay' : 'Gaji'} {r.label}
                                            </Link>
                                            <span className="block text-xs text-slate-400">{r.range}</span>
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{r.location}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ${RUN_TONE[r.status]}`}>
                                                {r.status === 'calculating' && <Loader2 size={12} className="animate-spin" />}
                                                {r.status_label}
                                            </span>
                                            {r.errors > 0 && <span className="ml-2 text-xs font-semibold text-rose-600">{r.errors} gagal</span>}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">{r.employee_count}</td>
                                        <td className="px-4 py-3 text-right font-semibold tabular-nums">{r.take_home_pay === null ? '–' : `Rp ${rupiah(r.take_home_pay)}`}</td>
                                        <td className="px-4 py-3 text-slate-500">{r.pay_date ?? '–'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Link href={`/compensation/payroll/${r.id}`} className="inline-flex text-slate-400 hover:text-brand-600" aria-label="Buka">
                                                <ChevronRight size={18} />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </main>
            {open && (
                <NewRun
                    periods={periods}
                    current={current_period}
                    locations={locations}
                    allLocations={all_locations}
                    types={types}
                    holidays={holidays}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}

function NewRun({
    periods,
    current,
    locations,
    allLocations,
    types,
    holidays,
    onClose,
}: {
    periods: Props['periods'];
    current: string;
    locations: Props['locations'];
    allLocations: boolean;
    types: Record<string, string>;
    holidays: Props['holidays'];
    onClose: () => void;
}) {
    const form = useForm({
        type: 'regular' as 'regular' | 'thr' | 'final',
        period: current,
        work_location_id: allLocations ? '' : String(locations[0]?.id ?? ''),
        pay_date: '',
        notes: '',
        holiday: 'idulfitri',
        holiday_date: '',
        employee_ids: [] as number[],
    });
    const { data, setData, errors, processing } = form;
    const [leavers, setLeavers] = useState<{ id: number; label: string; termination_date: string }[] | null>(null);
    useEffect(() => {
        if (data.type !== 'final') return;
        setLeavers(null);
        fetch(`/compensation/payroll/leaving?period=${data.period}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : []))
            .then(setLeavers)
            .catch(() => setLeavers([]));
    }, [data.type, data.period]);
    const submit = () => {
        form.transform((d) => ({
            ...d,
            work_location_id: d.type !== 'final' && d.work_location_id ? Number(d.work_location_id) : null,
            pay_date: d.pay_date || null,
            holiday: d.type === 'thr' ? d.holiday : null,
            holiday_date: d.type === 'thr' ? d.holiday_date || null : null,
            employee_ids: d.type === 'final' ? d.employee_ids : null,
        }));
        form.post('/compensation/payroll');
    };
    const err = (k: string) => (errors as Record<string, string>)[k];

    return (
        <Modal
            title="Proses gaji baru"
            subtitle="Gaji bulanan per periode (semua lokasi atau per lokasi), THR per hari raya, atau final pay untuk karyawan yang keluar."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={processing || (data.type === 'final' && data.employee_ids.length === 0)} className={primaryButton}>
                        {processing ? <Loader2 size={15} className="animate-spin" /> : <Plus size={15} />} Buat
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm font-bold">
                    {Object.entries(types).map(([v, l]) => (
                        <button
                            key={v}
                            onClick={() => setData('type', v as typeof data.type)}
                            className={`flex-1 rounded-lg px-2 py-1.5 ${data.type === v ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500'}`}
                        >
                            {l}
                        </button>
                    ))}
                </div>
                <Field label={data.type === 'thr' ? 'Dibayar bersama gaji periode' : 'Periode'} hint={data.type === 'thr' ? 'PPh21 THR memakai TER bulan dibayar, digabung dengan gaji bulan itu.' : undefined}>
                    <select value={data.period} onChange={(e) => setData('period', e.target.value)} className={inputClass}>
                        {periods.map((p) => (
                            <option key={p.code} value={p.code}>
                                {p.label}
                            </option>
                        ))}
                    </select>
                    {err('period') && <p className="mt-1 text-xs font-semibold text-rose-600">{err('period')}</p>}
                </Field>
                {data.type === 'thr' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Hari raya" hint="Karyawan dipilih menurut agama di profil.">
                            <select value={data.holiday} onChange={(e) => setData('holiday', e.target.value)} className={inputClass}>
                                {holidays.map((h) => (
                                    <option key={h.value} value={h.value}>
                                        {h.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Tanggal hari raya" hint="Dasar masa kerja (minimal 1 bulan).">
                            <DateInput value={data.holiday_date} onChange={(v) => setData('holiday_date', v)} />
                            {err('holiday_date') && <p className="mt-1 text-xs font-semibold text-rose-600">{err('holiday_date')}</p>}
                        </Field>
                    </div>
                )}
                {data.type === 'final' ? (
                    <Field label="Karyawan yang keluar di periode ini" hint="Sisa gaji pro-rata, PPh21 setahun, dan pelunasan sisa pinjaman.">
                        {leavers === null ? (
                            <p className="text-sm text-slate-400">Memuat…</p>
                        ) : leavers.length === 0 ? (
                            <p className="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500">Tidak ada karyawan dengan tanggal keluar di periode ini.</p>
                        ) : (
                            <div className="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">
                                {leavers.map((l) => (
                                    <label key={l.id} className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={data.employee_ids.includes(l.id)}
                                            onChange={(e) => setData('employee_ids', e.target.checked ? [...data.employee_ids, l.id] : data.employee_ids.filter((x) => x !== l.id))}
                                        />
                                        {l.label} <span className="text-xs text-slate-400">keluar {l.termination_date}</span>
                                    </label>
                                ))}
                            </div>
                        )}
                        {err('employee_ids') && <p className="mt-1 text-xs font-semibold text-rose-600">{err('employee_ids')}</p>}
                    </Field>
                ) : (
                    <Field label="Lokasi kerja">
                        <select value={data.work_location_id} onChange={(e) => setData('work_location_id', e.target.value)} className={inputClass}>
                            {allLocations && <option value="">Semua lokasi</option>}
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                        {err('work_location_id') && <p className="mt-1 text-xs font-semibold text-rose-600">{err('work_location_id')}</p>}
                    </Field>
                )}
                <Field label="Rencana tanggal bayar (opsional)">
                    <DateInput value={data.pay_date} onChange={(v) => setData('pay_date', v)} />
                </Field>
            </div>
        </Modal>
    );
}
