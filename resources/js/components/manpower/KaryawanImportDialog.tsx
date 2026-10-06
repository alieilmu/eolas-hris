import { useRef, useState } from 'react';
import { AlertTriangle, ArrowLeft, CheckCircle2, ChevronDown, ChevronRight, Download, FileSpreadsheet, Loader2, Upload, X } from 'lucide-react';
import { postForm } from '@/lib/http';
import type { ColumnGroup, ImportAction, ImportOutcome, ImportRowResult } from '@/types/manpower';

type Step = 'pilih' | 'pratinjau' | 'hasil';
type Filter = 'semua' | ImportAction;

const EXCEL_URL = '/manpower/karyawan/excel';

const ACTION_LABEL: Record<ImportAction, string> = {
    baru: 'Baru',
    perbarui: 'Diperbarui',
    'tanpa-perubahan': 'Tanpa perubahan',
    dilewati: 'Dilewati',
};

const ACTION_STYLE: Record<ImportAction, string> = {
    baru: 'bg-emerald-50 text-emerald-700 border-emerald-100',
    perbarui: 'bg-brand-50 text-brand-700 border-brand-100',
    'tanpa-perubahan': 'bg-slate-100 text-slate-500 border-slate-200',
    dilewati: 'bg-rose-50 text-rose-700 border-rose-100',
};

const pctText = (p: number | null) => (p === null ? '—' : `${p}%`);

function Badge({ action }: { action: ImportAction }) {
    return (
        <span className={`inline-block rounded-full border px-2 py-0.5 text-[10px] font-bold ${ACTION_STYLE[action]}`}>{ACTION_LABEL[action]}</span>
    );
}

