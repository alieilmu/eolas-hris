import { useMemo, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Check, Loader2, Search } from 'lucide-react';
import { AttendanceHeader, Field, primaryButton, smallInput } from '@/components/time/ui';
import { rupiah } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';

interface Line {
    label: string;
    amount: number | null;
    note?: string | null;
}

interface Slip {
    employee: { id: number; employee_number: string; name: string; position: string | null; job_level: string | null; location: string | null };
    period: { code: string; label: string; range: string };
    ptkp: string;
    ter_category: string;
    has_tax_id: boolean;
    tax_method: string;
    mode: 'ter' | 'annual';
    warnings: string[];
    error?: string;
    earnings?: Line[];
    deductions?: Line[];
    company?: Line[];
    tax_steps?: Line[];
    absence?: { days: number; divisor: number; divisor_source: 'schedule' | 'fixed'; amount: number; dates: { date: string; code: string }[] };
    overtime?: { records: number; weighted_hours: number; amount: number };
    wage?: number;
    gross?: number;
    tax?: number;
    total_earnings?: number;
    total_deductions?: number;
    take_home_pay?: number;
}

interface Props {
    employees: { id: number; label: string }[];
    periods: { code: string; label: string }[];
    period: string;
    employee_id: number | null;
    slip: Slip | null;
    profile: { tax_method: string | null; bpjs_health: boolean; bpjs_employment: boolean } | null;
    tax_methods: Record<string, string>;
    default_tax_method: string;
    can_edit: boolean;
}

const BASE = '/compensation/payroll-simulation';

export default function PayrollSimulation({ employees, periods, period, employee_id, slip, profile, tax_methods, default_tax_method, can_edit }: Props) {
    const [q, setQ] = useState('');
    const shown = useMemo(() => employees.filter((e) => e.label.toLowerCase().includes(q.toLowerCase())), [employees, q]);
    const go = (params: { employee?: number | null; period?: string }) =>
        router.get(BASE, { employee: params.employee ?? employee_id ?? undefined, period: params.period ?? period }, { preserveState: true, preserveScroll: true });

    return (
        <>
            <Head title="Simulasi Gaji" />
            <AttendanceHeader title="Simulasi Gaji" subtitle="Slip satu karyawan dihitung langsung dari Data Gaji, Absensi, Lembur, BPJS dan PPh21 — tidak disimpan">
                <select value={period} onChange={(e) => go({ period: e.target.value })} className={smallInput} aria-label="Periode">
                    {periods.map((p) => (
                        <option key={p.code} value={p.code}>
                            {p.label}
                        </option>
                    ))}
                </select>
            </AttendanceHeader>
            <div className="flex min-h-0 flex-1 bg-slate-50">
                <aside className="flex w-72 shrink-0 flex-col border-r border-slate-200 bg-white">
                    <div className="relative p-3">
                        <Search size={14} className="absolute top-1/2 left-6 -translate-y-1/2 text-slate-400" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP" className={`${smallInput} w-full pl-8`} />
                    </div>
                    <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                        {shown.map((e) => (
                            <button
                                key={e.id}
                                onClick={() => go({ employee: e.id })}
                                className={`block w-full rounded-lg px-3 py-1.5 text-left text-sm ${
                                    e.id === employee_id ? 'bg-brand-50 font-bold text-brand-700' : 'text-slate-600 hover:bg-slate-50'
                                }`}
                            >
                                {e.label}
                            </button>
                        ))}
                    </div>
                </aside>
                <main className="min-w-0 flex-1 overflow-y-auto p-6">
                    {!slip ? (
                        <p className="py-20 text-center text-sm text-slate-400">Pilih karyawan di kiri untuk melihat simulasi slip gajinya.</p>
                    ) : (
                        <SlipView slip={slip} />
                    )}
                    {slip && profile && (
                        <ProfileForm
                            key={slip.employee.id}
                            employeeId={slip.employee.id}
                            profile={profile}
                            taxMethods={tax_methods}
                            defaultMethod={default_tax_method}
                            canEdit={can_edit}
                        />
                    )}
                </main>
            </div>
        </>
    );
}

