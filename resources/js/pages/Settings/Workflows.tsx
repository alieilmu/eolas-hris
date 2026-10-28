import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, CheckCircle2, GripVertical, History, Loader2, Play, Plus, RotateCcw, Save, Trash2, X } from 'lucide-react';
import { AttendanceHeader, ghostButton, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { postJson } from '@/lib/http';

type ApproverType = 'superior' | 'position' | 'menu';
type Op = '>' | '>=' | '<' | '<=' | 'in' | 'not_in';

type Rule = {
    field: string;
    op: Op;
    value: number | string | string[];
};

type Step = {
    key: number;
    name: string;
    approver_type: ApproverType;
    approver_value: string | null;
    mode: 'any' | 'all';
    due_days: number | null;
    conditions: Rule[];
};

interface Version {
    id: number;
    version: number;
    name: string;
    is_active: boolean;
    created_at: string | null;
    created_by: string | null;
    pending: number;
    steps: (Omit<Step, 'key'> & { approver: string; condition: string | null })[];
}

interface Field {
    key: string;
    label: string;
    kind: 'number' | 'list';
    options: { value: string; label: string }[];
}

type Option = { value: string; label: string };

interface Props {
    type: string;
    types: { value: string; label: string; version: number | null; steps: number }[];
    versions: Version[];
    fields: Field[];
    approver_types: Option[];
    positions: Option[];
    menus: Option[];
    hr_menu: string;
    employees: { value: number; label: string }[];
}

interface SimStep {
    sequence: number;
    name: string;
    applies: boolean;
    condition: string | null;
    approvers: string[];
    fallback: 'up' | 'hr' | 'admin' | null;
    note: string | null;
}

interface Coverage {
    sequence: number;
    name: string;
    hr: number;
    up: number;
    examples: string[];
    reasons: string[];
}

const NUMBER_OPS: Option[] = [
    { value: '>', label: 'lebih dari' },
    { value: '>=', label: 'minimal' },
    { value: '<', label: 'kurang dari' },
    { value: '<=', label: 'maksimal' },
];
const LIST_OPS: Option[] = [
    { value: 'in', label: 'salah satu dari' },
    { value: 'not_in', label: 'bukan' },
];
const LEVELS = [1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: n === 1 ? 'Atasan langsung' : `Atasan ${n} tingkat di atas` }));
const UNIT: Record<string, string> = { amount: 'Rp', days: 'hari', hours: 'jam' };

let seq = 0;
const keyed = (s: Omit<Step, 'key'>): Step => ({ ...s, key: ++seq, conditions: s.conditions ?? [] });

