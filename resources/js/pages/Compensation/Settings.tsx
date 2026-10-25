import { Fragment, useMemo, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Check, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { CrudTable } from '@/components/settings/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/settings/DirtyGuard';
import { AttendanceHeader, Modal, ghostButton, primaryButton, smallInput } from '@/components/time/ui';
import { MoneyInput } from '@/components/compensation/MoneyInput';
import {
    AturanCutiSection,
    CutiLapanganSection,
    LumpsumSection,
    type CutiAturanData,
    type CutiLapanganRow,
    type LumpsumRow,
} from '@/components/compensation/LeaveSettings';
import { rupiah } from '@/lib/compensation';

interface GolonganRow {
    id: number;
    grade_group: string;
    code: string;
    employee_count: number;
    scale_count: number;
    benefit_limit_count: number;
}

interface Kolom {
    id: number;
    year: number;
    work_location_id: number | null;
    label: string;
}

interface SkalaRow {
    id: number;
    grade_id: number;
    code: string;
    grade_group: string;
    step: string | null;
    value: Record<string, number>;
}

interface PlafonBase {
    id: number;
    layer: string;
    grade_ids: number[];
    job_level_ids: number[];
    position_label_head_office: string | null;
    position_label_site: string | null;
}
type MedicalBenefitLimit = PlafonBase & { single: number; family: number };
type OpticalBenefitLimit = PlafonBase & { frame: number; lens: number };

interface Props {
    grades: GolonganRow[];
    grade_groups: string[];
    period_column: Kolom[];
    scale: SkalaRow[];
    medical_benefit_limit: MedicalBenefitLimit[];
    optical_benefit_limit: OpticalBenefitLimit[];
    job_levels: { id: number; name: string; layer: number }[];
    work_locations: { id: number; name: string }[];
    leave_policy: CutiAturanData;
    field_leave: CutiLapanganRow[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    field_leave_allowance: LumpsumRow[];
    point_of_hire: { id: number; code: string; name: string }[];
    employee_class: string[];
    position_allowance: Record<string, number>;
}

const BASE = '/compensation/settings';
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
            id: 'grade',
            label: 'Golongan',
            hint: 'Golongan per group. Karyawan memilih golongannya di profil (bagian 4. Golongan & Hak Normatif); mengganti kode ikut memperbarui karyawan yang memakainya.',
            render: () => <GolonganSection grades={props.grades} grade_groups={props.grade_groups} />,
        },
        {
            id: 'salary-scale',
            label: 'Struktur Skala Upah',
            hint: 'Upah per golongan (dan step) untuk tiap kolom: tahun, berlaku untuk semua lokasi atau satu site.',
            render: () => <SkalaSection {...props} />,
        },
        {
            id: 'benefit_limit-medical',
            label: 'Plafon Pengobatan',
            hint: 'Bantuan pengobatan rawat jalan per tahun, untuk satu keluarga. Baris dipilih dari SubGroup karyawan; golongan hanya dicek masuk rentang. Lajang = TK/0, Keluarga = K/… atau punya anak.',
            render: () => <PlafonSection type="medical" rows={props.medical_benefit_limit} {...props} />,
        },
        {
            id: 'benefit_limit-optical',
            label: 'Plafon Kacamata',
            hint: 'Untuk tiap anggota keluarga terdaftar (karyawan, istri/suami, tiap anak). Bingkai 2 tahun sekali, lensa 1 tahun sekali; Lengkap = bingkai + lensa.',
            render: () => <PlafonSection type="optical" rows={props.optical_benefit_limit} {...props} />,
        },
        {
            id: 'position-allowances',
            label: 'Tunjangan Jabatan',
            hint: 'Tunjangan tetap bulanan per SubGroup. Bersama Gaji Pokok (Struktur Skala Upah) menjadi upah tetap: dasar BPJS, THR, dan upah lembur per jam (1/173). Lihat per karyawan di Comben → Data Gaji.',
            render: () => <TunjanganSection jobLevels={props.job_levels} amount={props.position_allowance} />,
        },
        {
            id: 'policy-leave',
            label: 'Aturan Cuti',
            hint: 'Jatah dan masa berlaku cuti tahunan, serta cuti besar: tiap berapa tahun, lamanya, dan uangnya (diambil / diuangkan). Berlaku untuk semua karyawan.',
            render: () => <AturanCutiSection policy={props.leave_policy} />,
        },
        {
            id: 'field-leave',
            label: 'Cuti Lapangan',
            hint: 'Hari cuti lapangan + hari perjalanan berangkat dan kembali per lokasi; bisa dibedakan per sub lokasi. Pengajuan Cuti Lapangan yang melebihi totalnya memotong cuti tahunan.',
            render: () => <CutiLapanganSection rows={props.field_leave} workLocations={props.work_locations} subLocations={props.sub_locations} />,
        },
        {
            id: 'field-leave-allowances',
            label: 'Lumpsum Cuti Lapangan',
            hint: 'Uang lumpsum tiap pengajuan Cuti Lapangan, per site, POH (lokasi penerimaan di NRP digit 2–3) dan klasifikasi karyawan (Staff / Non Staff, dari profil).',
            render: () => (
                <LumpsumSection rows={props.field_leave_allowance} point_of_hire={props.point_of_hire} employee_class={props.employee_class} workLocations={props.work_locations} />
            ),
        },
    ];
    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'grade');
    const active = sections.find((s) => s.id === activeId) ?? sections[0];
    const select = (id: string) =>
        guard(() => {
            setActiveId(id);
            window.history.replaceState(window.history.state, '', `${BASE}?tab=${id}`);
        });

    return (
        <>
            <Head title="Pengaturan Kompensasi" />
            <AttendanceHeader title="Pengaturan Kompensasi" />
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

function GolonganSection({ grades, grade_groups }: { grades: GolonganRow[]; grade_groups: string[] }) {
    const [newGrup, setNewGrup] = useState('');
    const [newCode, setNewKode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const count = (n: number, noun: string) => (n ? `${n} ${noun}` : '-');

    return (
        <div className="space-y-6">
            {grade_groups.map((grade_group) => (
                <div key={grade_group} className="space-y-2">
                    <h3 className="flex items-center gap-2 text-sm font-black tracking-wide text-slate-700">
                        {grade_group}
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                            {grades.filter((g) => g.grade_group === grade_group).length} golongan
                        </span>
                    </h3>
                    <CrudTable
                        title={`Golongan ${grade_group}`}
                        rows={grades.filter((g) => g.grade_group === grade_group)}
                        baseUrl={`${BASE}/grade`}
                        fields={[{ key: 'code', label: 'Kode Golongan', placeholder: 'mis. 1A', width: 'w-48' }]}
                        toForm={(r) => ({ code: r.code, grade_group: r.grade_group })}
                        emptyForm={{ code: '', grade_group }}
                        extraColumns={[
                            { label: 'Karyawan', render: (r) => count(r.employee_count, 'orang') },
                            { label: 'Skala upah', render: (r) => count(r.scale_count, 'baris') },
                            { label: 'Plafon', render: (r) => count(r.benefit_limit_count, 'baris') },
                        ]}
                        deleteBlockedReason={(r) => (r.employee_count ? `Masih dipakai ${r.employee_count} karyawan` : null)}
                        describe={(r) => `golongan ${r.code} (baris skala upahnya ikut terhapus)`}
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
                        value={newCode}
                        onChange={(e) => setNewKode(e.target.value.toUpperCase())}
                        placeholder="mis. DR1"
                        className={`${smallInput} w-32`}
                    />
                </div>
                <button
                    disabled={!newGrup.trim() || !newCode.trim()}
                    onClick={() =>
                        router.post(
                            `${BASE}/grade`,
                            { grade_group: newGrup, code: newCode },
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

function SkalaSection({ grades, grade_groups, period_column, scale, work_locations }: Props) {
    const [grade_group, setGrup] = useState(grade_groups[0] ?? '');
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const rows = scale.filter((s) => s.grade_group === grade_group);
    const grupGolongan = grades.filter((g) => g.grade_group === grade_group);
    const [periodColumnForm, setKolomForm] = useState<{ year: string; work_location_id: string } | null>(null);
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const fail = (e: Record<string, string>) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.');

    const removeKolom = (k: Kolom) => {
        if (confirm(`Hapus kolom ${k.label}? Semua nilai upah di kolom itu ikut terhapus.`))
            router.delete(`${BASE}/salary-scale-periods/${k.id}`, { ...visit, onError: fail });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap rounded-xl bg-slate-100 p-1">
                    {grade_groups.map((g) => (
                        <button
                            key={g}
                            onClick={() => {
                                setGrup(g);
                                setEditing(null);
                            }}
                            className={`rounded-lg px-3 py-1 text-xs font-bold ${grade_group === g ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                        >
                            {g}
                        </button>
                    ))}
                </div>
                <div className="ml-auto flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="font-bold text-slate-500">Kolom:</span>
                    {period_column.map((k) => (
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
                    {periodColumnForm ? (
                        <span className="flex items-center gap-1">
                            <input
                                type="number"
                                value={periodColumnForm.year}
                                onChange={(e) => setKolomForm({ ...periodColumnForm, year: e.target.value })}
                                placeholder="Tahun"
                                className={`${smallInput} w-24`}
                            />
                            <select
                                value={periodColumnForm.work_location_id}
                                onChange={(e) => setKolomForm({ ...periodColumnForm, work_location_id: e.target.value })}
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
                                        `${BASE}/salary-scale-periods`,
                                        { year: periodColumnForm.year, work_location_id: periodColumnForm.work_location_id || null },
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
                            onClick={() => setKolomForm({ year: String(new Date().getFullYear() + 1), work_location_id: '' })}
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
                            {period_column.map((k) => (
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
                                <ScaleEditor
                                    key={r.id}
                                    row={r}
                                    grades={grupGolongan}
                                    period_column={period_column}
                                    onDone={() => setEditing(null)}
                                    onError={fail}
                                />
                            ) : (
                                <tr key={r.id} className="hover:bg-slate-50/60">
                                    <td className="px-3 py-2 font-bold text-slate-700">{r.code}</td>
                                    <td className="px-3 py-2 text-slate-500">{r.step ?? '-'}</td>
                                    {period_column.map((k) => (
                                        <td key={k.id} className="px-3 py-2 text-right text-slate-700 tabular-nums">
                                            {r.value[k.id] !== undefined ? rupiah(r.value[k.id]) : <span className="text-slate-300">-</span>}
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
                                                    confirm(`Hapus baris ${r.code}${r.step ? ` ${r.step}` : ''}?`) &&
                                                    router.delete(`${BASE}/salary-scale/${r.id}`, { ...visit, onError: fail })
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
                            <ScaleEditor grades={grupGolongan} period_column={period_column} onDone={() => setEditing(null)} onError={fail} />
                        ) : (
                            <tr className="bg-slate-50/60">
                                <td colSpan={period_column.length + 3} className="px-3 py-2">
                                    <button
                                        onClick={() => setEditing('new')}
                                        disabled={!grupGolongan.length}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                    >
                                        <Plus size={14} />
                                        Tambah baris {grade_group}
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

function ScaleEditor({
    row,
    grades,
    period_column,
    onDone,
    onError,
}: {
    row?: SkalaRow;
    grades: GolonganRow[];
    period_column: Kolom[];
    onDone: () => void;
    onError: (e: Record<string, string>) => void;
}) {
    const [gradeId, setGolonganId] = useState(row?.grade_id ?? grades[0]?.id ?? 0);
    const [step, setStep] = useState(row?.step ?? '');
    const [value, setNilai] = useState<Record<string, number | null>>(() => Object.fromEntries(period_column.map((k) => [k.id, row?.value[k.id] ?? null])));
    const [busy, setBusy] = useState(false);

    const save = () => {
        const data = { grade_id: gradeId, step: step || null, value } as never;
        const options = { ...visit, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onDone, onError };
        if (row) router.put(`${BASE}/salary-scale/${row.id}`, data, options);
        else router.post(`${BASE}/salary-scale`, data, options);
    };

    return (
        <tr className="bg-brand-50/40">
            <td className="px-3 py-2">
                <select
                    value={gradeId}
                    onChange={(e) => setGolonganId(Number(e.target.value))}
                    className={`${smallInput} w-full`}
                    aria-label="Golongan"
                >
                    {grades.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.code}
                        </option>
                    ))}
                </select>
            </td>
            <td className="px-3 py-2">
                <input value={step} onChange={(e) => setStep(e.target.value)} placeholder="mis. Step 1" className={`${smallInput} w-full`} />
            </td>
            {period_column.map((k) => (
                <td key={k.id} className="px-3 py-2">
                    <MoneyInput
                        value={value[k.id] ?? null}
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
function golonganRanges(ids: number[], grades: GolonganRow[]): string {
    const chosen = new Set(ids);
    const parts: string[] = [];
    let run: GolonganRow[] = [];
    const flush = () => {
        if (run.length) parts.push(run.length > 1 ? `${run[0].code}–${run[run.length - 1].code}` : run[0].code);
        run = [];
    };
    grades.forEach((g, i) => {
        if (chosen.has(g.id) && (!run.length || grades[i - 1]?.grade_group === g.grade_group)) run.push(g);
        else {
            flush();
            if (chosen.has(g.id)) run.push(g);
        }
    });
    flush();
    return parts.join(', ') || '-';
}

function PlafonSection({
    type,
    rows,
    grades,
    job_levels,
}: Props & { type: 'medical' | 'optical'; rows: (MedicalBenefitLimit | OpticalBenefitLimit)[] }) {
    const [editing, setEditing] = useState<PlafonBase | 'new' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const subGroupName = useMemo(() => new Map(job_levels.map((s) => [s.id, s.name])), [job_levels]);
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const amounts: [string, string][] =
        type === 'medical'
            ? [
                  ['single', 'Lajang'],
                  ['family', 'Keluarga'],
              ]
            : [
                  ['frame', 'Bingkai'],
                  ['lens', 'Lensa'],
              ];
    const value = (r: PlafonBase, key: string) => (r as unknown as Record<string, number>)[key];
    const unmapped = job_levels.filter((s) => !rows.some((r) => r.job_level_ids.includes(s.id)));

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
                            {type === 'optical' && <th className={`${th} text-right`}>Lengkap</th>}
                            <th className={`${th} w-24 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r) => (
                            <tr key={r.id} className="align-top hover:bg-slate-50/60">
                                <td className="px-3 py-2.5 font-black text-slate-700">{r.layer}</td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{golonganRanges(r.grade_ids, grades)}</td>
                                <td className="px-3 py-2.5">
                                    <div className="flex flex-wrap gap-1">
                                        {r.job_level_ids.map((id) => (
                                            <span key={id} className="rounded-md bg-brand-50 px-1.5 py-0.5 text-[11px] font-bold text-brand-700">
                                                {subGroupName.get(id) ?? '?'}
                                            </span>
                                        ))}
                                    </div>
                                </td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{r.position_label_head_office ?? <span className="text-slate-300">—</span>}</td>
                                <td className="px-3 py-2.5 text-xs text-slate-600">{r.position_label_site ?? <span className="text-slate-300">—</span>}</td>
                                {amounts.map(([k]) => (
                                    <td key={k} className="px-3 py-2.5 text-right font-bold text-slate-700 tabular-nums">
                                        {rupiah(value(r, k))}
                                    </td>
                                ))}
                                {type === 'optical' && (
                                    <td className="px-3 py-2.5 text-right font-black text-slate-800 tabular-nums">
                                        {rupiah(value(r, 'frame') + value(r, 'lens'))}
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
                                                    `Hapus baris plafon ${r.layer}? Karyawan di SubGroup-nya tidak punya plafon ${type} sampai ditambahkan lagi.`,
                                                ) &&
                                                router.delete(`${BASE}/benefit-limit/${type}/${r.id}`, {
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
                        SubGroup tanpa plafon {type}: <b>{unmapped.map((s) => s.name).join(', ')}</b>
                    </p>
                )}
            </div>
            {editing && (
                <BenefitLimitEditor
                    type={type}
                    row={editing === 'new' ? undefined : editing}
                    amounts={amounts}
                    grades={grades}
                    jobLevels={job_levels}
                    usedElsewhere={new Set(rows.filter((r) => editing === 'new' || r.id !== editing.id).flatMap((r) => r.job_level_ids))}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function BenefitLimitEditor({
    type,
    row,
    amounts,
    grades,
    jobLevels,
    usedElsewhere,
    onClose,
}: {
    type: 'medical' | 'optical';
    row?: PlafonBase;
    amounts: [string, string][];
    grades: GolonganRow[];
    jobLevels: { id: number; name: string; layer: number }[];
    usedElsewhere: Set<number>;
    onClose: () => void;
}) {
    const [layer, setLayer] = useState(row?.layer ?? 'L1');
    const [gradeIds, setGolonganIds] = useState<Set<number>>(new Set(row?.grade_ids ?? []));
    const [jobLevelIds, setSubGroupIds] = useState<Set<number>>(new Set(row?.job_level_ids ?? []));
    const [jabatanHo, setJabatanHo] = useState(row?.position_label_head_office ?? '');
    const [jabatanSite, setJabatanSite] = useState(row?.position_label_site ?? '');
    const [values, setValues] = useState<Record<string, number | null>>(() =>
        Object.fromEntries(amounts.map(([k]) => [k, row ? (row as unknown as Record<string, number>)[k] : null])),
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const grade_groups = [...new Set(grades.map((g) => g.grade_group))];

    const toggle = (set: Set<number>, id: number, on: boolean) => {
        const next = new Set(set);
        if (on) next.add(id);
        else next.delete(id);
        return next;
    };

    const save = () => {
        const data = {
            layer,
            grade_ids: [...gradeIds],
            job_level_ids: [...jobLevelIds],
            position_label_head_office: jabatanHo || null,
            position_label_site: jabatanSite || null,
            ...Object.fromEntries(amounts.map(([k]) => [k, values[k] ?? 0])),
        } as never;
        const options = {
            ...visit,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onClose,
            onError: (e: Record<string, string>) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
        };
        if (row) router.put(`${BASE}/benefit-limit/${type}/${row.id}`, data, options);
        else router.post(`${BASE}/benefit-limit/${type}`, data, options);
    };

    return (
        <Modal
            title={`${row ? 'Ubah' : 'Tambah'} Plafon ${type === 'medical' ? 'Pengobatan' : 'Kacamata'}`}
            subtitle="Karyawan masuk baris ini lewat SubGroup-nya; golongan dicek masuk rentang."
            onClose={onClose}
            width="max-w-3xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy || !jobLevelIds.size} className={primaryButton}>
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
                <div className={`grid gap-3 ${amounts.length === 2 && type === 'optical' ? 'grid-cols-3' : 'grid-cols-2'}`}>
                    {amounts.map(([k, l]) => (
                        <Labeled key={k} label={`${l} (Rp)`}>
                            <MoneyInput
                                value={values[k] ?? null}
                                onChange={(v) => setValues((s) => ({ ...s, [k]: v }))}
                                className={`${smallInput} w-full`}
                            />
                        </Labeled>
                    ))}
                    {type === 'optical' && (
                        <Labeled label="Lengkap (otomatis)">
                            <p className="py-1.5 text-right text-sm font-black text-slate-700 tabular-nums">
                                {rupiah((values.frame ?? 0) + (values.lens ?? 0))}
                            </p>
                        </Labeled>
                    )}
                </div>

                <Labeled label="SubGroup (penentu plafon)">
                    <div className="flex flex-wrap gap-1.5">
                        {jobLevels.map((s) => {
                            const taken = usedElsewhere.has(s.id);
                            const on = jobLevelIds.has(s.id);
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
                                    {s.name}
                                </label>
                            );
                        })}
                    </div>
                </Labeled>

                <Labeled label={`Golongan yang sesuai (${gradeIds.size} dipilih: ${golonganRanges([...gradeIds], grades)})`}>
                    <div className="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-slate-200 p-3">
                        {grade_groups.map((grade_group) => {
                            const list = grades.filter((g) => g.grade_group === grade_group);
                            const all = list.every((g) => gradeIds.has(g.id));
                            return (
                                <div key={grade_group} className="flex flex-wrap items-center gap-1">
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
                                        {grade_group}
                                    </button>
                                    {list.map((g) => (
                                        <button
                                            key={g.id}
                                            onClick={() => setGolonganIds((set) => toggle(set, g.id, !set.has(g.id)))}
                                            className={`rounded-md px-1.5 py-0.5 text-[11px] font-bold ${
                                                gradeIds.has(g.id) ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'
                                            }`}
                                        >
                                            {g.code}
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

// --- Tunjangan Jabatan ------------------------------------------------------------------------

function TunjanganSection({ jobLevels, amount }: { jobLevels: { id: number; name: string; layer: number }[]; amount: Record<string, number> }) {
    const initial = useMemo(() => Object.fromEntries(jobLevels.map((g) => [g.id, amount[g.id] ?? 0])), [jobLevels, amount]);
    const [values, setValues] = useState<Record<string, number>>(initial);
    const [busy, setBusy] = useState(false);
    const dirty = jobLevels.some((g) => (values[g.id] ?? 0) !== (initial[g.id] ?? 0));

    return (
        <div className="space-y-3">
            <table className="w-full max-w-2xl text-sm">
                <thead>
                    <tr className="text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                        <th className="py-2">SubGroup</th>
                        <th className="py-2">Layer</th>
                        <th className="py-2 text-right">Tunjangan Jabatan / bulan</th>
                    </tr>
                </thead>
                <tbody>
                    {jobLevels.map((g) => (
                        <tr key={g.id} className="border-t border-slate-100">
                            <td className="py-2 font-semibold text-slate-700">{g.name}</td>
                            <td className="py-2 text-xs text-slate-400">{g.layer}</td>
                            <td className="py-2 text-right">
                                <MoneyInput
                                    value={values[g.id] ?? 0}
                                    onChange={(v) => setValues((prev) => ({ ...prev, [g.id]: v ?? 0 }))}
                                    className={`${smallInput} w-44 text-right`}
                                    aria-label={`Tunjangan ${g.name}`}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <button
                onClick={() =>
                    router.put('/compensation/settings/position-allowances', { amount: values } as never, {
                        preserveScroll: true,
                        preserveState: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    })
                }
                disabled={!dirty || busy}
                className={primaryButton}
            >
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan tunjangan
            </button>
        </div>
    );
}