function SlipView({ slip }: { slip: Slip }) {
    const card = 'rounded-3xl border border-slate-200 bg-white p-5 shadow-sm';

    return (
        <div className="space-y-4">
            <div className={card}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-bold tracking-wide text-slate-400 uppercase">
                            Slip gaji {slip.period.label} · {slip.period.range}
                        </p>
                        <h2 className="mt-1 text-lg font-bold text-slate-800">
                            {slip.employee.name} <span className="font-normal text-slate-400">· {slip.employee.employee_number}</span>
                        </h2>
                        <p className="text-sm text-slate-500">{[slip.employee.position, slip.employee.location].filter(Boolean).join(' · ')}</p>
                    </div>
                    <div className="flex flex-wrap gap-1.5 text-xs">
                        <Badge>PTKP {slip.ptkp}</Badge>
                        <Badge>TER {slip.ter_category}</Badge>
                        <Badge>{{ gross: 'Gross', gross_up: 'Gross-up', net: 'Net' }[slip.tax_method] ?? slip.tax_method}</Badge>
                        {!slip.has_tax_id && <Badge tone="rose">Tanpa NPWP/NIK (+20%)</Badge>}
                    </div>
                </div>
                {slip.warnings.map((w) => (
                    <p key={w} className="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <AlertTriangle size={14} className="mt-0.5 shrink-0" /> {w}
                    </p>
                ))}
                {slip.error && (
                    <p className="mt-3 flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <AlertTriangle size={15} className="mt-0.5 shrink-0" /> {slip.error}
                    </p>
                )}
            </div>

            {!slip.error && (
                <>
                    <div className="grid gap-4 xl:grid-cols-2">
                        <div className={card}>
                            <Lines title="Pendapatan" lines={slip.earnings!} total={slip.total_earnings!} />
                            <Lines title="Potongan" lines={slip.deductions!} total={slip.total_deductions!} />
                            <div className="mt-4 flex items-center justify-between rounded-2xl bg-brand-50 px-4 py-3">
                                <span className="text-sm font-bold text-brand-800">Take home pay</span>
                                <span className="text-xl font-black text-brand-700 tabular-nums">Rp {rupiah(slip.take_home_pay)}</span>
                            </div>
                        </div>
                        <div className="space-y-4">
                            <div className={card}>
                                <Lines title={slip.mode === 'annual' ? 'PPh21 Desember (setahun, Pasal 17)' : 'PPh21 (TER bulanan)'} lines={slip.tax_steps ?? []} />
                            </div>
                            <div className={card}>
                                <Lines title="Ditanggung perusahaan" lines={slip.company!} total={slip.company!.reduce((n, l) => n + (l.amount ?? 0), 0)} />
                                <p className="mt-2 text-[11px] text-slate-400">
                                    Dasar BPJS = upah tetap Rp {rupiah(slip.wage)}. BPJS Kesehatan, JKK dan JKM perusahaan ikut menjadi penghasilan bruto PPh21 (Rp{' '}
                                    {rupiah(slip.gross)}).
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="grid gap-4 xl:grid-cols-2">
                        <div className={card}>
                            <h3 className="text-sm font-bold text-slate-700">Potong gapok</h3>
                            {slip.absence!.days === 0 ? (
                                <p className="mt-2 text-sm text-slate-400">Tidak ada hari ALPA / Izin Potong Gapok di periode ini.</p>
                            ) : (
                                <>
                                    <p className="mt-2 text-sm text-slate-600">
                                        {slip.absence!.days} hari × gaji pokok ÷ {slip.absence!.divisor}{' '}
                                        {slip.absence!.divisor_source === 'schedule' ? '(hari kerja di roster)' : '(angka tetap)'} = Rp {rupiah(slip.absence!.amount)}
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {slip.absence!.dates.map((d) => (
                                            <span key={d.date} className="rounded-lg bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700">
                                                {fmtDate(d.date)} · {d.code}
                                            </span>
                                        ))}
                                    </div>
                                </>
                            )}
                        </div>
                        <div className={card}>
                            <h3 className="text-sm font-bold text-slate-700">Lembur disetujui</h3>
                            <p className="mt-2 text-sm text-slate-600">
                                {slip.overtime!.records
                                    ? `${slip.overtime!.records} hari · ${String(slip.overtime!.weighted_hours).replace('.', ',')} jam tertimbang = Rp ${rupiah(slip.overtime!.amount)}`
                                    : 'Tidak ada lembur yang disetujui di periode ini.'}
                            </p>
                            <p className="mt-1 text-[11px] text-slate-400">Hanya lembur berstatus Disetujui (Kehadiran → Lembur) yang dibayar.</p>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}

function Lines({ title, lines, total }: { title: string; lines: Line[]; total?: number }) {
    return (
        <div className="mb-3">
            <h3 className="mb-1 text-sm font-bold text-slate-700">{title}</h3>
            <table className="w-full text-sm">
                <tbody>
                    {lines.map((l) => (
                        <tr key={l.label} className="border-t border-slate-100">
                            <td className="py-1.5 text-slate-600">
                                {l.label}
                                {l.note && <span className="ml-1.5 text-xs text-slate-400">{l.note}</span>}
                            </td>
                            <td className={`py-1.5 text-right tabular-nums ${(l.amount ?? 0) < 0 ? 'text-rose-600' : 'text-slate-800'}`}>
                                {l.amount === null ? '' : rupiah(l.amount)}
                            </td>
                        </tr>
                    ))}
                    {total !== undefined && (
                        <tr className="border-t-2 border-slate-200 font-bold">
                            <td className="py-1.5 text-slate-700">Total</td>
                            <td className="py-1.5 text-right text-slate-800 tabular-nums">{rupiah(total)}</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function Badge({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'rose' }) {
    return (
        <span className={`rounded-lg px-2 py-1 font-bold ${tone === 'rose' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'}`}>{children}</span>
    );
}

function ProfileForm({
    employeeId,
    profile,
    taxMethods,
    defaultMethod,
    canEdit,
}: {
    employeeId: number;
    profile: NonNullable<Props['profile']>;
    taxMethods: Record<string, string>;
    defaultMethod: string;
    canEdit: boolean;
}) {
    const [form, setForm] = useState(profile);
    const [busy, setBusy] = useState(false);
    const dirty = JSON.stringify(form) !== JSON.stringify(profile);
    const save = () =>
        router.put(`/compensation/payroll-profile/${employeeId}`, form as never, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });

    return (
        <div className="mt-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 className="text-sm font-bold text-slate-700">Pengaturan payroll karyawan ini</h3>
            <div className="mt-3 flex flex-wrap items-end gap-4">
                <Field label="Metode PPh21">
                    <select
                        value={form.tax_method ?? ''}
                        disabled={!canEdit}
                        onChange={(e) => setForm((f) => ({ ...f, tax_method: e.target.value || null }))}
                        className={smallInput}
                    >
                        <option value="">Ikut default ({taxMethods[defaultMethod]})</option>
                        {Object.entries(taxMethods).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </Field>
                <label className="flex items-center gap-2 pb-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        disabled={!canEdit}
                        checked={form.bpjs_health}
                        onChange={(e) => setForm((f) => ({ ...f, bpjs_health: e.target.checked }))}
                    />
                    Ikut BPJS Kesehatan
                </label>
                <label className="flex items-center gap-2 pb-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        disabled={!canEdit}
                        checked={form.bpjs_employment}
                        onChange={(e) => setForm((f) => ({ ...f, bpjs_employment: e.target.checked }))}
                    />
                    Ikut BPJS Ketenagakerjaan
                </label>
                {canEdit && (
                    <button onClick={save} disabled={!dirty || busy} className={primaryButton}>
                        {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                )}
            </div>
        </div>
    );
}
