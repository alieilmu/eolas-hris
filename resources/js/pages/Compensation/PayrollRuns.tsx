import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Banknote, ChevronRight, Loader2, Plus } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';
import { DateInput } from '@/components/DateInput';
import { rupiah } from '@/lib/compensation';
import { RUN_TONE, type PayrollRunRow } from '@/lib/payroll';

interface Props {
    runs: PayrollRunRow[];
    periods: { code: string; label: string }[];
    current_period: string;
    locations: { id: number; name: string }[];
    all_locations: boolean;
    can_run: boolean;
}

/** Proses Gaji: the payroll runs, newest period first, and a new one. */
export default function PayrollRuns({ runs, periods, current_period, locations, all_locations, can_run }: Props) {
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
                                                {r.label}
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
            {open && <NewRun periods={periods} current={current_period} locations={locations} allLocations={all_locations} onClose={() => setOpen(false)} />}
        </>
    );
}

function NewRun({
    periods,
    current,
    locations,
    allLocations,
    onClose,
}: {
    periods: Props['periods'];
    current: string;
    locations: Props['locations'];
    allLocations: boolean;
    onClose: () => void;
}) {
    const form = useForm({ period: current, work_location_id: allLocations ? '' : String(locations[0]?.id ?? ''), pay_date: '', notes: '' });
    const { data, setData, errors, processing } = form;
    const submit = () => {
        form.transform((d) => ({ ...d, work_location_id: d.work_location_id ? Number(d.work_location_id) : null, pay_date: d.pay_date || null }));
        form.post('/compensation/payroll');
    };

    return (
        <Modal
            title="Proses gaji baru"
            subtitle="Satu periode untuk semua lokasi kerja, atau per lokasi (mis. site dan Head Office diproses terpisah)."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={processing} className={primaryButton}>
                        {processing ? <Loader2 size={15} className="animate-spin" /> : <Plus size={15} />} Buat
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Periode">
                    <select value={data.period} onChange={(e) => setData('period', e.target.value)} className={inputClass}>
                        {periods.map((p) => (
                            <option key={p.code} value={p.code}>
                                {p.label}
                            </option>
                        ))}
                    </select>
                    {errors.period && <p className="mt-1 text-xs font-semibold text-rose-600">{errors.period}</p>}
                </Field>
                <Field label="Lokasi kerja">
                    <select value={data.work_location_id} onChange={(e) => setData('work_location_id', e.target.value)} className={inputClass}>
                        {allLocations && <option value="">Semua lokasi</option>}
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                    {errors.work_location_id && <p className="mt-1 text-xs font-semibold text-rose-600">{errors.work_location_id}</p>}
                </Field>
                <Field label="Rencana tanggal bayar (opsional)">
                    <DateInput value={data.pay_date} onChange={(v) => setData('pay_date', v)} />
                </Field>
                <Field label="Catatan (opsional)">
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} className={inputClass} />
                </Field>
            </div>
        </Modal>
    );
}
