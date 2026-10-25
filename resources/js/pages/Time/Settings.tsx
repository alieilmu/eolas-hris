import { Fragment, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { CrudTable } from '@/components/settings/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/settings/DirtyGuard';
import { OptionList, type OptionCategory } from '@/components/settings/OptionList';
import { AttendanceHeader, smallInput } from '@/components/time/ui';
import { JENIS_CELL, type Jenis, type Pattern, type PatternStep } from '@/lib/time';

interface CodeRow {
    id: number;
    code: string;
    daily_work_schedule: string | null;
    description: string | null;
    type: Jenis;
    deducts_base_salary: boolean;
    start_time: string | null;
    end_time: string | null;
    break_start: string | null;
    break_end: string | null;
    work_hours: number | null;
    usage: number;
    patterns: string[];
}

interface Props {
    codes: CodeRow[];
    type_options: Record<Jenis, string>;
    patterns: Pattern[];
    cash_advance_status: OptionCategory;
    public_holidays: { id: number; date: string; name: string }[];
    work_schedule: WaktuKerja[];
    scheme_options: Record<string, string>;
    overtime_policy: OvertimePolicy;
    overtime_eligibility: HakLembur[];
}

interface WaktuKerja {
    id: number;
    name: string;
    type: string;
    work_schedule_scheme: string;
    daily_normal_hours: number;
}

interface OvertimePolicy {
    rounding_minutes: number;
    minimum_minutes: number;
    count_before_shift: boolean;
    daily_limit_hours: number;
    weekly_limit_hours: number;
}

interface HakLembur {
    id: number;
    name: string;
    layer: number;
    ho: boolean;
    site: boolean;
}

const BASE = '/time/settings';

export default function AttendanceMasterData(props: Props) {
    return (
        <DirtyGuardProvider>
            <Page {...props} />
        </DirtyGuardProvider>
    );
}

function Page({ codes, type_options, patterns, cash_advance_status, public_holidays, work_schedule, scheme_options, overtime_policy, overtime_eligibility }: Props) {
    const guard = useGuard();
    const sections: { id: string; label: string; hint: string; render: () => ReactNode }[] = [
        {
            id: 'shift-codes',
            label: 'Kode Shift',
            hint: 'Kode Shift dipakai di Roster dan Absensi. DWS adalah terjemahannya untuk export (pilih "DWS" saat export). Jenis menentukan cara hitung % Kehadiran dan Man Onsite: Hadir = kerja di lokasi (onsite); Cuti & Dinas memakai kode pertama berjenis Dinas / Cuti. Jam masuk, keluar dan istirahat dipakai Kehadiran (CI/CO) dan Lembur; jam keluar lebih kecil dari jam masuk = shift malam (selesai besok). Jam istirahat tidak dihitung sebagai jam kerja. Potong Gapok = tiap hari dengan kode ini memotong gaji pokok (gapok ÷ hari kerja, lihat Pengaturan Kompensasi → Payroll); hanya untuk jenis Mangkir dan Izin, mis. A (ALPA) dan IP (Izin Potong Gapok).',
            render: () => (
                <CrudTable
                    title="Kode Shift"
                    rows={codes}
                    baseUrl={`${BASE}/shift-codes`}
                    fields={[
                        { key: 'code', label: 'Kode Shift', placeholder: 'mis. D', width: 'w-28' },
                        { key: 'daily_work_schedule', label: 'DWS', placeholder: 'mis. DS07', width: 'w-36' },
                        { key: 'description', label: 'Keterangan', placeholder: 'mis. Day shift 07.00–19.00' },
                        {
                            key: 'type',
                            label: 'Jenis',
                            type: 'select',
                            width: 'w-52',
                            options: Object.entries(type_options).map(([value, label]) => ({ value, label })),
                        },
                        {
                            key: 'deducts_base_salary',
                            label: 'Potong Gapok',
                            type: 'select',
                            width: 'w-28',
                            options: [
                                { value: '0', label: 'Tidak' },
                                { value: '1', label: 'Ya', display: 'Ya (gapok ÷ hari kerja)' },
                            ],
                        },
                        { key: 'start_time', label: 'Masuk', type: 'time', width: 'w-28' },
                        { key: 'end_time', label: 'Keluar', type: 'time', width: 'w-28' },
                        { key: 'break_start', label: 'Istirahat', type: 'time', width: 'w-28' },
                        { key: 'break_end', label: 's/d', type: 'time', width: 'w-28' },
                    ]}
                    toForm={(r) => ({
                        code: r.code,
                        daily_work_schedule: r.daily_work_schedule ?? '',
                        description: r.description ?? '',
                        type: r.type,
                        deducts_base_salary: r.deducts_base_salary ? '1' : '0',
                        start_time: r.start_time ?? '',
                        end_time: r.end_time ?? '',
                        break_start: r.break_start ?? '',
                        break_end: r.break_end ?? '',
                    })}
                    emptyForm={{ code: '', daily_work_schedule: '', description: '', type: 'work', deducts_base_salary: '0', start_time: '', end_time: '', break_start: '', break_end: '' }}
                    extraColumns={[
                        { label: 'Jam kerja', render: (r) => (r.work_hours !== null ? `${String(r.work_hours).replace('.', ',')} jam` : '-') },
                        {
                            label: 'Contoh',
                            render: (r) => <span className={`rounded px-2 py-0.5 text-xs font-black ${JENIS_CELL[r.type]}`}>{r.code}</span>,
                        },
                        { label: 'Dipakai', render: (r) => (r.usage ? `${r.usage.toLocaleString('id-ID')} hari` : '-') },
                    ]}
                    deleteBlockedReason={(r) =>
                        r.usage
                            ? `Masih dipakai ${r.usage} hari Roster/Absensi`
                            : r.patterns.length
                              ? `Masih dipakai Pola ${r.patterns.join(', ')}`
                              : null
                    }
                    describe={(r) => `Kode Shift ${r.code}`}
                />
            ),
        },
        {
            id: 'shift-patterns',
            label: 'Pola Roster',
            hint: 'Siklus berulang untuk mengisi Roster dengan cepat (tombol "Isi Pola" di mode Edit), mis. 10:2 = 10 hari D lalu 2 hari OFF.',
            render: () => <PatternList patterns={patterns} codes={codes} />,
        },
        {
            id: 'cash-status',
            label: 'Status Pengajuan Uang',
            hint: 'Pilihan status uang di Pengajuan Dinas. Urutan pertama menjadi status awal pengajuan baru. Mengganti nama ikut memperbarui pengajuan yang memakainya.',
            render: () => <OptionList category={cash_advance_status} usageNoun="submission" />,
        },
        {
            id: 'work-schedule',
            label: 'Waktu Kerja & Lembur',
            hint: 'Dasar perhitungan lembur (PP 35/2021). Hari kerja: kelebihan dari jam kerja normal lokasi = lembur (jam ke-1 × 1,5, berikutnya × 2). Hari OFF roster dan hari libur nasional: semua jam kerja = lembur (skema 5 hari: jam 1–8 × 2, jam 9 × 3, jam 10+ × 4; skema 6 hari / ESDM: jam 1–7 × 2, jam 8 × 3, jam 9+ × 4). Jam istirahat shift tidak dihitung.',
            render: () => <WaktuKerjaPanel location={work_schedule} scheme={scheme_options} policy={overtime_policy} eligibility={overtime_eligibility} />,
        },
        {
            id: 'public-holidays',
            label: 'Hari Libur',
            hint: 'Tanggal merah, ditandai di tabel Roster dan Absensi.',
            render: () => (
                <CrudTable
                    title="Hari Libur"
                    rows={public_holidays}
                    baseUrl={`${BASE}/public-holidays`}
                    fields={[
                        { key: 'date', label: 'Tanggal', type: 'date', width: 'w-48' },
                        { key: 'name', label: 'Nama Hari Libur', placeholder: 'mis. Hari Kemerdekaan' },
                    ]}
                    toForm={(r) => ({ date: r.date, name: r.name })}
                    emptyForm={{ date: '', name: '' }}
                    describe={(r) => `hari libur ${r.name}`}
                    emptyText="Belum ada hari libur."
                />
            ),
        },
    ];

    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'shift-codes');
    const active = sections.find((s) => s.id === activeId) ?? sections[0];
    const select = (id: string) =>
        guard(() => {
            setActiveId(id);
            window.history.replaceState(window.history.state, '', `${BASE}?tab=${id}`);
        });

    return (
        <>
            <Head title="Pengaturan Waktu & Kehadiran" />
            <AttendanceHeader title="Pengaturan Waktu & Kehadiran" />
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

// --- Pola Roster --------------------------------------------------------------------------

function PatternList({ patterns, codes }: { patterns: Pattern[]; codes: CodeRow[] }) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const codeById = new Map(codes.map((c) => [c.id, c]));

    const remove = (p: Pattern) => {
        if (confirm(`Hapus Pola Roster ${p.name}?`)) router.delete(`${BASE}/shift-patterns/${p.id}`, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="space-y-3">
            {patterns.map((p) =>
                editing === p.id ? (
                    <PatternEditor key={p.id} pattern={p} codes={codes} onDone={() => setEditing(null)} />
                ) : (
                    <div key={p.id} className="flex items-center gap-4 rounded-2xl border border-slate-200 px-4 py-3">
                        <div className="w-40 shrink-0">
                            <p className="font-bold text-slate-700">{p.name}</p>
                            <p className="text-[11px] text-slate-400">Siklus {p.steps.reduce((n, s) => n + s.days, 0)} hari</p>
                        </div>
                        <Strip steps={p.steps} codeById={codeById} />
                        <div className="ml-auto flex shrink-0 gap-1">
                            <button
                                onClick={() => setEditing(p.id)}
                                title="Ubah"
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                            >
                                <Pencil size={15} />
                            </button>
                            <button
                                onClick={() => remove(p)}
                                title="Hapus"
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                            >
                                <Trash2 size={15} />
                            </button>
                        </div>
                    </div>
                ),
            )}
            {patterns.length === 0 && editing !== 'new' && <p className="text-sm text-slate-400">Belum ada pola.</p>}
            {editing === 'new' ? (
                <PatternEditor codes={codes} onDone={() => setEditing(null)} />
            ) : (
                <button
                    onClick={() => setEditing('new')}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                >
                    <Plus size={14} />
                    Tambah Pola
                </button>
            )}
        </div>
    );
}

/** A pattern drawn as its days: D D D … OFF OFF (long runs shortened). */
function Strip({ steps, codeById }: { steps: PatternStep[]; codeById: Map<number, CodeRow> }) {
    return (
        <div className="flex min-w-0 flex-wrap items-center gap-1">
            {steps.map((s, i) => {
                const code = codeById.get(s.shift_code_id);
                return (
                    <span
                        key={i}
                        className={`rounded-md px-2 py-1 text-[11px] font-black ${code ? JENIS_CELL[code.type] : 'bg-slate-100 text-slate-400'}`}
                    >
                        {code?.code ?? '?'} × {s.days}
                    </span>
                );
            })}
        </div>
    );
}

function PatternEditor({ pattern, codes, onDone }: { pattern?: Pattern; codes: CodeRow[]; onDone: () => void }) {
    const [name, setNama] = useState(pattern?.name ?? '');
    const [steps, setSteps] = useState<PatternStep[]>(
        pattern?.steps ?? [
            { shift_code_id: codes.find((c) => c.type === 'work')?.id ?? codes[0]?.id ?? 0, days: 5 },
            { shift_code_id: codes.find((c) => c.type === 'off')?.id ?? codes[0]?.id ?? 0, days: 2 },
        ],
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const codeById = new Map(codes.map((c) => [c.id, c]));

    const update = (i: number, patch: Partial<PatternStep>) => setSteps((prev) => prev.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const move = (i: number, by: number) =>
        setSteps((prev) => {
            const next = [...prev];
            [next[i], next[i + by]] = [next[i + by], next[i]];
            return next;
        });

    const save = () => {
        setError(null);
        const data = { name, steps: steps } as never;
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onDone,
            onError: (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.'),
        };
        if (pattern) router.put(`${BASE}/shift-patterns/${pattern.id}`, data, options);
        else router.post(`${BASE}/shift-patterns`, data, options);
    };

    return (
        <div className="space-y-3 rounded-2xl border border-brand-200 bg-brand-50/40 p-4">
            <div>
                <label className="mb-1 block text-[11px] font-bold text-slate-500">Nama pola</label>
                <input value={name} onChange={(e) => setNama(e.target.value)} placeholder="mis. 10:2" className={`${smallInput} w-60`} autoFocus />
            </div>
            <div className="space-y-1.5">
                <label className="block text-[11px] font-bold text-slate-500">Urutan</label>
                {steps.map((s, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <span className="w-5 text-right text-xs text-slate-400">{i + 1}.</span>
                        <select
                            value={s.shift_code_id}
                            onChange={(e) => update(i, { shift_code_id: Number(e.target.value) })}
                            className={`${smallInput} w-56`}
                        >
                            {codes.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.code} — {c.description ?? c.type}
                                </option>
                            ))}
                        </select>
                        <input
                            type="number"
                            min={1}
                            max={60}
                            value={s.days}
                            onChange={(e) => update(i, { days: Math.max(1, Number(e.target.value) || 1) })}
                            className={`${smallInput} w-20`}
                        />
                        <span className="text-xs text-slate-500">hari</span>
                        <button
                            onClick={() => move(i, -1)}
                            disabled={i === 0}
                            title="Naik"
                            className="rounded p-1 text-slate-400 hover:bg-white disabled:opacity-30"
                        >
                            <ArrowUp size={14} />
                        </button>
                        <button
                            onClick={() => move(i, 1)}
                            disabled={i === steps.length - 1}
                            title="Turun"
                            className="rounded p-1 text-slate-400 hover:bg-white disabled:opacity-30"
                        >
                            <ArrowDown size={14} />
                        </button>
                        <button
                            onClick={() => setSteps((prev) => prev.filter((_, j) => j !== i))}
                            disabled={steps.length === 1}
                            title="Hapus langkah"
                            className="rounded p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-30"
                        >
                            <X size={14} />
                        </button>
                    </div>
                ))}
                <button
                    onClick={() => setSteps((prev) => [...prev, { shift_code_id: codes[0]?.id ?? 0, days: 1 }])}
                    className="ml-7 text-xs font-bold text-brand-600 hover:underline"
                >
                    + Tambah langkah
                </button>
            </div>
            <div className="flex items-center gap-2">
                <span className="text-[11px] text-slate-400">Siklus {steps.reduce((n, s) => n + s.days, 0)} hari:</span>
                <Strip steps={steps} codeById={codeById} />
            </div>
            {error && <p className="text-xs text-rose-500">{error}</p>}
            <div className="flex gap-2">
                <button
                    onClick={save}
                    disabled={busy}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    {busy ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />}
                    Simpan
                </button>
                <button onClick={onDone} className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-white">
                    Batal
                </button>
            </div>
        </div>
    );
}

// --- Waktu Kerja & Lembur -------------------------------------------------------------------

function WaktuKerjaPanel({ location, scheme, policy, eligibility }: { location: WaktuKerja[]; scheme: Record<string, string>; policy: OvertimePolicy; eligibility: HakLembur[] }) {
    const opts = { preserveScroll: true, preserveState: true };
    const [rules, setRules] = useState(policy);
    const [matrix, setMatrix] = useState(eligibility);
    const [busy, setBusy] = useState<string | null>(null);
    const done = { ...opts, onFinish: () => setBusy(null) };
    const card = 'rounded-2xl border border-slate-200 p-4';

    return (
        <div className="space-y-5">
            <div className={card}>
                <h3 className="mb-1 text-sm font-bold text-slate-700">Skema waktu kerja per lokasi</h3>
                <p className="mb-3 text-[11px] text-slate-400">
                    ESDM = waktu kerja tertentu sektor energi & sumber daya mineral (Kepmenakertrans 234/2003): hari kerja mengikuti roster, hari OFF roster
                    menjadi hari istirahat.
                </p>
                <div className="space-y-2">
                    {location.map((l) => (
                        <WaktuKerjaRow key={l.id} location={l} scheme={scheme} />
                    ))}
                </div>
            </div>

            <div className={card}>
                <h3 className="mb-3 text-sm font-bold text-slate-700">Aturan perhitungan lembur</h3>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <label className="text-xs text-slate-500">
                        Pembulatan
                        <select
                            value={rules.rounding_minutes}
                            onChange={(e) => setRules({ ...rules, rounding_minutes: Number(e.target.value) })}
                            className={`${smallInput} mt-1 w-full`}
                        >
                            {[1, 15, 30, 60].map((m) => (
                                <option key={m} value={m}>
                                    {m === 1 ? 'Per menit' : `Ke bawah per ${m} menit`}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs text-slate-500">
                        Minimal lembur (menit)
                        <input type="number" min={0} max={240} value={rules.minimum_minutes} onChange={(e) => setRules({ ...rules, minimum_minutes: Number(e.target.value) })} className={`${smallInput} mt-1 w-full`} />
                    </label>
                    <label className="text-xs text-slate-500">
                        Batas wajar / hari (jam)
                        <input type="number" min={1} max={12} value={rules.daily_limit_hours} onChange={(e) => setRules({ ...rules, daily_limit_hours: Number(e.target.value) })} className={`${smallInput} mt-1 w-full`} />
                    </label>
                    <label className="text-xs text-slate-500">
                        Batas wajar / minggu (jam)
                        <input type="number" min={1} max={60} value={rules.weekly_limit_hours} onChange={(e) => setRules({ ...rules, weekly_limit_hours: Number(e.target.value) })} className={`${smallInput} mt-1 w-full`} />
                    </label>
                    <label className="flex items-end gap-2 pb-2 text-xs text-slate-600">
                        <input type="checkbox" checked={rules.count_before_shift} onChange={(e) => setRules({ ...rules, count_before_shift: e.target.checked })} />
                        Hitung kerja sebelum jam masuk
                    </label>
                </div>
                <p className="mt-2 text-[11px] text-slate-400">Batas wajar PP 35/2021: maksimal 4 jam/hari dan 18 jam/minggu; lembur di atasnya diberi tanda di Kehadiran.</p>
                <button
                    onClick={() => router.put(`${BASE}/overtime-policy`, rules as never, { ...done, onStart: () => setBusy('policy') })}
                    disabled={busy === 'policy'}
                    className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    {busy === 'policy' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Simpan aturan
                </button>
            </div>

            <div className={card}>
                <h3 className="mb-1 text-sm font-bold text-slate-700">Hak lembur per SubGroup</h3>
                <p className="mb-3 text-[11px] text-slate-400">Tanpa centang = tidak mendapat upah lembur di lokasi jenis itu (jam kerjanya tetap tercatat di Kehadiran).</p>
                <table className="w-full max-w-xl text-sm">
                    <thead>
                        <tr className="text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <th className="py-1.5">SubGroup</th>
                            <th className="py-1.5 text-center">Head Office</th>
                            <th className="py-1.5 text-center">Site</th>
                        </tr>
                    </thead>
                    <tbody>
                        {matrix.map((g, i) => (
                            <tr key={g.id} className="border-t border-slate-100">
                                <td className="py-1.5 text-slate-700">
                                    {g.name} <span className="text-[10px] text-slate-400">layer {g.layer}</span>
                                </td>
                                {(['ho', 'site'] as const).map((k) => (
                                    <td key={k} className="py-1.5 text-center">
                                        <input
                                            type="checkbox"
                                            checked={g[k]}
                                            onChange={(e) => setMatrix((prev) => prev.map((r, j) => (j === i ? { ...r, [k]: e.target.checked } : r)))}
                                        />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                <button
                    onClick={() => router.put(`${BASE}/overtime-eligibility`, { rows: matrix } as never, { ...done, onStart: () => setBusy('eligibility') })}
                    disabled={busy === 'eligibility'}
                    className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                >
                    {busy === 'eligibility' ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Simpan hak lembur
                </button>
            </div>
        </div>
    );
}

function WaktuKerjaRow({ location, scheme }: { location: WaktuKerja; scheme: Record<string, string> }) {
    const [form, setForm] = useState({ work_schedule_scheme: location.work_schedule_scheme, daily_normal_hours: location.daily_normal_hours });
    const [busy, setBusy] = useState(false);
    const dirty = form.work_schedule_scheme !== location.work_schedule_scheme || Number(form.daily_normal_hours) !== Number(location.daily_normal_hours);
    const pick = (value: string) => setForm({ work_schedule_scheme: value, daily_normal_hours: value === 'five_day' ? 8 : 7 });

    return (
        <div className="flex flex-wrap items-center gap-3">
            <span className="w-44 text-sm font-bold text-slate-700">
                {location.name} <span className="block text-[10px] font-normal text-slate-400">{location.type === 'head_office' ? 'Head Office' : 'Site'}</span>
            </span>
            <select value={form.work_schedule_scheme} onChange={(e) => pick(e.target.value)} className={`${smallInput} w-96`}>
                {Object.entries(scheme).map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </select>
            <label className="flex items-center gap-1.5 text-xs text-slate-500">
                Jam normal/hari
                <input
                    type="number"
                    step="0.5"
                    min={4}
                    max={12}
                    value={form.daily_normal_hours}
                    onChange={(e) => setForm({ ...form, daily_normal_hours: Number(e.target.value) })}
                    className={`${smallInput} w-20`}
                />
            </label>
            {dirty && (
                <button
                    onClick={() =>
                        router.put(`${BASE}/work-schedule/${location.id}`, form as never, {
                            preserveScroll: true,
                            preserveState: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        })
                    }
                    className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                >
                    {busy ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />} Simpan
                </button>
            )}
        </div>
    );
}