function RowDetail({ r }: { r: ImportRowResult }) {
    const byGroup = new Map<string, ImportRowResult['changes']>();
    for (const c of r.changes) byGroup.set(c.group_title, [...(byGroup.get(c.group_title) ?? []), c]);

    return (
        <div className="space-y-4 border-t border-slate-100 bg-slate-50/70 px-5 py-4">
            {r.reason && <p className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-2.5 text-sm text-rose-600">{r.reason}</p>}

            {r.warnings.map((w, i) => (
                <p key={i} className="flex items-start gap-2 rounded-xl border border-amber-100 bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
                    <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                    {w}
                </p>
            ))}

            {r.action !== 'dilewati' && (
                <>
                    {r.changes.length === 0 ? (
                        <p className="text-xs text-slate-400">Semua nilai di file sama dengan data yang sudah tersimpan.</p>
                    ) : (
                        <div className="space-y-3">
                            <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">
                                Data yang akan berubah ({r.changes.length})
                            </p>
                            {[...byGroup.entries()].map(([title, list]) => (
                                <div key={title}>
                                    <p className="mb-1 text-[11px] font-bold text-slate-600">{title}</p>
                                    <table className="w-full text-xs">
                                        <tbody>
                                            {list.map((c) => (
                                                <tr key={c.label} className="border-t border-slate-100">
                                                    <td className="w-56 py-1.5 pr-3 align-top text-slate-500">{c.label}</td>
                                                    <td className="py-1.5 pr-3 align-top text-slate-400">{c.before ?? '(kosong)'}</td>
                                                    <td className="py-1.5 pr-3 align-top text-slate-300">→</td>
                                                    <td className="py-1.5 align-top font-bold break-words text-slate-700">{c.after ?? '(kosong)'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ))}
                        </div>
                    )}

                    <div>
                        <p className="mb-2 text-[11px] font-bold tracking-wide text-slate-500 uppercase">Status kelengkapan setelah import</p>
                        <div className="grid grid-cols-2 gap-x-6 gap-y-1 md:grid-cols-3">
                            {r.groups_after.map((g) => (
                                <div key={g.id} className="flex items-center justify-between gap-3 text-xs">
                                    <span className="truncate text-slate-500" title={g.title}>
                                        {g.title}
                                    </span>
                                    <span className={`font-bold tabular-nums ${g.percent === null ? 'text-slate-300' : 'text-slate-700'}`}>
                                        {pctText(g.percent)}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-[11px] text-slate-400">&quot;—&quot; berarti kelompok itu tidak perlu diisi untuk karyawan ini.</p>
                    </div>
                </>
            )}
        </div>
    );
}

interface Props {
    groups: ColumnGroup[];
    /** Number of employees, shown next to the "pre-fill" option. */
    currentCount: number;
    onClose: () => void;
    /** Called once the import has really been saved. */
    onImported: () => void;
}

export function KaryawanImportDialog({ groups, currentCount, onClose, onImported }: Props) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [step, setStep] = useState<Step>('pilih');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [file, setFile] = useState<File | null>(null);
    const [updateOnly, setUpdateOnly] = useState(false);
    const [preview, setPreview] = useState<ImportOutcome | null>(null);
    const [result, setResult] = useState<ImportOutcome | null>(null);

    const [showGroupPicker, setShowGroupPicker] = useState(false);
    const [pickedGroups, setPickedGroups] = useState<Set<string>>(new Set());
    const [prefill, setPrefill] = useState(true);

    const [filter, setFilter] = useState<Filter>('semua');
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    // The same file is sent twice: once as a dry run for the preview, once to save.
    const send = (f: File, dryRun: boolean) => {
        const body = new FormData();
        body.append('file', f);
        body.append('dry_run', dryRun ? '1' : '0');
        body.append('update_only', updateOnly ? '1' : '0');
        return postForm<ImportOutcome>(`${EXCEL_URL}/import`, body);
    };

    const handleFile = async (f: File) => {
        setBusy(true);
        setError(null);
        try {
            setPreview(await send(f, true));
            setFile(f);
            setFilter('semua');
            setExpanded(new Set());
            setStep('pratinjau');
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal membaca file.');
        } finally {
            setBusy(false);
        }
    };

    const handleConfirm = async () => {
        if (!file) return;
        setBusy(true);
        setError(null);
        try {
            setResult(await send(file, false));
            setStep('hasil');
            onImported();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal menyimpan data.');
        } finally {
            setBusy(false);
        }
    };

    const groupTemplateUrl = () => {
        const params = new URLSearchParams();
        pickedGroups.forEach((g) => params.append('groups[]', g));
        params.set('prefill', prefill ? '1' : '0');
        return `${EXCEL_URL}/template-kelompok?${params}`;
    };

    const toggle = <T,>(set: Set<T>, value: T) => {
        const next = new Set(set);
        if (next.has(value)) next.delete(value);
        else next.add(value);
        return next;
    };

    const counts = preview
        ? { baru: preview.created, perbarui: preview.updated, 'tanpa-perubahan': preview.unchanged, dilewati: preview.skipped.length }
        : null;
    const importable = preview ? preview.created + preview.updated : 0;
    const visibleRows = preview ? preview.rows.filter((r) => filter === 'semua' || r.action === filter) : [];

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
            <div
                className={`w-full ${step === 'pratinjau' ? 'max-w-5xl' : 'max-w-lg'} flex max-h-[90vh] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl`}
            >
                <div className="flex shrink-0 items-center justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">
                            {step === 'pilih' && 'Import Data Karyawan'}
                            {step === 'pratinjau' && 'Pratinjau — periksa sebelum disimpan'}
                            {step === 'hasil' && 'Import Selesai'}
                        </h3>
                        {step === 'pratinjau' && (
                            <p className="mt-0.5 text-xs text-slate-400">
                                {file?.name} • {preview?.total_rows} baris terbaca • belum ada data yang tersimpan
                            </p>
                        )}
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {error && <div className="mx-6 mt-5 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</div>}

                    {step === 'pilih' && (
                        <div className="space-y-6 p-6">
                            <section className="space-y-3">
                                <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">1. Unduh template (opsional)</p>

                                <a
                                    href={`${EXCEL_URL}/template`}
                                    className="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                                >
                                    <Download size={16} />
                                    Template lengkap (semua kelompok, untuk karyawan baru)
                                </a>

                                <div className="rounded-xl border border-slate-200">
                                    <button
                                        onClick={() => setShowGroupPicker((v) => !v)}
                                        className="flex w-full items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-600 transition-all hover:bg-slate-50"
                                    >
                                        <FileSpreadsheet size={16} />
                                        <span className="flex-1 text-left">Update sebagian data (pilih kelompok)</span>
                                        {showGroupPicker ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                                    </button>

                                    {showGroupPicker && (
                                        <div className="space-y-3 border-t border-slate-100 px-4 pt-3 pb-4">
                                            <p className="text-xs text-slate-400">
                                                Cocok untuk melengkapi satu bagian saja (mis. data keluarga) — file hanya berisi NRP dan kolom
                                                kelompok yang dipilih, dan hanya kolom itu yang diperbarui.
                                            </p>
                                            <div className="grid max-h-52 grid-cols-1 gap-x-4 gap-y-1.5 overflow-y-auto pr-1 sm:grid-cols-2">
                                                {groups.map((g) => (
                                                    <label key={g.id} className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                                        <input
                                                            type="checkbox"
                                                            checked={pickedGroups.has(g.id)}
                                                            onChange={() => setPickedGroups((prev) => toggle(prev, g.id))}
                                                            className="accent-brand-600"
                                                        />
                                                        {g.title}
                                                    </label>
                                                ))}
                                            </div>
                                            <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                                                <input
                                                    type="checkbox"
                                                    checked={prefill}
                                                    onChange={(e) => setPrefill(e.target.checked)}
                                                    className="accent-brand-600"
                                                />
                                                Isi dengan data karyawan saat ini ({currentCount} karyawan)
                                            </label>
                                            {pickedGroups.size === 0 ? (
                                                <span className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-xs font-bold text-white opacity-50">
                                                    <Download size={14} />
                                                    Pilih minimal 1 kelompok
                                                </span>
                                            ) : (
                                                <a
                                                    href={groupTemplateUrl()}
                                                    className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-xs font-bold text-white transition-all hover:bg-brand-700"
                                                >
                                                    <Download size={14} />
                                                    Unduh ({pickedGroups.size} kelompok)
                                                </a>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>

                            <section className="space-y-3">
                                <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">2. Pilih file Excel</p>
                                <label className="flex cursor-pointer items-start gap-2 text-xs text-slate-600">
                                    <input
                                        type="checkbox"
                                        checked={updateOnly}
                                        onChange={(e) => setUpdateOnly(e.target.checked)}
                                        className="mt-0.5 accent-brand-600"
                                    />
                                    <span>
                                        <span className="font-bold">Hanya perbarui karyawan yang sudah terdaftar</span>
                                        <br />
                                        <span className="text-slate-400">
                                            NRP yang belum ada dilewati, tidak dibuat sebagai karyawan baru — aman dari salah ketik NRP.
                                        </span>
                                    </span>
                                </label>

                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    className="hidden"
                                    onChange={(e) => {
                                        const f = e.target.files?.[0];
                                        if (f) handleFile(f);
                                        e.target.value = '';
                                    }}
                                />
                                <button
                                    onClick={() => fileInputRef.current?.click()}
                                    disabled={busy}
                                    className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-100 transition-all hover:bg-brand-700 disabled:opacity-50"
                                >
                                    {busy ? <Loader2 className="animate-spin" size={16} /> : <Upload size={16} />}
                                    {busy ? 'Membaca & memeriksa file...' : 'Pilih File .xlsx'}
                                </button>
                                <p className="text-[11px] text-slate-400">
                                    Data belum disimpan saat file dipilih — Anda akan melihat pratinjau dulu.
                                </p>
                                <p className="rounded-xl border border-brand-100 bg-brand-50 px-3 py-2 text-[11px] text-brand-700">
                                    Kolom bertanda <b>*</b> (kelompok 1 Identitas &amp; Pekerjaan dan 2 Kelahiran &amp; Masa Kerja) wajib terisi.
                                    Karyawan baru harus mengisinya di file; untuk karyawan lama boleh dikosongkan bila datanya sudah tersimpan.
                                </p>
                            </section>
                        </div>
                    )}

                    {step === 'pratinjau' && preview && counts && (
                        <div className="space-y-5 p-6">
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                {(['baru', 'perbarui', 'tanpa-perubahan', 'dilewati'] as ImportAction[]).map((a) => (
                                    <button
                                        key={a}
                                        onClick={() => setFilter(filter === a ? 'semua' : a)}
                                        className={`rounded-2xl border px-4 py-3 text-left transition-all ${filter === a ? 'ring-2 ring-brand-500' : ''} ${ACTION_STYLE[a]}`}
                                    >
                                        <p className="text-2xl leading-none font-black">{counts[a]}</p>
                                        <p className="mt-1 text-[11px] font-bold">{ACTION_LABEL[a]}</p>
                                    </button>
                                ))}
                            </div>

                            <div className="space-y-2 text-xs">
                                <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">Kelompok data yang terbaca dari file</p>
                                {preview.recognized_groups.length === 0 ? (
                                    <p className="text-slate-400">Tidak ada kolom data yang dikenali selain NRP.</p>
                                ) : (
                                    <div className="flex flex-wrap gap-1.5">
                                        {preview.recognized_groups.map((g) => (
                                            <span
                                                key={g.id}
                                                className="rounded-lg border border-brand-100 bg-brand-50 px-2.5 py-1 font-bold text-brand-700"
                                            >
                                                {g.title} <span className="font-normal text-brand-500">({g.columns} kolom)</span>
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {preview.unknown_columns.length > 0 && (
                                    <p className="flex items-start gap-2 rounded-xl border border-amber-100 bg-amber-50 px-4 py-2.5 text-amber-700">
                                        <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                                        <span>
                                            Kolom tidak dikenali dan <b>diabaikan</b>: {preview.unknown_columns.join(', ')}. Periksa penulisan judul
                                            kolom — jangan diubah dari template.
                                        </span>
                                    </p>
                                )}
                                {preview.update_only && (
                                    <p className="text-slate-500">Mode aktif: hanya memperbarui karyawan yang sudah terdaftar.</p>
                                )}
                            </div>

                            <div className="overflow-hidden rounded-2xl border border-slate-200">
                                <div className="grid grid-cols-[3rem_6rem_1fr_7rem_10rem_2rem] gap-3 bg-slate-50 px-5 py-2.5 text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                    <span>Baris</span>
                                    <span>NRP</span>
                                    <span>Nama / kelompok yang berubah</span>
                                    <span>Aksi</span>
                                    <span>Kelengkapan data</span>
                                    <span />
                                </div>

                                {visibleRows.length === 0 && (
                                    <p className="px-5 py-8 text-center text-sm text-slate-400">Tidak ada baris untuk filter ini.</p>
                                )}

                                {visibleRows.map((r) => {
                                    const open = expanded.has(r.row);
                                    return (
                                        <div key={r.row} className="border-t border-slate-100">
                                            <button
                                                onClick={() => setExpanded((prev) => toggle(prev, r.row))}
                                                className="grid w-full grid-cols-[3rem_6rem_1fr_7rem_10rem_2rem] items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-slate-50/70"
                                            >
                                                <span className="text-xs text-slate-400 tabular-nums">{r.row}</span>
                                                <span className="truncate text-xs font-bold text-slate-700">{r.nrp || '—'}</span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-sm font-bold text-slate-700">{r.nama || '—'}</span>
                                                    {r.groups.length > 0 && (
                                                        <span className="block truncate text-[11px] text-slate-400">{r.groups.join(' • ')}</span>
                                                    )}
                                                    {r.action === 'dilewati' && r.reason && (
                                                        <span className="block truncate text-[11px] text-rose-500">{r.reason}</span>
                                                    )}
                                                </span>
                                                <span>
                                                    <Badge action={r.action} />
                                                    {r.warnings.length > 0 && <AlertTriangle size={12} className="ml-1.5 inline text-amber-500" />}
                                                </span>
                                                <span className="text-xs text-slate-600 tabular-nums">
                                                    {r.action === 'dilewati' ? (
                                                        '—'
                                                    ) : r.completeness_before === null ? (
                                                        <b>{pctText(r.completeness_after)}</b>
                                                    ) : (
                                                        <>
                                                            {pctText(r.completeness_before)} <span className="text-slate-300">→</span>{' '}
                                                            <b>{pctText(r.completeness_after)}</b>
                                                        </>
                                                    )}
                                                </span>
                                                <span className="text-slate-400">
                                                    {open ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                                                </span>
                                            </button>
                                            {open && <RowDetail r={r} />}
                                        </div>
                                    );
                                })}
                            </div>

                            <p className="text-[11px] text-slate-400">
                                Kelengkapan dihitung dengan aturan &quot;tidak perlu diisi&quot;, jadi angkanya bisa turun bila status berubah membuat
                                kelompok baru wajib diisi (mis. status menjadi Menikah → data pasangan &amp; mertua ikut dinilai).
                            </p>
                        </div>
                    )}

                    {step === 'hasil' && result && (
                        <div className="space-y-4 p-6">
                            <div className="flex items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                                <CheckCircle2 size={16} className="shrink-0" />
                                {result.created} karyawan baru dibuat, {result.updated} diperbarui
                                {result.unchanged > 0 ? `, ${result.unchanged} tanpa perubahan` : ''}.
                            </div>
                            {result.skipped.length > 0 && (
                                <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm">
                                    <p className="mb-2 flex items-center gap-2 font-bold text-rose-700">
                                        <AlertTriangle size={14} />
                                        {result.skipped.length} baris dilewati
                                    </p>
                                    <ul className="max-h-40 space-y-1 overflow-y-auto text-xs text-rose-600">
                                        {result.skipped.map((s, i) => (
                                            <li key={i}>
                                                Baris {s.row}: {s.reason}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                <div className="flex shrink-0 items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                    {step === 'pratinjau' ? (
                        <>
                            <button
                                onClick={() => {
                                    setStep('pilih');
                                    setPreview(null);
                                    setError(null);
                                }}
                                disabled={busy}
                                className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                            >
                                <ArrowLeft size={15} />
                                Pilih file lain
                            </button>
                            <div className="flex items-center gap-3">
                                {importable === 0 && <span className="text-xs text-slate-400">Tidak ada baris yang bisa disimpan.</span>}
                                <button
                                    onClick={handleConfirm}
                                    disabled={busy || importable === 0}
                                    className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-brand-100 transition-all hover:bg-brand-700 disabled:opacity-50"
                                >
                                    {busy ? <Loader2 className="animate-spin" size={16} /> : <CheckCircle2 size={16} />}
                                    Konfirmasi &amp; Simpan ({importable} baris)
                                </button>
                            </div>
                        </>
                    ) : (
                        <>
                            <span />
                            <button
                                onClick={onClose}
                                className="rounded-xl px-5 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                            >
                                {step === 'hasil' ? 'Selesai' : 'Batal'}
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
