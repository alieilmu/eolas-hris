import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Loader2, Plus } from 'lucide-react';
import { Field, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { MoneyInput } from '@/components/compensation/MoneyInput';
import { rupiah } from '@/lib/compensation';

export interface PayrollRateRow {
    year: number;
    health_company_rate: number;
    health_employee_rate: number;
    health_wage_cap: number;
    jht_company_rate: number;
    jht_employee_rate: number;
    jp_company_rate: number;
    jp_employee_rate: number;
    jp_wage_cap: number;
    jkm_rate: number;
    position_cost_rate: number;
    position_cost_monthly_cap: number;
    ptkp_base: number;
    ptkp_married: number;
    ptkp_dependant: number;
    no_tax_id_surcharge: number;
}

export interface PayrollComponentRow {
    id: number;
    code: string;
    name: string;
    kind: 'earning' | 'deduction';
    nature: string;
    taxable: boolean;
    sort_order: number;
    is_active: boolean;
    is_system: boolean;
}

export interface PayrollData {
    settings: {
        cutoff_day: number;
        default_tax_method: string;
        absence_divisor_mode: string;
        absence_divisor_days: number;
        payslip_password: boolean;
        loan_max_ratio: number;
    };
    components: PayrollComponentRow[];
    component_kinds: Record<string, string>;
    component_natures: Record<string, string>;
    tax_methods: Record<string, string>;
    divisor_modes: Record<string, string>;
    rates: PayrollRateRow[];
    jkk: { id: number; name: string; type: string; jkk_rate: number; minimum_wage: number | null }[];
    ter: Record<string, { lower_bound: number; upper_bound: number | null; rate: number }[]>;
}

const visit = { preserveScroll: true, preserveState: true } as const;
const label = 'text-[10px] font-bold tracking-wide text-slate-500 uppercase';

const RATE_FIELDS: { key: keyof PayrollRateRow; label: string; money?: boolean }[] = [
    { key: 'health_company_rate', label: 'BPJS Kesehatan — perusahaan (%)' },
    { key: 'health_employee_rate', label: 'BPJS Kesehatan — karyawan (%)' },
    { key: 'health_wage_cap', label: 'Batas upah BPJS Kesehatan', money: true },
    { key: 'jht_company_rate', label: 'JHT — perusahaan (%)' },
    { key: 'jht_employee_rate', label: 'JHT — karyawan (%)' },
    { key: 'jkm_rate', label: 'JKM — perusahaan (%)' },
    { key: 'jp_company_rate', label: 'JP — perusahaan (%)' },
    { key: 'jp_employee_rate', label: 'JP — karyawan (%)' },
    { key: 'jp_wage_cap', label: 'Batas upah JP', money: true },
    { key: 'position_cost_rate', label: 'Biaya jabatan (%)' },
    { key: 'position_cost_monthly_cap', label: 'Batas biaya jabatan / bulan', money: true },
    { key: 'no_tax_id_surcharge', label: 'Tambahan tanpa NPWP/NIK (%)' },
    { key: 'ptkp_base', label: 'PTKP wajib pajak / tahun', money: true },
    { key: 'ptkp_married', label: 'PTKP tambahan kawin', money: true },
    { key: 'ptkp_dependant', label: 'PTKP per tanggungan (maks. 3)', money: true },
];

/** Pengaturan Kompensasi → Payroll: periods, potong gapok, JKK per location, figures per year, the TER table. */
export function PayrollSection({ data }: { data: PayrollData }) {
    return (
        <div className="space-y-8">
            <PeriodForm data={data} />
            <ComponentsForm data={data} />
            <RatesForm rates={data.rates} />
            <TerTable ter={data.ter} />
        </div>
    );
}

function PeriodForm({ data }: { data: PayrollData }) {
    const [form, setForm] = useState(data.settings);
    const [jkk, setJkk] = useState<Record<number, string>>(() => Object.fromEntries(data.jkk.map((l) => [l.id, String(l.jkk_rate)])));
    const [umk, setUmk] = useState<Record<number, string>>(() => Object.fromEntries(data.jkk.map((l) => [l.id, l.minimum_wage ? String(l.minimum_wage) : ''])));
    const [busy, setBusy] = useState(false);
    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }));
    const save = () =>
        router.put(
            '/compensation/settings/payroll',
            { ...form, jkk, umk: Object.fromEntries(Object.entries(umk).map(([k, v]) => [k, v === '' ? null : Number(v)])) } as never,
            { ...visit, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    return (
        <section className="space-y-4">
            <h3 className="text-sm font-bold text-slate-700">Periode, pajak & potong gapok</h3>
            <div className="grid max-w-3xl gap-4 sm:grid-cols-2">
                <Field label="Tanggal cutoff" hint="Hari terakhir periode. 20 = periode Oktober 21 Sep – 20 Okt; 31 = bulan kalender.">
                    <input type="number" min={1} max={31} value={form.cutoff_day} onChange={(e) => set('cutoff_day', Number(e.target.value))} className={`${smallInput} w-24`} />
                </Field>
                <Field label="Metode PPh21 default" hint="Bisa diganti per karyawan di Simulasi Gaji.">
                    <select value={form.default_tax_method} onChange={(e) => set('default_tax_method', e.target.value)} className={`${smallInput} w-full`}>
                        {Object.entries(data.tax_methods).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Batas angsuran pinjaman (% gaji bersih)" hint="Dicek saat pinjaman diajukan atau disetujui.">
                    <input
                        type="number"
                        min={10}
                        max={100}
                        value={form.loan_max_ratio}
                        onChange={(e) => set('loan_max_ratio', Number(e.target.value))}
                        className={`${smallInput} w-24`}
                    />
                </Field>
                <Field label="Pembagi potong gapok" hint="Potongan per hari ALPA / Izin Potong Gapok = gaji pokok ÷ pembagi ini.">
                    <select value={form.absence_divisor_mode} onChange={(e) => set('absence_divisor_mode', e.target.value)} className={`${smallInput} w-full`}>
                        {Object.entries(data.divisor_modes).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    label={form.absence_divisor_mode === 'fixed' ? 'Angka pembagi' : 'Angka pembagi cadangan'}
                    hint={form.absence_divisor_mode === 'fixed' ? 'Mis. 21 (5 hari kerja) atau 25 (6 hari kerja).' : 'Dipakai bila roster karyawan di periode itu kosong.'}
                >
                    <input
                        type="number"
                        min={1}
                        max={31}
                        value={form.absence_divisor_days}
                        onChange={(e) => set('absence_divisor_days', Number(e.target.value))}
                        className={`${smallInput} w-24`}
                    />
                </Field>
            </div>
            <div>
                <p className={`${label} mb-2`}>Tarif JKK (%) & UMK per lokasi kerja</p>
                <table className="w-full max-w-2xl text-sm">
                    <thead className="text-left text-xs text-slate-400">
                        <tr>
                            <th className="py-1 font-semibold">Lokasi</th>
                            <th />
                            <th className="py-1 text-right font-semibold">JKK %</th>
                            <th className="py-1 text-right font-semibold">UMK (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.jkk.map((l) => (
                            <tr key={l.id} className="border-t border-slate-100">
                                <td className="py-2 font-semibold text-slate-700">{l.name}</td>
                                <td className="py-2 text-xs text-slate-400">{l.type === 'site' ? 'Site' : 'Head Office'}</td>
                                <td className="py-2 text-right">
                                    <input
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        max={10}
                                        value={jkk[l.id] ?? ''}
                                        onChange={(e) => setJkk((j) => ({ ...j, [l.id]: e.target.value }))}
                                        className={`${smallInput} w-24 text-right`}
                                        aria-label={`Tarif JKK ${l.name}`}
                                    />
                                </td>
                                <td className="py-2 pl-2 text-right">
                                    <input
                                        type="number"
                                        min={0}
                                        step={1000}
                                        value={umk[l.id] ?? ''}
                                        onChange={(e) => setUmk((u) => ({ ...u, [l.id]: e.target.value }))}
                                        className={`${smallInput} w-36 text-right`}
                                        placeholder="–"
                                        aria-label={`UMK ${l.name}`}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p className="mt-1 text-[11px] text-slate-400">Kelas risiko PP 44/2015: 0,24 · 0,54 · 0,89 · 1,27 · 1,74%. Tambang umumnya kelas V (1,74%). UMK = batas bawah dasar iuran BPJS Kesehatan (kosongkan bila tidak dipakai).</p>
            </div>
            <label className="flex max-w-3xl items-start gap-2.5 text-sm text-slate-600">
                <input type="checkbox" checked={form.payslip_password} onChange={(e) => set('payslip_password', e.target.checked)} className="mt-0.5 rounded border-slate-300" />
                <span>
                    Slip gaji PDF di portal karyawan dibuka dengan password <b>tanggal lahir</b> karyawan (ddmmyyyy).
                    <span className="block text-[11px] text-slate-400">Slip yang diunduh HR dari Proses Gaji tidak berpassword.</span>
                </span>
            </label>
            <button onClick={save} disabled={busy} className={primaryButton}>
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan pengaturan
            </button>
        </section>
    );
}

function RatesForm({ rates }: { rates: PayrollRateRow[] }) {
    const [year, setYear] = useState(rates[0]?.year ?? new Date().getFullYear());
    const [extraYear, setExtraYear] = useState<number | null>(null);
    const base = rates.find((r) => r.year === year) ?? rates[0];
    const [form, setForm] = useState<PayrollRateRow>(() => ({ ...base, year }));
    const [busy, setBusy] = useState(false);
    const years = useMemo(() => [...new Set([...(extraYear ? [extraYear] : []), ...rates.map((r) => r.year)])].sort((a, b) => b - a), [rates, extraYear]);

    const pick = (y: number) => {
        setYear(y);
        setForm({ ...(rates.find((r) => r.year === y) ?? rates[0]), year: y });
    };
    const addYear = () => {
        const next = Math.max(...years) + 1;
        setExtraYear(next);
        setYear(next);
        setForm({ ...rates[0], year: next }); // starts from the latest year's figures
    };
    const save = () =>
        router.put('/compensation/settings/payroll/rates', form as never, { ...visit, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <h3 className="text-sm font-bold text-slate-700">Tarif BPJS & PPh21 per tahun</h3>
                <select value={year} onChange={(e) => pick(Number(e.target.value))} className={smallInput} aria-label="Tahun">
                    {years.map((y) => (
                        <option key={y} value={y}>
                            {y}
                            {rates.some((r) => r.year === y) ? '' : ' (baru)'}
                        </option>
                    ))}
                </select>
                <button onClick={addYear} className={secondaryButton}>
                    <Plus size={14} /> Tahun berikutnya
                </button>
            </div>
            <p className="text-[11px] text-slate-400">Tahun tanpa baris memakai tahun terakhir sebelumnya. Perbarui batas upah JP dan BPJS Kesehatan setiap tahun.</p>
            <div className="grid max-w-4xl gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                {RATE_FIELDS.map((f) => (
                    <Field key={f.key} label={f.label}>
                        {f.money ? (
                            <MoneyInput
                                value={form[f.key] as number}
                                onChange={(v) => setForm((x) => ({ ...x, [f.key]: v ?? 0 }))}
                                className={`${smallInput} w-full text-right`}
                                aria-label={f.label}
                            />
                        ) : (
                            <input
                                type="number"
                                step="0.01"
                                min={0}
                                max={100}
                                value={form[f.key] as number}
                                onChange={(e) => setForm((x) => ({ ...x, [f.key]: Number(e.target.value) }))}
                                className={`${smallInput} w-full text-right`}
                                aria-label={f.label}
                            />
                        )}
                    </Field>
                ))}
            </div>
            <button onClick={save} disabled={busy} className={primaryButton}>
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan tarif {form.year}
            </button>
        </section>
    );
}

function TerTable({ ter }: { ter: PayrollData['ter'] }) {
    const [open, setOpen] = useState(false);
    const categories = Object.keys(ter).sort();

    return (
        <section className="space-y-3">
            <button onClick={() => setOpen((o) => !o)} className="flex items-center gap-2 text-sm font-bold text-slate-700">
                <ChevronDown size={16} className={`transition-transform ${open ? '' : '-rotate-90'}`} /> Tabel TER bulanan (PP 58/2023)
            </button>
            <p className="text-[11px] text-slate-400">
                Kategori A: TK/0, TK/1, K/0 · B: TK/2, TK/3, K/1, K/2 · C: K/3. Dipakai Januari–November; Desember dihitung setahun dengan tarif Pasal 17.
            </p>
            {open && (
                <div className="grid gap-4 lg:grid-cols-3">
                    {categories.map((c) => (
                        <table key={c} className="w-full text-xs">
                            <thead>
                                <tr className={label}>
                                    <th className="py-1.5 text-left">Kategori {c}: bruto / bulan</th>
                                    <th className="py-1.5 text-right">Tarif</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ter[c].map((t) => (
                                    <tr key={t.lower_bound} className="border-t border-slate-100 tabular-nums">
                                        <td className="py-1 text-slate-600">
                                            {t.upper_bound === null ? `di atas ${rupiah(t.lower_bound - 1)}` : `${rupiah(t.lower_bound)} – ${rupiah(t.upper_bound)}`}
                                        </td>
                                        <td className="py-1 text-right font-semibold text-slate-700">{String(t.rate).replace('.', ',')}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ))}
                </div>
            )}
        </section>
    );
}

/** Komponen Input Variabel: what can be paid or deducted besides the fixed pay. */
function ComponentsForm({ data }: { data: PayrollData }) {
    const blank = { id: 0, code: '', name: '', kind: 'earning' as const, nature: 'variable', taxable: true, sort_order: 100, is_active: true, is_system: false };
    const [editing, setEditing] = useState<PayrollComponentRow | null>(null);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const save = () => {
        if (!editing) return;
        const opts = { ...visit, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setEditing(null) };
        if (editing.id) router.put(`/compensation/settings/payroll-components/${editing.id}`, editing as never, opts);
        else router.post('/compensation/settings/payroll-components', editing as never, opts);
    };

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-slate-700">Komponen Input Variabel</h3>
                <button onClick={() => setEditing(blank)} className={secondaryButton}>
                    <Plus size={15} /> Komponen
                </button>
            </div>
            <table className="w-full max-w-3xl text-sm">
                <thead className="text-left text-xs text-slate-400">
                    <tr>
                        <th className="py-1 font-semibold">Nama</th>
                        <th className="py-1 font-semibold">Kode (Excel)</th>
                        <th className="py-1 font-semibold">Jenis</th>
                        <th className="py-1 font-semibold">PPh21</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {data.components.map((c) => (
                        <tr key={c.id} className={`border-t border-slate-100 ${c.is_active ? '' : 'opacity-50'}`}>
                            <td className="py-2 font-semibold text-slate-700">
                                {c.name}
                                {!c.is_active && <span className="ml-1.5 text-xs font-normal text-slate-400">(nonaktif)</span>}
                            </td>
                            <td className="py-2 font-mono text-xs text-slate-500">{c.code}</td>
                            <td className="py-2 text-xs text-slate-600">{data.component_kinds[c.kind]}</td>
                            <td className="py-2 text-xs text-slate-600">
                                {c.kind === 'earning' ? (c.taxable ? 'Kena pajak' : 'Tidak kena pajak') : c.taxable ? 'Dipotong sebelum pajak' : 'Dipotong setelah pajak'}
                            </td>
                            <td className="py-2 text-right">
                                <button onClick={() => setEditing(c)} className="text-xs font-bold text-brand-600 hover:underline">
                                    Ubah
                                </button>
                                {c.is_active && (
                                    <button
                                        onClick={() => confirm(`Hapus / nonaktifkan ${c.name}?`) && router.delete(`/compensation/settings/payroll-components/${c.id}`, visit)}
                                        className="ml-3 text-xs font-bold text-rose-600 hover:underline"
                                    >
                                        {c.is_system ? 'Nonaktifkan' : 'Hapus'}
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {editing && (
                <div className="grid max-w-3xl gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
                    <Field label="Nama (tampil di slip)">
                        <input value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} className={`${smallInput} w-full`} />
                    </Field>
                    <Field label="Kode untuk Excel" hint="Huruf kecil, angka, garis bawah.">
                        <input value={editing.code} onChange={(e) => setEditing({ ...editing, code: e.target.value.toLowerCase() })} className={`${smallInput} w-full font-mono`} />
                    </Field>
                    <Field label="Jenis">
                        <select value={editing.kind} onChange={(e) => setEditing({ ...editing, kind: e.target.value as 'earning' | 'deduction' })} className={`${smallInput} w-full`}>
                            {Object.entries(data.component_kinds).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Sifat">
                        <select value={editing.nature} onChange={(e) => setEditing({ ...editing, nature: e.target.value })} className={`${smallInput} w-full`}>
                            {Object.entries(data.component_natures).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <label className="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                        <input type="checkbox" checked={editing.taxable} onChange={(e) => setEditing({ ...editing, taxable: e.target.checked })} />
                        {editing.kind === 'earning' ? 'Kena PPh21 (masuk penghasilan bruto)' : 'Dipotong sebelum pajak (mengurangi penghasilan bruto)'}
                    </label>
                    {editing.id > 0 && (
                        <label className="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                            <input type="checkbox" checked={editing.is_active} onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })} />
                            Aktif
                        </label>
                    )}
                    {(errors.code || errors.name) && <p className="text-xs font-semibold text-rose-600 sm:col-span-2">{errors.code || errors.name}</p>}
                    <div className="flex gap-2 sm:col-span-2">
                        <button onClick={save} disabled={busy || !editing.name || !editing.code} className={primaryButton}>
                            {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan komponen
                        </button>
                        <button onClick={() => setEditing(null)} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-500 hover:bg-slate-100">
                            Batal
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
