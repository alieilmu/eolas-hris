import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Search } from 'lucide-react';
import { SlipView, type Slip } from '@/components/compensation/SlipView';
import { AttendanceHeader, Field, primaryButton, smallInput } from '@/components/time/ui';

interface Props {
    employees: { id: number; label: string }[];
    periods: { code: string; label: string }[];
    period: string;
    employee_id: number | null;
    slip: Slip | null;
    profile: { tax_method: string | null; bpjs_health: boolean; bpjs_employment: boolean; health_extra_members: number } | null;
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
                <Field label="Anggota BPJS Kes tambahan">
                    <input
                        type="number"
                        min={0}
                        max={10}
                        disabled={!canEdit || !form.bpjs_health}
                        value={form.health_extra_members}
                        onChange={(e) => setForm((f) => ({ ...f, health_extra_members: Number(e.target.value) }))}
                        className={`${smallInput} w-20`}
                        title="Di luar pasangan & 3 anak: orang tua, mertua, anak ke-4 dst. (1% per orang)"
                    />
                </Field>
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
