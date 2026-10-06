import { Fragment, useMemo, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { CrudTable } from '@/components/masterdata/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/masterdata/DirtyGuard';
import { AttendanceHeader, Modal, ghostButton, primaryButton, smallInput } from '@/components/attendance/ui';
import { MoneyInput } from '@/components/comben/MoneyInput';
import { AturanCutiSection, CutiLapanganSection, type CutiAturanData, type CutiLapanganRow } from '@/components/comben/CutiMaster';
import { rupiah } from '@/lib/comben';

interface GolonganRow {
    id: number;
    grup: string;
    kode: string;
    karyawan_count: number;
    skala_count: number;
    plafon_count: number;
}

interface Kolom {
    id: number;
    tahun: number;
    work_location_id: number | null;
    label: string;
}

interface SkalaRow {
    id: number;
    golongan_id: number;
    kode: string;
    grup: string;
    step: string | null;
    nilai: Record<string, number>;
}

interface PlafonBase {
    id: number;
    layer: string;
    golongan_ids: number[];
    sub_group_ids: number[];
    jabatan_ho: string | null;
    jabatan_site: string | null;
}
type PlafonPengobatan = PlafonBase & { lajang: number; keluarga: number };
type PlafonKacamata = PlafonBase & { bingkai: number; lensa: number };

interface Props {
    golongans: GolonganRow[];
    grups: string[];
    kolom: Kolom[];
    skala: SkalaRow[];
    plafon_pengobatan: PlafonPengobatan[];
    plafon_kacamata: PlafonKacamata[];
    sub_groups: { id: number; nama: string; layer: number }[];
    work_locations: { id: number; name: string }[];
    cuti_aturan: CutiAturanData;
    cuti_lapangan: CutiLapanganRow[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
}

const BASE = '/comben/master-data';
const visit = { preserveScroll: true, preserveState: true } as const;

export default function CombenMasterData(props: Props) {
    return (
        <DirtyGuardProvider>
            <Page {...props} />
        </DirtyGuardProvider>
    );
}

function Page(props: Props) {
    const guard = useGuard();
    const sections: { id: string; label: string; hint: string; render: () => ReactNode }[] = [
        {
            id: 'golongan',
            label: 'Golongan',
            hint: 'Golongan per group. Karyawan memilih golongannya di profil (bagian 4. Golongan & Hak Normatif); mengganti kode ikut memperbarui karyawan yang memakainya.',
            render: () => <GolonganSection golongans={props.golongans} grups={props.grups} />,
        },
        {
            id: 'skala-upah',
            label: 'Struktur Skala Upah',
            hint: 'Upah per golongan (dan step) untuk tiap kolom: tahun, berlaku untuk semua lokasi atau satu site.',
            render: () => <SkalaSection {...props} />,
        },
        {
            id: 'plafon-pengobatan',
            label: 'Plafon Pengobatan',
            hint: 'Bantuan pengobatan rawat jalan per tahun, untuk satu keluarga. Baris dipilih dari SubGroup karyawan; golongan hanya dicek masuk rentang. Lajang = TK/0, Keluarga = K/… atau punya anak.',
            render: () => <PlafonSection jenis="pengobatan" rows={props.plafon_pengobatan} {...props} />,
        },
        {
            id: 'plafon-kacamata',
            label: 'Plafon Kacamata',
            hint: 'Untuk tiap anggota keluarga terdaftar (karyawan, istri/suami, tiap anak). Bingkai 2 tahun sekali, lensa 1 tahun sekali; Lengkap = bingkai + lensa.',
            render: () => <PlafonSection jenis="kacamata" rows={props.plafon_kacamata} {...props} />,
        },
        {
            id: 'aturan-cuti',
            label: 'Aturan Cuti',
            hint: 'Jatah dan masa berlaku cuti tahunan, serta cuti besar: tiap berapa tahun, lamanya, dan uangnya (diambil / diuangkan). Berlaku untuk semua karyawan.',
            render: () => <AturanCutiSection aturan={props.cuti_aturan} />,
        },
        {
            id: 'cuti-lapangan',
            label: 'Cuti Lapangan',
            hint: 'Hari cuti lapangan + hari perjalanan berangkat dan kembali per lokasi; bisa dibedakan per sub lokasi. Pengajuan Cuti Lapangan yang melebihi totalnya memotong cuti tahunan.',
            render: () => <CutiLapanganSection rows={props.cuti_lapangan} workLocations={props.work_locations} subLocations={props.sub_locations} />,
        },
    ];
    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'golongan');
    const active = sections.find((s) => s.id === activeId) ?? sections[0];
    const select = (id: string) =>
        guard(() => {
            setActiveId(id);
            window.history.replaceState(window.history.state, '', `${BASE}?tab=${id}`);
        });

    return (
        <>
            <Head title="Master Data Comben" />
            <AttendanceHeader title="Master Data Comben" />
            <div className="flex min-h-0 flex-1 bg-slate-50">
                <nav className="w-60 shrink-0 space-y-1 overflow-y-auto border-r border-slate-200 bg-white p-4">
                    {sections.map((s) => (
                        <button
                            key={s.id}
                            onClick={() => s.id !== active.id && select(s.id)}
                            className={`block w-full rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                s.id === active.id ? 'bg-brand-50 font-bold text-brand-600' : 'text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            {s.label}
                        </button>
                    ))}
                </nav>
                <div className="min-w-0 flex-1 overflow-y-auto p-6">
                    <div className="w-full space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <h2 className="text-lg font-bold text-slate-800">{active.label}</h2>
                            <p className="mt-0.5 text-xs text-slate-400">{active.hint}</p>
                        </div>
                        <Fragment key={active.id}>{active.render()}</Fragment>
                    </div>
                </div>
            </div>
        </>
    );
}

// --- Golongan ------------------------------------------------------------------------------

function GolonganSection({ golongans, grups }: { golongans: GolonganRow[]; grups: string[] }) {
    const [newGrup, setNewGrup] = useState('');
    const [newKode, setNewKode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const count = (n: number, noun: string) => (n ? `${n} ${noun}` : '-');

    return (
        <div className="space-y-6">
            {grups.map((grup) => (
                <div key={grup} className="space-y-2">
                    <h3 className="flex items-center gap-2 text-sm font-black tracking-wide text-slate-700">
                        {grup}
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                            {golongans.filter((g) => g.grup === grup).length} golongan
                        </span>
                    </h3>
                    <CrudTable
                        title={`Golongan ${grup}`}
                        rows={golongans.filter((g) => g.grup === grup)}
                        baseUrl={`${BASE}/golongan`}
                        fields={[{ key: 'kode', label: 'Kode Golongan', placeholder: 'mis. 1A', width: 'w-48' }]}
                        toForm={(r) => ({ kode: r.kode, grup: r.grup })}
                        emptyForm={{ kode: '', grup }}
                        extraColumns={[
                            { label: 'Karyawan', render: (r) => count(r.karyawan_count, 'orang') },
                            { label: 'Skala upah', render: (r) => count(r.skala_count, 'baris') },
                            { label: 'Plafon', render: (r) => count(r.plafon_count, 'baris') },
                        ]}
                        deleteBlockedReason={(r) => (r.karyawan_count ? `Masih dipakai ${r.karyawan_count} karyawan` : null)}
                        describe={(r) => `golongan ${r.kode} (baris skala upahnya ikut terhapus)`}
                    />
                </div>
            ))}
            <div className="flex flex-wrap items-end gap-2 rounded-2xl border border-dashed border-slate-300 p-4">
                <div>
                    <label className="mb-1 block text-[11px] font-bold text-slate-500">Group baru</label>
                    <input
                        value={newGrup}
                        onChange={(e) => setNewGrup(e.target.value.toUpperCase())}
                        placeholder="mis. DRIVER"
                        className={`${smallInput} w-40`}
                    />
                </div>
                <div>
                    <label className="mb-1 block text-[11px] font-bold text-slate-500">Golongan pertama</label>
                    <input
                        value={newKode}
                        onChange={(e) => setNewKode(e.target.value.toUpperCase())}
                        placeholder="mis. DR1"
                        className={`${smallInput} w-32`}
                    />
                </div>
                <button
                    disabled={!newGrup.trim() || !newKode.trim()}
                    onClick={() =>
                        router.post(
                            `${BASE}/golongan`,
                            { grup: newGrup, kode: newKode },
                            {
                                ...visit,
                                onSuccess: () => {
                                    setNewGrup('');
                                    setNewKode('');
                                    setError(null);
                                },
                                onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
                            },
                        )
                    }
                    className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    <Plus size={14} />
                    Tambah group
                </button>
                {error && <p className="w-full text-xs text-rose-600">{error}</p>}
            </div>
        </div>
    );
}

// --- Struktur Skala Upah ---------------------------------------------------------------------

function SkalaSection({ golongans, grups, kolom, skala, work_locations }: Props) {
    const [grup, setGrup] = useState(grups[0] ?? '');
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const rows = skala.filter((s) => s.grup === grup);
    const grupGolongan = golongans.filter((g) => g.grup === grup);
    const [kolomForm, setKolomForm] = useState<{ tahun: string; work_location_id: string } | null>(null);
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const fail = (e: Record<string, string>) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.');

    const removeKolom = (k: Kolom) => {
        if (confirm(`Hapus kolom ${k.label}? Semua nilai upah di kolom itu ikut terhapus.`))
            router.delete(`${BASE}/skala-kolom/${k.id}`, { ...visit, onError: fail });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap rounded-xl bg-slate-100 p-1">
                    {grups.map((g) => (
                        <button
                            key={g}
                            onClick={() => {
                                setGrup(g);
                                setEditing(null);
                            }}
                            className={`rounded-lg px-3 py-1 text-xs font-bold ${grup === g ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                        >
                            {g}
                        </button>
                    ))}
                </div>
                <div className="ml-auto flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="font-bold text-slate-500">Kolom:</span>
                    {kolom.map((k) => (
                        <span key={k.id} className="flex items-center gap-1 rounded-lg border border-slate-200 py-0.5 pr-1 pl-2 text-slate-600">
                            {k.label}
                            <button
                                onClick={() => removeKolom(k)}
                                title="Hapus kolom"
                                className="rounded p-0.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                            >
                                <X size={12} />
                            </button>
                        </span>
                    ))}
                    {kolomForm ? (
                        <span className="flex items-center gap-1">
                            <input
                                type="number"
                                value={kolomForm.tahun}
                                onChange={(e) => setKolomForm({ ...kolomForm, tahun: e.target.value })}
                                placeholder="Tahun"
                                className={`${smallInput} w-24`}
                            />
                            <select
                                value={kolomForm.work_location_id}
                                onChange={(e) => setKolomForm({ ...kolomForm, work_location_id: e.target.value })}
                                className={smallInput}
                                aria-label="Lokasi"
                            >
                                <option value="">Semua lokasi</option>
                                {work_locations.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        Site {w.name}
                                    </option>
                                ))}
                            </select>
                            <button
                                onClick={() =>
                                    router.post(
                                        `${BASE}/skala-kolom`,
                                        { tahun: kolomForm.tahun, work_location_id: kolomForm.work_location_id || null },
                                        { ...visit, onSuccess: () => setKolomForm(null), onError: fail },
                                    )
                                }
                                className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50"
                                title="Simpan kolom"
                            >
                                <Check size={15} />
                            </button>
                            <button onClick={() => setKolomForm(null)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" title="Batal">
                                <X size={15} />
                            </button>
                        </span>
                    ) : (
                        <button
                            onClick={() => setKolomForm({ tahun: String(new Date().getFullYear() + 1), work_location_id: '' })}
                            className="flex items-center gap-1 rounded-lg px-2 py-1 font-bold text-brand-600 hover:bg-brand-50"
                        >
                            <Plus size={13} />
                            Kolom
                        </button>
                    )}
                </div>
            </div>

            {error && <p className="rounded-xl bg-rose-50 px-4 py-2.5 text-sm text-rose-600">{error}</p>}

            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className={`${th} w-40`}>Golongan</th>
                            <th className={`${th} w-36`}>Step</th>
                            {kolom.map((k) => (
                                <th key={k.id} className={`${th} text-right`}>
                                    {k.label} (IDR)
                                </th>
                            ))}
                            <th className={`${th} w-24 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r) =>
                            editing === r.id ? (
                                <SkalaEditor
                                    key={r.id}
                                    row={r}
                                    golongans={grupGolongan}
                                    kolom={kolom}
                                    onDone={() => setEditing(null)}
                                    onError={fail}
                                />
                            ) : (
                                <tr key={r.id} className="hover:bg-slate-50/60">
                                    <td className="px-3 py-2 font-bold text-slate-700">{r.kode}</td>
                                    <td className="px-3 py-2 text-slate-500">{r.step ?? '-'}</td>
                                    {kolom.map((k) => (
                                        <td key={k.id} className="px-3 py-2 text-right text-slate-700 tabular-nums">
                                            {r.nilai[k.id] !== undefined ? rupiah(r.nilai[k.id]) : <span className="text-slate-300">-</span>}
                                        </td>
                                    ))}
                                    <td className="px-3 py-2">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                onClick={() => setEditing(r.id)}
                                                title="Ubah"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                            >
                                                <Pencil size={15} />
                                            </button>
                                            <button
                                                onClick={() =>
                                                    confirm(`Hapus baris ${r.kode}${r.step ? ` ${r.step}` : ''}?`) &&
                                                    router.delete(`${BASE}/skala-upah/${r.id}`, { ...visit, onError: fail })
                                                }
                                                title="Hapus"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            >
                                                <Trash2 size={15} />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ),
                        )}
                        {editing === 'new' ? (
                            <SkalaEditor golongans={grupGolongan} kolom={kolom} onDone={() => setEditing(null)} onError={fail} />
                        ) : (
                            <tr className="bg-slate-50/60">
                                <td colSpan={kolom.length + 3} className="px-3 py-2">
                                    <button
                                        onClick={() => setEditing('new')}
                                        disabled={!grupGolongan.length}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                    >
                                        <Plus size={14} />
                                        Tambah baris {grup}
                                    </button>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function SkalaEditor({
    row,
    golongans,
    kolom,
    onDone,
    onError,
}: {
    row?: SkalaRow;
    golongans: GolonganRow[];
    kolom: Kolom[];
    onDone: () => void;
    onError: (e: Record<string, string>) => void;
}) {
    const [golonganId, setGolonganId] = useState(row?.golongan_id ?? golongans[0]?.id ?? 0);
    const [step, setStep] = useState(row?.step ?? '');
    const [nilai, setNilai] = useState<Record<string, number | null>>(() => Object.fromEntries(kolom.map((k) => [k.id, row?.nilai[k.id] ?? null])));
    const [busy, setBusy] = useState(false);

    const save = () => {
        const data = { golongan_id: golonganId, step: step || null, nilai } as never;
        const options = { ...visit, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onDone, onError };
        if (row) router.put(`${BASE}/skala-upah/${row.id}`, data, options);
        else router.post(`${BASE}/skala-upah`, data, options);
    };

    return (
        <tr className="bg-brand-50/40">
            <td className="px-3 py-2">
                <select
                    value={golonganId}
                    onChange={(e) => setGolonganId(Number(e.target.value))}
                    className={`${smallInput} w-full`}
                    aria-label="Golongan"
                >
                    {golongans.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.kode}
                        </option>
                    ))}
                </select>
            </td>
            <td className="px-3 py-2">
                <input value={step} onChange={(e) => setStep(e.target.value)} placeholder="mis. Step 1" className={`${smallInput} w-full`} />
            </td>
            {kolom.map((k) => (
                <td key={k.id} className="px-3 py-2">
                    <MoneyInput
                        value={nilai[k.id] ?? null}
                        onChange={(v) => setNilai((n) => ({ ...n, [k.id]: v }))}
                        className={`${smallInput} w-full`}
                        aria-label={k.label}
                    />
                </td>
            ))}
            <td className="px-3 py-2">
                <div className="flex justify-end gap-1">
                    <button
                        onClick={save}
                        disabled={busy}
                        title="Simpan"
                        className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50 disabled:opacity-50"
                    >
                        {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
                    </button>
                    <button onClick={onDone} title="Batal" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={15} />
                    </button>
                </div>
            </td>
        </tr>
    );
}

// --- Plafon ------------------------------------------------------------------------------

/** "1A–2E, APP–D5S": consecutive golongan (in master order, within a group) shortened to ranges. */
function golonganRanges(ids: number[], golongans: GolonganRow[]): string {
    const chosen = new Set(ids);
    const parts: string[] = [];
    let run: GolonganRow[] = [];
    const flush = () => {
        if (run.length) parts.push(run.length > 1 ? `${run[0].kode}–${run[run.length - 1].kode}` : run[0].kode);
        run = [];
    };
    golongans.forEach((g, i) => {
        if (chosen.has(g.id) && (!run.length || golongans[i - 1]?.grup === g.grup)) run.push(g);
        else {
            flush();
            if (chosen.has(g.id)) run.push(g);
        }
    });
    flush();
    return parts.join(', ') || '-';
}

function PlafonSection({
    jenis,
    rows,
    golongans,
    sub_groups,
}: Props & { jenis: 'pengobatan' | 'kacamata'; rows: (PlafonPengobatan | PlafonKacamata)[] }) {
    const [editing, setEditing] = useState<PlafonBase | 'new' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const subGroupName = useMemo(() => new Map(sub_groups.map((s) => [s.id, s.nama])), [sub_groups]);
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const amounts: [string, string][] =
        jenis === 'pengobatan'
            ? [
                  ['lajang', 'Lajang'],
                  ['keluarga', 'Keluarga'],
              ]
            : [
                  ['bingkai', 'Bingkai'],
                  ['lensa', 'Lensa'],
              ];
    const value = (r: PlafonBase, key: string) => (r as unknown as Record<string, number>)[key];
    const unmapped = sub_groups.filter((s) => !rows.some((r) => r.sub_group_ids.includes(s.id)));

    return (
        <div className="space-y-3">
            {error && <p className="rounded-xl bg-rose-50 px-4 py-2.5 text-sm text-rose-600">{error}</p>}
            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className={`${th} w-16`}>Layer</th>
                            <th className={th}>Golongan</th>
                            <th className={th}>SubGroup</th>
                            <th className={th}>Jabatan HO</th>
                            <th className={th}>Jabatan Site</th>
                            {amounts.map(([k, l]) => (
                                <th key={k} className={`${th} text-right`}>
                                    {l}
                                </th>
                            ))}
                            {jenis === 'kacamata' && <th className={`${th} text-right`}>Lengkap</th>}
                            <th className={`${th} w-24 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r) => (
                            <tr key={r.id} className="align-top hover:bg-slate-50/60">
                                <td className="px-3 py-2.5 font-black text-slate-700">{r.layer}</td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{golonganRanges(r.golongan_ids, golongans)}</td>
                                <td className="px-3 py-2.5">
                                    <div className="flex flex-wrap gap-1">
                                        {r.sub_group_ids.map((id) => (
                                            <span key={id} className="rounded-md bg-brand-50 px-1.5 py-0.5 text-[11px] font-bold text-brand-700">
                                                {subGroupName.get(id) ?? '?'}
                                            </span>
                                        ))}
                                    </div>
                                </td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{r.jabatan_ho ?? <span className="text-slate-300">—</span>}</td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{r.jabatan_site ?? <span className="text-slate-300">—</span>}</td>
                                {amounts.map(([k]) => (
                                    <td key={k} className="px-3 py-2.5 text-right font-bold text-slate-700 tabular-nums">
                                        {rupiah(value(r, k))}
                                    </td>
                                ))}
                                {jenis === 'kacamata' && (
                                    <td className="px-3 py-2.5 text-right font-black text-slate-800 tabular-nums">
                                        {rupiah(value(r, 'bingkai') + value(r, 'lensa'))}
                                    </td>
                                )}
                                <td className="px-3 py-2.5">
                                    <div className="flex justify-end gap-1">
                                        <button
                                            onClick={() => setEditing(r)}
                                            title="Ubah"
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                        >
                                            <Pencil size={15} />
                                        </button>
                                        <button
                                            onClick={() =>
                                                confirm(
                                                    `Hapus baris plafon ${r.layer}? Karyawan di SubGroup-nya tidak punya plafon ${jenis} sampai ditambahkan lagi.`,
                                                ) &&
                                                router.delete(`${BASE}/plafon/${jenis}/${r.id}`, {
                                                    ...visit,
                                                    onError: (e) => setError(Object.values(e)[0] ?? 'Gagal.'),
                                                })
                                            }
                                            title="Hapus"
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex flex-wrap items-center gap-3">
                <button
                    onClick={() => setEditing('new')}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                >
                    <Plus size={14} />
                    Tambah baris
                </button>
                {unmapped.length > 0 && (
                    <p className="text-xs text-amber-700">
                        SubGroup tanpa plafon {jenis}: <b>{unmapped.map((s) => s.nama).join(', ')}</b>
                    </p>
                )}
            </div>
            {editing && (
                <PlafonEditor
                    jenis={jenis}
                    row={editing === 'new' ? undefined : editing}
                    amounts={amounts}
                    golongans={golongans}
                    subGroups={sub_groups}
                    usedElsewhere={new Set(rows.filter((r) => editing === 'new' || r.id !== editing.id).flatMap((r) => r.sub_group_ids))}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function PlafonEditor({
    jenis,
    row,
    amounts,
    golongans,
    subGroups,
    usedElsewhere,
    onClose,
}: {
    jenis: 'pengobatan' | 'kacamata';
    row?: PlafonBase;
    amounts: [string, string][];
    golongans: GolonganRow[];
    subGroups: { id: number; nama: string; layer: number }[];
    usedElsewhere: Set<number>;
    onClose: () => void;
}) {
    const [layer, setLayer] = useState(row?.layer ?? 'L1');
    const [golonganIds, setGolonganIds] = useState<Set<number>>(new Set(row?.golongan_ids ?? []));
    const [subGroupIds, setSubGroupIds] = useState<Set<number>>(new Set(row?.sub_group_ids ?? []));
    const [jabatanHo, setJabatanHo] = useState(row?.jabatan_ho ?? '');
    const [jabatanSite, setJabatanSite] = useState(row?.jabatan_site ?? '');
    const [values, setValues] = useState<Record<string, number | null>>(() =>
        Object.fromEntries(amounts.map(([k]) => [k, row ? (row as unknown as Record<string, number>)[k] : null])),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const grups = [...new Set(golongans.map((g) => g.grup))];

    const toggle = (set: Set<number>, id: number, on: boolean) => {
        const next = new Set(set);
        if (on) next.add(id);
        else next.delete(id);
        return next;
    };

    const save = () => {
        const data = {
            layer,
            golongan_ids: [...golonganIds],
            sub_group_ids: [...subGroupIds],
            jabatan_ho: jabatanHo || null,
            jabatan_site: jabatanSite || null,
            ...Object.fromEntries(amounts.map(([k]) => [k, values[k] ?? 0])),
        } as never;
        const options = {
            ...visit,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onClose,
            onError: (e: Record<string, string>) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
        };
        if (row) router.put(`${BASE}/plafon/${jenis}/${row.id}`, data, options);
        else router.post(`${BASE}/plafon/${jenis}`, data, options);
    };

    return (
        <Modal
            title={`${row ? 'Ubah' : 'Tambah'} Plafon ${jenis === 'pengobatan' ? 'Pengobatan' : 'Kacamata'}`}
            subtitle="Karyawan masuk baris ini lewat SubGroup-nya; golongan dicek masuk rentang."
            onClose={onClose}
            width="max-w-3xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy || !subGroupIds.size} className={primaryButton}>
                        {busy ? <Loader2 size={16} className="animate-spin" /> : <Check size={16} />}
                        Simpan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid grid-cols-[100px_1fr_1fr] gap-3">
                    <Labeled label="Layer">
                        <input value={layer} onChange={(e) => setLayer(e.target.value.toUpperCase())} className={`${smallInput} w-full`} />
                    </Labeled>
                    <Labeled label="Jabatan HO">
                        <input
                            value={jabatanHo}
                            onChange={(e) => setJabatanHo(e.target.value)}
                            placeholder="mis. Officer"
                            className={`${smallInput} w-full`}
                        />
                    </Labeled>
                    <Labeled label="Jabatan Site">
                        <input
                            value={jabatanSite}
                            onChange={(e) => setJabatanSite(e.target.value)}
                            placeholder="mis. Group Leader"
                            className={`${smallInput} w-full`}
                        />
                    </Labeled>
                </div>
                <div className={`grid gap-3 ${amounts.length === 2 && jenis === 'kacamata' ? 'grid-cols-3' : 'grid-cols-2'}`}>
                    {amounts.map(([k, l]) => (
                        <Labeled key={k} label={`${l} (Rp)`}>
                            <MoneyInput
                                value={values[k] ?? null}
                                onChange={(v) => setValues((s) => ({ ...s, [k]: v }))}
                                className={`${smallInput} w-full`}
                            />
                        </Labeled>
                    ))}
                    {jenis === 'kacamata' && (
                        <Labeled label="Lengkap (otomatis)">
                            <p className="py-1.5 text-right text-sm font-black text-slate-700 tabular-nums">
                                {rupiah((values.bingkai ?? 0) + (values.lensa ?? 0))}
                            </p>
                        </Labeled>
                    )}
                </div>

                <Labeled label="SubGroup (penentu plafon)">
                    <div className="flex flex-wrap gap-1.5">
                        {subGroups.map((s) => {
                            const taken = usedElsewhere.has(s.id);
                            const on = subGroupIds.has(s.id);
                            return (
                                <label
                                    key={s.id}
                                    title={taken ? 'Sudah dipakai baris lain' : `Layer ${s.layer}`}
                                    className={`flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs ${
                                        on ? 'border-brand-500 bg-brand-50 font-bold text-brand-700' : 'border-slate-200 text-slate-600'
                                    } ${taken ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'}`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        disabled={taken}
                                        onChange={(e) => setSubGroupIds((set) => toggle(set, s.id, e.target.checked))}
                                        className="accent-brand-600"
                                    />
                                    {s.nama}
                                </label>
                            );
                        })}
                    </div>
                </Labeled>

                <Labeled label={`Golongan yang sesuai (${golonganIds.size} dipilih: ${golonganRanges([...golonganIds], golongans)})`}>
                    <div className="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                        {grups.map((grup) => {
                            const list = golongans.filter((g) => g.grup === grup);
                            const all = list.every((g) => golonganIds.has(g.id));
                            return (
                                <div key={grup} className="flex flex-wrap items-center gap-1">
                                    <button
                                        onClick={() =>
                                            setGolonganIds((set) => {
                                                const next = new Set(set);
                                                for (const g of list) {
                                                    if (all) next.delete(g.id);
                                                    else next.add(g.id);
                                                }
                                                return next;
                                            })
                                        }
                                        className="w-24 shrink-0 text-left text-[10px] font-black tracking-wide text-slate-500 hover:text-brand-600"
                                        title={all ? 'Lepas semua' : 'Pilih semua'}
                                    >
                                        {grup}
                                    </button>
                                    {list.map((g) => (
                                        <button
                                            key={g.id}
                                            onClick={() => setGolonganIds((set) => toggle(set, g.id, !set.has(g.id)))}
                                            className={`rounded-md px-1.5 py-0.5 text-[11px] font-bold ${
                                                golonganIds.has(g.id) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                                            }`}
                                        >
                                            {g.kode}
                                        </button>
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                </Labeled>
                {error && <p className="text-xs text-rose-600">{error}</p>}
            </div>
        </Modal>
    );
}

function Labeled({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <label className="mb-1 block text-[11px] font-bold text-slate-500">{label}</label>
            {children}
        </div>
    );
}
