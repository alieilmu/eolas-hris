import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { ghostButton, primaryButton, smallInput } from '@/components/time/ui';
import { MoneyInput } from '@/components/compensation/MoneyInput';
import { useDirtyForm, useGuard } from '@/components/settings/DirtyGuard';

export interface CutiAturanData {
    annual_entitlement_days: number;
    annual_validity_years: number;
    long_service_interval_years: number;
    long_service_days: number;
    long_service_taken_salary_multiplier: number;
    long_service_cashout_salary_multiplier: number;
    long_service_decision_months: number;
}

export interface CutiLapanganRow {
    id: number;
    work_location_id: number;
    sub_location_id: number | null;
    location: string | null;
    sub_location: string | null;
    leave_days: number;
    outbound_travel_days: number;
    return_travel_days: number;
    total: number;
}

const visit = { preserveScroll: true, preserveState: true } as const;

const FIELDS: { key: keyof CutiAturanData; label: string; suffix: string; group: 'annual' | 'long_service'; step?: string }[] = [
    { key: 'annual_entitlement_days', label: 'Jatah cuti tahunan', suffix: 'hari / tahun', group: 'annual' },
    { key: 'annual_validity_years', label: 'Masa berlaku jatah', suffix: 'tahun (dipakai FIFO)', group: 'annual' },
    { key: 'long_service_interval_years', label: 'Cuti besar tiap', suffix: 'tahun masa kerja', group: 'long_service' },
    { key: 'long_service_days', label: 'Lama cuti besar bila diambil', suffix: 'hari', group: 'long_service' },
    { key: 'long_service_taken_salary_multiplier', label: 'Uang cuti besar bila diambil', suffix: '× gaji pokok', group: 'long_service', step: '0.5' },
    { key: 'long_service_cashout_salary_multiplier', label: 'Uang cuti besar bila diuangkan', suffix: '× gaji pokok', group: 'long_service', step: '0.5' },
    { key: 'long_service_decision_months', label: 'Batas memilih (ambil / uangkan)', suffix: 'bulan sejak hak; lewat = diuangkan', group: 'long_service' },
];

/** The leave rules: annual quota and its life, Cuti Besar interval, days and pay. */
export function AturanCutiSection({ policy }: { policy: CutiAturanData }) {
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(policy).map(([k, v]) => [k, String(v)])));
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = FIELDS.some((f) => values[f.key] !== String(policy[f.key]));

    const save = () =>
        router.put('/compensation/settings/leave-policy', values, {
            ...visit,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: setErrors,
            onSuccess: () => setErrors({}),
        });

    return (
        <div className="space-y-5">
            {(['annual', 'long_service'] as const).map((group) => (
                <div key={group} className="rounded-2xl border border-slate-200 p-4">
                    <h3 className="mb-3 text-sm font-bold text-slate-700">{group === 'annual' ? 'Cuti Tahunan' : 'Cuti Besar'}</h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {FIELDS.filter((f) => f.group === group).map((f) => (
                            <label key={f.key} className="block">
                                <span className="mb-1 block text-[11px] font-bold text-slate-500">{f.label}</span>
                                <span className="flex items-center gap-2">
                                    <input
                                        type="number"
                                        min={0}
                                        step={f.step ?? '1'}
                                        value={values[f.key]}
                                        onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
                                        className={`${smallInput} w-24 text-right tabular-nums`}
                                    />
                                    <span className="text-xs text-slate-500">{f.suffix}</span>
                                </span>
                                {errors[f.key] && <span className="mt-0.5 block text-[11px] text-rose-600">{errors[f.key]}</span>}
                            </label>
                        ))}
                    </div>
                </div>
            ))}
            <p className="text-xs text-slate-400">
                Hak cuti tahunan tiap tahun masuk pada tanggal ulang tahun masuk kerja (mulai tahun kedua). Cuti diambil memotong sisa tahun lalu
                dulu; kelebihan dari saldo menjadi hutang yang memotong jatah berikutnya.
            </p>
            <div className="flex justify-end">
                <button onClick={save} disabled={!dirty || saving} className={primaryButton}>
                    {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                    Simpan aturan
                </button>
            </div>
        </div>
    );
}

