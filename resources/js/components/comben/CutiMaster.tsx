import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Loader2, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { ghostButton, primaryButton, smallInput } from '@/components/attendance/ui';
import { MoneyInput } from '@/components/comben/MoneyInput';
import { useDirtyForm, useGuard } from '@/components/masterdata/DirtyGuard';

export interface CutiAturanData {
    jatah_tahunan: number;
    masa_aktif_tahun: number;
    besar_interval_tahun: number;
    besar_hari: number;
    besar_gapok_diambil: number;
    besar_gapok_diuangkan: number;
    besar_batas_bulan: number;
}

export interface CutiLapanganRow {
    id: number;
    work_location_id: number;
    sub_location_id: number | null;
    lokasi: string | null;
    sub_lokasi: string | null;
    hari_cuti: number;
    hari_berangkat: number;
    hari_kembali: number;
    total: number;
}

const visit = { preserveScroll: true, preserveState: true } as const;

const FIELDS: { key: keyof CutiAturanData; label: string; suffix: string; group: 'tahunan' | 'besar'; step?: string }[] = [
    { key: 'jatah_tahunan', label: 'Jatah cuti tahunan', suffix: 'hari / tahun', group: 'tahunan' },
    { key: 'masa_aktif_tahun', label: 'Masa berlaku jatah', suffix: 'tahun (dipakai FIFO)', group: 'tahunan' },
    { key: 'besar_interval_tahun', label: 'Cuti besar tiap', suffix: 'tahun masa kerja', group: 'besar' },
    { key: 'besar_hari', label: 'Lama cuti besar bila diambil', suffix: 'hari', group: 'besar' },
    { key: 'besar_gapok_diambil', label: 'Uang cuti besar bila diambil', suffix: '× gaji pokok', group: 'besar', step: '0.5' },
    { key: 'besar_gapok_diuangkan', label: 'Uang cuti besar bila diuangkan', suffix: '× gaji pokok', group: 'besar', step: '0.5' },
    { key: 'besar_batas_bulan', label: 'Batas memilih (ambil / uangkan)', suffix: 'bulan sejak hak; lewat = diuangkan', group: 'besar' },
];

/** The leave rules: annual quota and its life, Cuti Besar interval, days and pay. */
export function AturanCutiSection({ aturan }: { aturan: CutiAturanData }) {
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(aturan).map(([k, v]) => [k, String(v)])));
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dirty = FIELDS.some((f) => values[f.key] !== String(aturan[f.key]));

    const save = () =>
        router.put('/comben/master-data/cuti-aturan', values, {
            ...visit,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: setErrors,
            onSuccess: () => setErrors({}),
        });

    return (
        <div className="space-y-5">
            {(['tahunan', 'besar'] as const).map((group) => (
                <div key={group} className="rounded-2xl border border-slate-200 p-4">
                    <h3 className="mb-3 text-sm font-bold text-slate-700">{group === 'tahunan' ? 'Cuti Tahunan' : 'Cuti Besar'}</h3>
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
    const empty = { work_location_id: '', sub_location_id: '', hari_cuti: '14', hari_berangkat: '2', hari_kembali: '2' };
    const [form, setForm] = useState<(typeof empty & { id?: number }) | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () => {
        if (!form) return;
        const { id, ...data } = form;
        const payload = { ...data, sub_location_id: data.sub_location_id || null };
        const options = { ...visit, onError: setErrors, onSuccess: () => (setForm(null), setErrors({})) };
        if (id) router.put(`/comben/master-data/cuti-lapangan/${id}`, payload, options);
        else router.post('/comben/master-data/cuti-lapangan', payload, options);
    };
    const remove = (r: CutiLapanganRow) => {
        if (confirm(`Hapus aturan cuti lapangan ${r.lokasi}${r.sub_lokasi ? ` · ${r.sub_lokasi}` : ''}?`))
            router.delete(`/comben/master-data/cuti-lapangan/${r.id}`, visit);
    };
    const total = form ? ['hari_cuti', 'hari_berangkat', 'hari_kembali'].reduce((n, k) => n + (Number(form[k as keyof typeof form]) || 0), 0) : 0;

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
                                <td className="px-3 py-2 font-bold text-slate-700">{r.lokasi}</td>
                                <td className="px-3 py-2 text-slate-600">
                                    {r.sub_lokasi ?? <span className="text-slate-400">Semua (default lokasi)</span>}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.hari_cuti}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.hari_berangkat}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{r.hari_kembali}</td>
                                <td className="px-3 py-2 text-right font-black text-brand-700 tabular-nums">{r.total}</td>
                                <td className="px-2 py-2 text-right">
                                    <button
                                        onClick={() =>
                                            setForm({
                                                id: r.id,
                                                work_location_id: String(r.work_location_id),
                                                sub_location_id: r.sub_location_id ? String(r.sub_location_id) : '',
                                                hari_cuti: String(r.hari_cuti),
                                                hari_berangkat: String(r.hari_berangkat),
                                                hari_kembali: String(r.hari_kembali),
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
                                ['hari_cuti', 'Hari cuti'],
                                ['hari_berangkat', 'Berangkat'],
                                ['hari_kembali', 'Kembali'],
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
    lokasi_penerimaan_id: number;
    klasifikasi: string;
    nominal: number;
}

type LumpsumValues = Record<string, Record<string, number | null>>;

/** Lumpsum Cuti Lapangan of one site: per POH (lokasi penerimaan in the NRP) × klasifikasi. Empty = no lumpsum. */
export function LumpsumSection({
    rows,
    poh,
    klasifikasi,
    workLocations,
}: {
    rows: LumpsumRow[];
    poh: { id: number; kode: string; nama: string }[];
    klasifikasi: string[];
    workLocations: { id: number; name: string }[];
}) {
    const guard = useGuard();
    const [site, setSite] = useState(workLocations[0]?.id ?? 0);
    const initial = (id: number): LumpsumValues =>
        Object.fromEntries(
            poh.map((p) => [
                p.id,
                Object.fromEntries(
                    klasifikasi.map((k) => [
                        k,
                        rows.find((r) => r.work_location_id === id && r.lokasi_penerimaan_id === p.id && r.klasifikasi === k)?.nominal ?? null,
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
                '/comben/master-data/lumpsum-cuti',
                { work_location_id: site, nominal: values },
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
    useDirtyForm('lumpsum-cuti', dirty, { label: `Lumpsum cuti lapangan ${siteName}`, save, discard: () => setValues(initial(site)) });
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
                            {klasifikasi.map((k) => (
                                <th key={k} className="px-3 py-2 text-right">
                                    {k} (Rp)
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {poh.map((p) => (
                            <tr key={p.id}>
                                <td className="px-3 py-2">
                                    <b className="text-slate-700">{p.nama}</b>
                                    <span className="ml-1.5 text-xs text-slate-400">kode NRP {p.kode}</span>
                                </td>
                                {klasifikasi.map((k) => (
                                    <td key={k} className="px-3 py-2">
                                        <MoneyInput
                                            value={values[p.id]?.[k] ?? null}
                                            onChange={(v) => setValues((cur) => ({ ...cur, [p.id]: { ...cur[p.id], [k]: v } }))}
                                            placeholder="tidak ada"
                                            aria-label={`Lumpsum ${siteName} POH ${p.nama} ${k}`}
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
