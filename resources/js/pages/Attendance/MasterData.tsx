import { Fragment, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { CrudTable } from '@/components/masterdata/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/masterdata/DirtyGuard';
import { OptionList, type OptionCategory } from '@/components/masterdata/OptionList';
import { AttendanceHeader, smallInput } from '@/components/attendance/ui';
import { JENIS_CELL, type Jenis, type Pattern, type PatternStep } from '@/lib/attendance';

interface CodeRow {
    id: number;
    kode: string;
    dws: string | null;
    keterangan: string | null;
    jenis: Jenis;
    usage: number;
    patterns: string[];
}

interface Props {
    codes: CodeRow[];
    jenis_options: Record<Jenis, string>;
    patterns: Pattern[];
    status_uang: OptionCategory;
    holidays: { id: number; tanggal: string; nama: string }[];
}

const BASE = '/attendance/master-data';

export default function AttendanceMasterData(props: Props) {
    return (
        <DirtyGuardProvider>
            <Page {...props} />
        </DirtyGuardProvider>
    );
}

function Page({ codes, jenis_options, patterns, status_uang, holidays }: Props) {
    const guard = useGuard();
    const sections: { id: string; label: string; hint: string; render: () => ReactNode }[] = [
        {
            id: 'kode-shift',
            label: 'Kode Shift',
            hint: 'Kode Shift dipakai di Roster dan Absensi. DWS adalah terjemahannya untuk export (pilih "DWS" saat export). Jenis menentukan cara hitung % Kehadiran dan Man Onsite: Hadir = kerja di lokasi (onsite); Trip & Leave memakai kode pertama berjenis Dinas / Cuti.',
            render: () => (
                <CrudTable
                    title="Kode Shift"
                    rows={codes}
                    baseUrl={`${BASE}/kode-shift`}
                    fields={[
                        { key: 'kode', label: 'Kode Shift', placeholder: 'mis. D', width: 'w-28' },
                        { key: 'dws', label: 'DWS', placeholder: 'mis. DS07', width: 'w-36' },
                        { key: 'keterangan', label: 'Keterangan', placeholder: 'mis. Day shift 07.00–19.00' },
                        {
                            key: 'jenis',
                            label: 'Jenis',
                            type: 'select',
                            width: 'w-52',
                            options: Object.entries(jenis_options).map(([value, label]) => ({ value, label })),
                        },
                    ]}
                    toForm={(r) => ({ kode: r.kode, dws: r.dws ?? '', keterangan: r.keterangan ?? '', jenis: r.jenis })}
                    emptyForm={{ kode: '', dws: '', keterangan: '', jenis: 'hadir' }}
                    extraColumns={[
                        {
                            label: 'Contoh',
                            render: (r) => <span className={`rounded px-2 py-0.5 text-xs font-black ${JENIS_CELL[r.jenis]}`}>{r.kode}</span>,
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
                    describe={(r) => `Kode Shift ${r.kode}`}
                />
            ),
        },
        {
            id: 'pola-roster',
            label: 'Pola Roster',
            hint: 'Siklus berulang untuk mengisi Roster dengan cepat (tombol "Isi Pola" di mode Edit), mis. 10:2 = 10 hari D lalu 2 hari OFF.',
            render: () => <PatternList patterns={patterns} codes={codes} />,
        },
        {
            id: 'status-uang',
            label: 'Status Pengajuan Uang',
            hint: 'Pilihan status uang di Pengajuan Dinas. Urutan pertama menjadi status awal pengajuan baru. Mengganti nama ikut memperbarui pengajuan yang memakainya.',
            render: () => <OptionList category={status_uang} usageNoun="pengajuan" />,
        },
        {
            id: 'hari-libur',
            label: 'Hari Libur',
            hint: 'Tanggal merah, ditandai di tabel Roster dan Absensi.',
            render: () => (
                <CrudTable
                    title="Hari Libur"
                    rows={holidays}
                    baseUrl={`${BASE}/hari-libur`}
                    fields={[
                        { key: 'tanggal', label: 'Tanggal', type: 'date', width: 'w-48' },
                        { key: 'nama', label: 'Nama Hari Libur', placeholder: 'mis. Hari Kemerdekaan' },
                    ]}
                    toForm={(r) => ({ tanggal: r.tanggal, nama: r.nama })}
                    emptyForm={{ tanggal: '', nama: '' }}
                    describe={(r) => `hari libur ${r.nama}`}
                    emptyText="Belum ada hari libur."
                />
            ),
        },
    ];

    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'kode-shift');
    const active = sections.find((s) => s.id === activeId) ?? sections[0];
    const select = (id: string) =>
        guard(() => {
            setActiveId(id);
            window.history.replaceState(window.history.state, '', `${BASE}?tab=${id}`);
        });

    return (
        <>
            <Head title="Master Data Attendance" />
            <AttendanceHeader title="Master Data Attendance" />
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
        if (confirm(`Hapus Pola Roster ${p.nama}?`)) router.delete(`${BASE}/pola-roster/${p.id}`, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="space-y-3">
            {patterns.map((p) =>
                editing === p.id ? (
                    <PatternEditor key={p.id} pattern={p} codes={codes} onDone={() => setEditing(null)} />
                ) : (
                    <div key={p.id} className="flex items-center gap-4 rounded-2xl border border-slate-200 px-4 py-3">
                        <div className="w-40 shrink-0">
                            <p className="font-bold text-slate-700">{p.nama}</p>
                            <p className="text-[11px] text-slate-400">Siklus {p.langkah.reduce((n, s) => n + s.hari, 0)} hari</p>
                        </div>
                        <Strip steps={p.langkah} codeById={codeById} />
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
                        className={`rounded-md px-2 py-1 text-[11px] font-black ${code ? JENIS_CELL[code.jenis] : 'bg-slate-100 text-slate-400'}`}
                    >
                        {code?.kode ?? '?'} × {s.hari}
                    </span>
                );
            })}
        </div>
    );
}

function PatternEditor({ pattern, codes, onDone }: { pattern?: Pattern; codes: CodeRow[]; onDone: () => void }) {
    const [nama, setNama] = useState(pattern?.nama ?? '');
    const [steps, setSteps] = useState<PatternStep[]>(
        pattern?.langkah ?? [
            { shift_code_id: codes.find((c) => c.jenis === 'hadir')?.id ?? codes[0]?.id ?? 0, hari: 5 },
            { shift_code_id: codes.find((c) => c.jenis === 'off')?.id ?? codes[0]?.id ?? 0, hari: 2 },
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
        const data = { nama, langkah: steps } as never;
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onDone,
            onError: (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.'),
        };
        if (pattern) router.put(`${BASE}/pola-roster/${pattern.id}`, data, options);
        else router.post(`${BASE}/pola-roster`, data, options);
    };

    return (
        <div className="space-y-3 rounded-2xl border border-brand-200 bg-brand-50/40 p-4">
            <div>
                <label className="mb-1 block text-[11px] font-bold text-slate-500">Nama pola</label>
                <input value={nama} onChange={(e) => setNama(e.target.value)} placeholder="mis. 10:2" className={`${smallInput} w-60`} autoFocus />
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
                                    {c.kode} — {c.keterangan ?? c.jenis}
                                </option>
                            ))}
                        </select>
                        <input
                            type="number"
                            min={1}
                            max={60}
                            value={s.hari}
                            onChange={(e) => update(i, { hari: Math.max(1, Number(e.target.value) || 1) })}
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
                    onClick={() => setSteps((prev) => [...prev, { shift_code_id: codes[0]?.id ?? 0, hari: 1 }])}
                    className="ml-7 text-xs font-bold text-brand-600 hover:underline"
                >
                    + Tambah langkah
                </button>
            </div>
            <div className="flex items-center gap-2">
                <span className="text-[11px] text-slate-400">Siklus {steps.reduce((n, s) => n + s.hari, 0)} hari:</span>
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