/** Pengaturan → Alur Persetujuan: HR builds, tries and saves the approval flow of each kind of pengajuan. */
export default function Workflows({ type, types, versions, fields, approver_types, positions, menus, hr_menu, employees }: Props) {
    const active = versions.find((v) => v.is_active) ?? versions[0];
    const load = (v: Version) =>
        v.steps.map((s) =>
            keyed({ name: s.name, approver_type: s.approver_type, approver_value: s.approver_value, mode: s.mode, due_days: s.due_days, conditions: s.conditions ?? [] }),
        );
    const [steps, setSteps] = useState<Step[]>(() => (active ? load(active) : []));
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const [dragging, setDragging] = useState<number | null>(null);
    const [coverage, setCoverage] = useState<Coverage[] | null>(null);
    const [checking, setChecking] = useState(false);
    const fieldOf = useMemo(() => Object.fromEntries(fields.map((f) => [f.key, f])), [fields]);
    const label = types.find((t) => t.value === type)?.label ?? type;
    const pendingOld = versions.filter((v) => !v.is_active).reduce((n, v) => n + v.pending, 0);
    const dirty = JSON.stringify(steps.map(({ key, ...s }) => s)) !== JSON.stringify(active ? load(active).map(({ key, ...s }) => s) : []);

    const payload = () => ({ steps: steps.map(({ key, ...s }) => s) });
    const flatten = (e: Record<string, string>) => [...new Set(Object.values(e))];
    const update = (key: number, patch: Partial<Step>) => (setSteps((all) => all.map((s) => (s.key === key ? { ...s, ...patch } : s))), setCoverage(null));
    const move = (from: number, to: number) =>
        setSteps((all) => {
            if (to < 0 || to >= all.length) return all;
            const next = [...all];
            const [it] = next.splice(from, 1);
            next.splice(to, 0, it);
            return next;
        });

    const add = (kind: ApproverType) =>
        setSteps((all) => [
            ...all,
            keyed({
                name: kind === 'superior' ? 'Atasan langsung' : kind === 'menu' ? 'Verifikasi HR' : 'Persetujuan jabatan',
                approver_type: kind,
                approver_value: kind === 'superior' ? '1' : kind === 'menu' ? hr_menu : null,
                mode: 'any',
                due_days: null,
                conditions: [],
            }),
        ]);

    const save = () => {
        if (!confirm(`Simpan sebagai versi baru alur ${label}? Pengajuan baru memakai alur ini; yang sedang berjalan tetap di versi lamanya.`)) return;
        setSaving(true);
        router.post(`/settings/workflows/${type}`, payload(), {
            preserveScroll: true,
            onError: (e) => setErrors(flatten(e)),
            onSuccess: () => setErrors([]),
            onFinish: () => setSaving(false),
        });
    };

    const check = async () => {
        setChecking(true);
        try {
            const r = await postJson<{ steps: Coverage[] }>(`/settings/workflows/${type}/coverage`, payload());
            setCoverage(r.steps);
            setErrors([]);
        } catch (e) {
            setErrors([(e as Error).message]);
        } finally {
            setChecking(false);
        }
    };

    return (
        <>
            <Head title="Alur Persetujuan" />
            <AttendanceHeader title="Alur Persetujuan" subtitle="Siapa menyetujui setiap jenis pengajuan, dalam urutan apa, dan kapan sebuah langkah berlaku" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden lg:flex-row">
                <aside className="shrink-0 border-b border-slate-200 bg-white p-3 lg:w-60 lg:border-r lg:border-b-0">
                    <nav className="flex gap-1 overflow-x-auto lg:flex-col">
                        {types.map((t) => (
                            <Link
                                key={t.value}
                                href={`/settings/workflows?type=${t.value}`}
                                className={`shrink-0 rounded-xl px-3 py-2 text-sm ${t.value === type ? 'bg-brand-50 font-bold text-brand-700' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {t.label}
                                <span className="block text-[11px] font-normal text-slate-400">
                                    {t.steps} langkah{t.version ? ` · v${t.version}` : ''}
                                </span>
                            </Link>
                        ))}
                    </nav>
                </aside>

                <main className="min-w-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                    <div className="mx-auto grid max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                        <div className="min-w-0 space-y-4">
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-lg font-black text-slate-800">Alur {label}</h2>
                                    {active && (
                                        <p className="text-xs text-slate-500">
                                            Versi aktif v{active.version}
                                            {active.created_by ? ` · diubah ${active.created_by}` : ' · bawaan'}
                                            {active.pending > 0 && ` · ${active.pending} pengajuan berjalan`}
                                            {pendingOld > 0 && ` · ${pendingOld} pengajuan masih di versi lama`}
                                        </p>
                                    )}
                                </div>
                                {dirty && (
                                    <button onClick={() => active && (setSteps(load(active)), setErrors([]), setCoverage(null))} className={ghostButton}>
                                        <RotateCcw size={14} className="mr-1 inline" /> Batalkan perubahan
                                    </button>
                                )}
                                <button onClick={save} disabled={saving || !dirty} className={primaryButton}>
                                    {saving ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Simpan versi baru
                                </button>
                            </div>

                            {errors.length > 0 && (
                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                    {errors.map((e) => (
                                        <p key={e}>{e}</p>
                                    ))}
                                </div>
                            )}

                            <ol className="space-y-3">
                                {steps.map((s, i) => (
                                    <li
                                        key={s.key}
                                        draggable
                                        onDragStart={() => setDragging(i)}
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={() => (dragging !== null && move(dragging, i), setDragging(null))}
                                        className={`rounded-3xl border bg-white p-4 shadow-sm ${dragging === i ? 'border-brand-300 opacity-60' : 'border-slate-200'}`}
                                    >
                                        <StepEditor
                                            index={i}
                                            count={steps.length}
                                            step={s}
                                            fields={fields}
                                            fieldOf={fieldOf}
                                            approverTypes={approver_types}
                                            positions={positions}
                                            menus={menus}
                                            hrMenu={hr_menu}
                                            onChange={(patch) => update(s.key, patch)}
                                            onMove={(d) => move(i, i + d)}
                                            onRemove={() => setSteps((all) => all.filter((x) => x.key !== s.key))}
                                        />
                                    </li>
                                ))}
                            </ol>

                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-xs font-bold text-slate-500">Tambah langkah:</span>
                                <button onClick={() => add('superior')} className={secondaryButton}>
                                    <Plus size={14} /> Atasan
                                </button>
                                <button onClick={() => add('position')} className={secondaryButton}>
                                    <Plus size={14} /> Jabatan tertentu
                                </button>
                                <button onClick={() => add('menu')} className={secondaryButton}>
                                    <Plus size={14} /> HR
                                </button>
                                <button
                                    onClick={() =>
                                        confirm('Ganti isi editor dengan alur bawaan (1 langkah HR)?') &&
                                        setSteps([keyed({ name: 'Verifikasi HR', approver_type: 'menu', approver_value: hr_menu, mode: 'any', due_days: null, conditions: [] })])
                                    }
                                    className={`${ghostButton} ml-auto`}
                                >
                                    Alur bawaan
                                </button>
                            </div>

                            <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="flex-1 text-sm font-bold text-slate-700">Cakupan penyetuju</h3>
                                    <button onClick={check} disabled={checking} className={secondaryButton}>
                                        {checking ? <Loader2 size={14} className="animate-spin" /> : <CheckCircle2 size={14} />} Periksa semua karyawan
                                    </button>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    Mencari karyawan aktif yang langkah atasan/jabatannya tidak menemukan penyetuju di struktur organisasi (pengajuan mereka jatuh ke HR).
                                </p>
                                {coverage && coverage.length === 0 && <p className="mt-3 text-sm text-slate-500">Semua langkah alur ini memakai HR; tidak ada yang perlu diperiksa.</p>}
                                {coverage?.map((c) => (
                                    <div key={c.sequence} className={`mt-3 rounded-2xl px-3 py-2 text-sm ${c.hr ? 'bg-amber-50 text-amber-800' : 'bg-brand-50 text-brand-800'}`}>
                                        <p className="font-bold">
                                            {c.hr ? <AlertTriangle size={14} className="mr-1 inline" /> : <CheckCircle2 size={14} className="mr-1 inline" />}
                                            Langkah {c.sequence} · {c.name}:{' '}
                                            {c.hr ? `${c.hr} karyawan tidak menemukan penyetuju → jatuh ke HR` : 'semua karyawan menemukan penyetuju'}
                                            {c.up > 0 && ` · ${c.up} diteruskan ke jabatan lebih atas karena jabatan atasannya kosong`}
                                        </p>
                                        {c.reasons.map((r) => (
                                            <p key={r} className="text-xs">
                                                • {r}
                                            </p>
                                        ))}
                                        {c.examples.length > 0 && <p className="mt-1 text-xs opacity-80">Contoh: {c.examples.join(', ')}</p>}
                                    </div>
                                ))}
                            </section>

                            <Versions versions={versions} onLoad={(v) => (setSteps(load(v)), setCoverage(null))} />
                        </div>

                        <Simulation type={type} fields={fields} employees={employees} payload={payload} onError={(e) => setErrors([e])} />
                    </div>
                </main>
            </div>
        </>
    );
}

function StepEditor({
    index,
    count,
    step: s,
    fields,
    fieldOf,
    approverTypes,
    positions,
    menus,
    hrMenu,
    onChange,
    onMove,
    onRemove,
}: {
    index: number;
    count: number;
    step: Step;
    fields: Field[];
    fieldOf: Record<string, Field>;
    approverTypes: Option[];
    positions: Option[];
    menus: Option[];
    hrMenu: string;
    onChange: (patch: Partial<Step>) => void;
    onMove: (delta: number) => void;
    onRemove: () => void;
}) {
    const [positionQuery, setPositionQuery] = useState('');
    const shownPositions = positions.filter((p) => p.value === s.approver_value || p.label.toLowerCase().includes(positionQuery.toLowerCase())).slice(0, 200);
    const setRule = (j: number, patch: Partial<Rule>) => onChange({ conditions: s.conditions.map((r, k) => (k === j ? { ...r, ...patch } : r)) });

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2">
                <GripVertical size={16} className="shrink-0 cursor-grab text-slate-300" />
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-black text-white">{index + 1}</span>
                <input value={s.name} onChange={(e) => onChange({ name: e.target.value })} maxLength={120} className={`${smallInput} min-w-0 flex-1 font-bold`} aria-label="Nama langkah" />
                <button onClick={() => onMove(-1)} disabled={index === 0} aria-label="Naik" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30">
                    <ArrowUp size={15} />
                </button>
                <button onClick={() => onMove(1)} disabled={index === count - 1} aria-label="Turun" className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 disabled:opacity-30">
                    <ArrowDown size={15} />
                </button>
                <button onClick={onRemove} disabled={count === 1} aria-label="Hapus langkah" className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-30">
                    <Trash2 size={15} />
                </button>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <label className="block text-xs font-bold text-slate-500">
                    Penyetuju
                    <select
                        value={s.approver_type}
                        onChange={(e) => {
                            const t = e.target.value as ApproverType;
                            onChange({ approver_type: t, approver_value: t === 'superior' ? '1' : t === 'menu' ? hrMenu : null });
                        }}
                        className={`${smallInput} mt-1 w-full`}
                    >
                        {approverTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block text-xs font-bold text-slate-500">
                    {s.approver_type === 'superior' ? 'Tingkat' : s.approver_type === 'position' ? 'Jabatan' : 'Menu HR (akses "ubah" di lokasi karyawan)'}
                    {s.approver_type === 'superior' && (
                        <select value={s.approver_value ?? '1'} onChange={(e) => onChange({ approver_value: e.target.value })} className={`${smallInput} mt-1 w-full`}>
                            {LEVELS.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </select>
                    )}
                    {s.approver_type === 'position' && (
                        <>
                            <input value={positionQuery} onChange={(e) => setPositionQuery(e.target.value)} placeholder="Cari jabatan…" className={`${smallInput} mt-1 w-full`} />
                            <select value={s.approver_value ?? ''} onChange={(e) => onChange({ approver_value: e.target.value || null })} className={`${smallInput} mt-1 w-full`}>
                                <option value="">Pilih jabatan</option>
                                {shownPositions.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </>
                    )}
                    {s.approver_type === 'menu' && (
                        <select value={s.approver_value ?? hrMenu} onChange={(e) => onChange({ approver_value: e.target.value })} className={`${smallInput} mt-1 w-full`}>
                            {menus.map((m) => (
                                <option key={m.value} value={m.value}>
                                    {m.label}
                                </option>
                            ))}
                        </select>
                    )}
                </label>
                <fieldset className="text-xs font-bold text-slate-500">
                    Bila penyetujunya lebih dari satu orang
                    <div className="mt-1.5 flex flex-wrap gap-3 font-normal text-slate-600">
                        <label className="flex items-center gap-1.5">
                            <input type="radio" checked={s.mode === 'any'} onChange={() => onChange({ mode: 'any' })} /> Salah satu cukup
                        </label>
                        <label className="flex items-center gap-1.5">
                            <input type="radio" checked={s.mode === 'all'} onChange={() => onChange({ mode: 'all' })} /> Semua harus setuju
                        </label>
                    </div>
                </fieldset>
                <label className="block text-xs font-bold text-slate-500">
                    Batas waktu (hari kerja)
                    <input
                        type="number"
                        min={1}
                        max={30}
                        value={s.due_days ?? ''}
                        onChange={(e) => onChange({ due_days: e.target.value ? Number(e.target.value) : null })}
                        placeholder="Tanpa batas"
                        className={`${smallInput} mt-1 w-full`}
                    />
                    <span className="mt-0.5 block font-normal text-slate-400">Lewat batas: diingatkan, sehari kerja kemudian naik ke atasan penyetuju.</span>
                </label>
            </div>

            <div className="rounded-2xl bg-slate-50 p-3">
                <p className="text-xs font-bold text-slate-500">
                    Berlaku {s.conditions.length ? 'bila semua syarat terpenuhi' : 'untuk semua pengajuan'}
                </p>
                {s.conditions.map((r, j) => {
                    const f = fieldOf[r.field];
                    return (
                        <div key={j} className="mt-2 flex flex-wrap items-start gap-2">
                            <select
                                value={r.field}
                                onChange={(e) => {
                                    const nf = fieldOf[e.target.value];
                                    setRule(j, { field: e.target.value, op: nf.kind === 'number' ? '>' : 'in', value: nf.kind === 'number' ? 0 : [] });
                                }}
                                className={smallInput}
                            >
                                {fields.map((x) => (
                                    <option key={x.key} value={x.key}>
                                        {x.label}
                                    </option>
                                ))}
                            </select>
                            <select value={r.op} onChange={(e) => setRule(j, { op: e.target.value as Op })} className={smallInput}>
                                {(f?.kind === 'number' ? NUMBER_OPS : LIST_OPS).map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                            {f?.kind === 'number' ? (
                                <span className="flex items-center gap-1.5 text-sm text-slate-500">
                                    {r.field === 'amount' && 'Rp'}
                                    <input
                                        type="number"
                                        min={0}
                                        value={String(r.value)}
                                        onChange={(e) => setRule(j, { value: Number(e.target.value) })}
                                        className={`${smallInput} w-32`}
                                    />
                                    {r.field !== 'amount' && UNIT[r.field]}
                                </span>
                            ) : (
                                <div className="flex max-w-md flex-wrap gap-1.5">
                                    {f?.options.map((o) => {
                                        const list = Array.isArray(r.value) ? r.value : [];
                                        const on = list.includes(o.value);
                                        return (
                                            <button
                                                key={o.value}
                                                onClick={() => setRule(j, { value: on ? list.filter((v) => v !== o.value) : [...list, o.value] })}
                                                className={`rounded-full px-2.5 py-1 text-xs font-bold ${on ? 'bg-brand-600 text-white' : 'bg-white text-slate-500 ring-1 ring-slate-200'}`}
                                            >
                                                {o.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                            <button
                                onClick={() => onChange({ conditions: s.conditions.filter((_, k) => k !== j) })}
                                aria-label="Hapus syarat"
                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                            >
                                <X size={14} />
                            </button>
                        </div>
                    );
                })}
                {fields.length > 0 && (
                    <button
                        onClick={() =>
                            onChange({ conditions: [...s.conditions, { field: fields[0].key, op: fields[0].kind === 'number' ? '>' : 'in', value: fields[0].kind === 'number' ? 0 : [] }] })
                        }
                        className="mt-2 text-xs font-bold text-brand-700 hover:underline"
                    >
                        + Tambah syarat
                    </button>
                )}
            </div>
        </div>
    );
}

function Simulation({
    type,
    fields,
    employees,
    payload,
    onError,
}: {
    type: string;
    fields: Field[];
    employees: { value: number; label: string }[];
    payload: () => { steps: unknown[] };
    onError: (e: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [employee, setEmployee] = useState<{ value: number; label: string } | null>(null);
    const [facts, setFacts] = useState<Record<string, string>>({});
    const [result, setResult] = useState<SimStep[] | null>(null);
    const [busy, setBusy] = useState(false);
    const own = fields.filter((f) => f.key !== 'work_location' && f.key !== 'job_level');
    const matches = query && !employee ? employees.filter((e) => e.label.toLowerCase().includes(query.toLowerCase())).slice(0, 30) : [];

    const run = async () => {
        if (!employee) return;
        setBusy(true);
        try {
            const r = await postJson<{ steps: SimStep[] }>(`/settings/workflows/${type}/simulate`, { ...payload(), employee_id: employee.value, facts });
            setResult(r.steps);
        } catch (e) {
            onError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <aside className="h-fit space-y-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-0">
            <h3 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                <Play size={15} /> Simulasi
            </h3>
            <p className="text-xs text-slate-500">Coba alur di editor (belum perlu disimpan) untuk satu karyawan dan contoh pengajuan.</p>
            <div>
                <input
                    value={query}
                    onChange={(e) => (setQuery(e.target.value), setEmployee(null), setResult(null))}
                    placeholder="Cari karyawan…"
                    className={`${smallInput} w-full`}
                />
                {matches.length > 0 && (
                    <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200">
                        {matches.map((e) => (
                            <button key={e.value} onClick={() => (setEmployee(e), setQuery(e.label))} className="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50">
                                {e.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
            {own.map((f) => (
                <label key={f.key} className="block text-xs font-bold text-slate-500">
                    {f.label}
                    {f.kind === 'number' ? (
                        <input
                            type="number"
                            min={0}
                            value={facts[f.key] ?? ''}
                            onChange={(e) => setFacts({ ...facts, [f.key]: e.target.value })}
                            className={`${smallInput} mt-1 w-full`}
                            placeholder={UNIT[f.key]}
                        />
                    ) : (
                        <select value={facts[f.key] ?? ''} onChange={(e) => setFacts({ ...facts, [f.key]: e.target.value })} className={`${smallInput} mt-1 w-full`}>
                            <option value="">-</option>
                            {f.options.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    )}
                </label>
            ))}
            <button onClick={run} disabled={!employee || busy} className={`${primaryButton} w-full justify-center`}>
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Play size={15} />} Jalankan simulasi
            </button>
            {result && (
                <ol className="space-y-2">
                    {result.map((r) => (
                        <li key={r.sequence} className={`rounded-2xl px-3 py-2 text-sm ${r.applies ? 'bg-slate-50' : 'bg-slate-50 opacity-50'}`}>
                            <p className="font-bold text-slate-700">
                                {r.sequence}. {r.name}
                            </p>
                            {!r.applies ? (
                                <p className="text-xs text-slate-500">Dilewati: syarat tidak terpenuhi{r.condition ? ` (${r.condition})` : ''}.</p>
                            ) : (
                                <>
                                    <p className="text-xs text-slate-600">{r.approvers.length ? r.approvers.join(', ') : 'Tidak ada penyetuju'}</p>
                                    {r.fallback && (
                                        <p className={`mt-0.5 text-xs ${r.fallback === 'up' ? 'text-slate-500' : 'font-semibold text-amber-700'}`}>
                                            {r.fallback === 'hr' ? 'Jatuh ke HR: ' : r.fallback === 'admin' ? 'Jatuh ke Super Admin: ' : ''}
                                            {r.note}
                                        </p>
                                    )}
                                </>
                            )}
                        </li>
                    ))}
                    {result.every((r) => !r.applies) && <p className="text-xs text-slate-500">Tidak ada langkah yang berlaku: pengajuan langsung disetujui.</p>}
                </ol>
            )}
        </aside>
    );
}

function Versions({ versions, onLoad }: { versions: Version[]; onLoad: (v: Version) => void }) {
    const [open, setOpen] = useState(false);
    if (versions.length < 2) return null;
    return (
        <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
            <button onClick={() => setOpen(!open)} className="flex w-full items-center gap-2 text-sm font-bold text-slate-700">
                <History size={15} /> Riwayat versi ({versions.length})
            </button>
            {open && (
                <ul className="mt-3 divide-y divide-slate-100">
                    {versions.map((v) => (
                        <li key={v.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                            <span className="font-bold text-slate-700">v{v.version}</span>
                            {v.is_active && <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-700">aktif</span>}
                            <span className="text-xs text-slate-500">
                                {v.steps.map((s) => s.name).join(' → ')}
                                {v.created_by ? ` · ${v.created_by}` : ''}
                                {v.created_at ? ` · ${new Date(v.created_at).toLocaleDateString('id-ID')}` : ''}
                                {v.pending ? ` · ${v.pending} pengajuan berjalan` : ''}
                            </span>
                            {!v.is_active && (
                                <button onClick={() => onLoad(v)} className="ml-auto text-xs font-bold text-brand-700 hover:underline">
                                    Muat ke editor
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