/** Cuti lapangan per work location or sub location: leave days + travel out + travel back. */
export function CutiLapanganSection({
    rows,
    workLocations,
    subLocations,
}: {
    rows: CutiLapanganRow[];
    workLocations: { id: number; name: string }[];
    subLocations: { id: number; name: string; work_location_id: number }[];
}) {
    const empty = { work_location_id: '', sub_location_id: '', leave_days: '14', outbound_travel_days: '2', return_travel_days: '2' };
    const [form, setForm] = useState<(typeof empty & { id?: number }) | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () => {
        if (!form) return;
        const { id, ...data } = form;
        const payload = { ...data, sub_location_id: data.sub_location_id || null };
        const options = { ...visit, onError: setErrors, onSuccess: () => (setForm(null), setErrors({})) };
        if (id) router.put(`/compensation/settings/field-leave/${id}`, payload, options);
        else router.post('/compensation/settings/field-leave', payload, options);
    };
    const remove = (r: CutiLapanganRow) => {
        if (confirm(`Hapus aturan cuti lapangan ${r.location}${r.sub_location ? ` · ${r.sub_location}` : ''}?`))
            router.delete(`/compensation/settings/field-leave/${r.id}`, visit);
    };
    const total = form ? ['leave_days', 'outbound_travel_days', 'return_travel_days'].reduce((n, k) => n + (Number(form[k as keyof typeof form]) || 0), 0) : 0;

    return (
        <div className="space-y-4">
            <div className="overflow-hidden rounded-2xl border border-slate-200">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th className="px-3 py-2 text-left">Lokasi</th>
                            <th className="px-3 py-2 text-left">Sub lokasi</th>
                            <th className="px-3 py-2 text-right">Hari cuti</th>
                            <th className="px-3 py-2 text-right">Perjalanan berangkat</th>
                            <th className="px-3 py-2 text-right">Perjalanan kembali</th>
                            <th className="px-3 py-2 text-right">Total</th>
                            <th className="w-20" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-3 py-8 text-center text-sm text-slate-400">
                                    Belum ada aturan. Karyawan di lokasi tanpa aturan tidak bisa mengajukan Cuti Lapangan.
                                </td>
                            </tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-3 py-2 font-bold text-slate-700">{r.location}</td>
                                <td className="px-3 py-2 text-slate-600">
                                    {r.sub_location ?? <span className="text-slate-400">Semua (default lokasi)</span>}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.leave_days}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.outbound_travel_days}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.return_travel_days}</td>
                                <td className="px-3 py-2 text-right font-black text-brand-700 tabular-nums">{r.total}</td>
                                <td className="px-2 py-2 text-right">
                                    <button
                                        onClick={() =>
                                            setForm({
                                                id: r.id,
                                                work_location_id: String(r.work_location_id),
                                                sub_location_id: r.sub_location_id ? String(r.sub_location_id) : '',
                                                leave_days: String(r.leave_days),
                                                outbound_travel_days: String(r.outbound_travel_days),
                                                return_travel_days: String(r.return_travel_days),
                                            })
                                        }
                                        title="Ubah"
                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                    >
                                        <Pencil size={14} />
                                    </button>
                                    <button
                                        onClick={() => remove(r)}
                                        title="Hapus"
                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                    >
                                        <Trash2 size={14} />
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {form ? (
                <div className="space-y-3 rounded-2xl border border-brand-100 bg-brand-50/30 p-4">
                    <div className="grid gap-3 sm:grid-cols-[1fr_1fr_90px_90px_90px]">
                        <select
                            value={form.work_location_id}
                            onChange={(e) => setForm({ ...form, work_location_id: e.target.value, sub_location_id: '' })}
                            className={smallInput}
                            aria-label="Lokasi"
                        >
                            <option value="">Pilih lokasi…</option>
                            {workLocations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                        <select
                            value={form.sub_location_id}
                            onChange={(e) => setForm({ ...form, sub_location_id: e.target.value })}
                            className={smallInput}
                            aria-label="Sub lokasi"
                        >
                            <option value="">Semua sub lokasi (default)</option>
                            {subLocations
                                .filter((s) => String(s.work_location_id) === form.work_location_id)
                                .map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                        </select>
                        {(
                            [
                                ['leave_days', 'Hari cuti'],
                                ['outbound_travel_days', 'Berangkat'],
                                ['return_travel_days', 'Kembali'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="block">
                                <span className="block text-[10px] font-bold text-slate-500">{label}</span>
                                <input
                                    type="number"
                                    min={0}
                                    value={form[k]}
                                    onChange={(e) => setForm({ ...form, [k]: e.target.value })}
                                    className={`${smallInput} w-full text-right`}
                                />
                            </label>
                        ))}
                    </div>
                    {Object.values(errors).length > 0 && <p className="text-xs text-rose-600">{Object.values(errors).join(' ')}</p>}
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-500">
                            Total cuti lapangan <b className="text-brand-700">{total} hari</b>; pengajuan yang lebih panjang memotong cuti tahunan.
                        </span>
                        <span className="flex gap-2">
                            <button onClick={() => (setForm(null), setErrors({}))} className={ghostButton}>
                                <X size={14} className="mr-1 inline" />
                                Batal
                            </button>
                            <button onClick={save} className={primaryButton}>
                                <Save size={16} /> Simpan
                            </button>
                        </span>
                    </div>
                </div>
            ) : (
                <button onClick={() => setForm(empty)} className={`${ghostButton} flex items-center gap-1.5 text-brand-600`}>
                    <Plus size={15} /> Tambah aturan lokasi / sub lokasi
                </button>
            )}
        </div>
    );
}

export interface LumpsumRow {
    work_location_id: number;
    point_of_hire_id: number;
    employee_class: string;
    amount: number;
}

type LumpsumValues = Record<string, Record<string, number | null>>;

/** Lumpsum Cuti Lapangan of one site: per POH (lokasi penerimaan in the NRP) × klasifikasi. Empty = no lumpsum. */
export function LumpsumSection({
    rows,
    point_of_hire,
    employee_class,
    workLocations,
}: {
    rows: LumpsumRow[];
    point_of_hire: { id: number; code: string; name: string }[];
    employee_class: string[];
    workLocations: { id: number; name: string }[];
}) {
    const guard = useGuard();
    const [site, setSite] = useState(workLocations[0]?.id ?? 0);
    const initial = (id: number): LumpsumValues =>
        Object.fromEntries(
            point_of_hire.map((p) => [
                p.id,
                Object.fromEntries(
                    employee_class.map((k) => [
                        k,
                        rows.find((r) => r.work_location_id === id && r.point_of_hire_id === p.id && r.employee_class === k)?.amount ?? null,
                    ]),
                ),
            ]),
        );
    const [values, setValues] = useState<LumpsumValues>(() => initial(site));
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = JSON.stringify(values) !== JSON.stringify(initial(site));
    const siteName = workLocations.find((w) => w.id === site)?.name ?? '';

    const save = () =>
        new Promise<boolean>((resolve) =>
            router.put(
                '/compensation/settings/field-leave-allowances',
                { work_location_id: site, amount: values },
                {
                    ...visit,
                    onStart: () => setSaving(true),
                    onFinish: () => setSaving(false),
                    onError: (e) => {
                        setErrors(e);
                        resolve(false);
                    },
                    onSuccess: () => {
                        setErrors({});
                        resolve(true);
                    },
                },
            ),
        );
    useDirtyForm('field-leave-allowances', dirty, { label: `Lumpsum cuti lapangan ${siteName}`, save, discard: () => setValues(initial(site)) });
    const pick = (id: number) =>
        guard(() => {
            setSite(id);
            setValues(initial(id));
            setErrors({});
        });

    return (
        <div className="space-y-4">
            <div className="flex w-fit flex-wrap gap-1 rounded-xl bg-slate-100 p-1">
                {workLocations.map((w) => (
                    <button
                        key={w.id}
                        onClick={() => w.id !== site && pick(w.id)}
                        className={`rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                            w.id === site ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        {w.name}
                    </button>
                ))}
            </div>
            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th className="px-3 py-2">POH (lokasi penerimaan)</th>
                            {employee_class.map((k) => (
                                <th key={k} className="px-3 py-2 text-right">
                                    {k} (Rp)
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {point_of_hire.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2">
                                    <b className="text-slate-700">{p.name}</b>
                                    <span className="ml-1.5 text-xs text-slate-400">kode NRP {p.code}</span>
                                </td>
                                {employee_class.map((k) => (
                                    <td key={k} className="px-3 py-2">
                                        <MoneyInput
                                            value={values[p.id]?.[k] ?? null}
                                            onChange={(v) => setValues((cur) => ({ ...cur, [p.id]: { ...cur[p.id], [k]: v } }))}
                                            placeholder="tidak ada"
                                            aria-label={`Lumpsum ${siteName} POH ${p.name} ${k}`}
                                            className={`${smallInput} ml-auto block w-36`}
                                        />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {Object.values(errors)[0] && <p className="text-xs text-rose-600">{Object.values(errors)[0]}</p>}
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs text-slate-500">
                    Kosong = cuti lapangan karyawan itu tanpa lumpsum. Perubahan berlaku untuk pengajuan berikutnya; pengajuan lama tetap memakai
                    nominal saat diajukan.
                </p>
                <button onClick={save} disabled={!dirty || saving} className={`${primaryButton} shrink-0 whitespace-nowrap`}>
                    {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Simpan {siteName}
                </button>
            </div>
        </div>
    );
}
