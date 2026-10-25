import { useEffect, useMemo, useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Layers, Loader2, Save, SkipForward, X } from 'lucide-react';
import { useDirtyForm } from '@/components/settings/DirtyGuard';
import { downloadPost, postJson } from '@/lib/http';

export type RowStatus = 'new' | 'skipped' | 'error';

export interface PreviewRow {
    row: number;
    values: Record<ValueKey, string | null>;
    status: RowStatus;
    /** The composed position name (null when the row has an error). */
    name: string | null;
    /** Where it sits in the organisation, e.g. "Section PERSONALIA · HRGA". */
    unit: string | null;
    reason: string | null;
}

type ValueKey = 'work_location' | 'job_level' | 'directorate' | 'division' | 'department' | 'section' | 'discipline';

const BASE = '/core-hr/settings/position/excel';

/** The template's columns; BOD and Divisi are only shown when the file uses them. */
const COLUMNS: { key: ValueKey; label: string; optional?: boolean }[] = [
    { key: 'work_location', label: 'Work Location' },
    { key: 'job_level', label: 'SubGroup' },
    { key: 'directorate', label: 'BOD', optional: true },
    { key: 'division', label: 'Divisi', optional: true },
    { key: 'department', label: 'Departemen' },
    { key: 'section', label: 'Section' },
    { key: 'discipline', label: 'Nama Jabatan (Bidang)' },
];

const STATUS: Record<RowStatus, { label: string; card: string; badge: string; icon: typeof CheckCircle2; hint: string }> = {
    new: {
        label: 'Siap diinput',
        card: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        badge: 'bg-emerald-100 text-emerald-700',
        icon: CheckCircle2,
        hint: 'Jabatan baru yang akan dibuat',
    },
    skipped: {
        label: 'Dilewati',
        card: 'border-slate-200 bg-slate-50 text-slate-600',
        badge: 'bg-slate-100 text-slate-500',
        icon: SkipForward,
        hint: 'Sudah ada, atau ganda di file',
    },
    error: {
        label: 'Error',
        card: 'border-rose-200 bg-rose-50 text-rose-700',
        badge: 'bg-rose-100 text-rose-700',
        icon: AlertTriangle,
        hint: 'Perlu diperbaiki lalu diimpor lagi',
    },
};

interface Props {
    fileName: string;
    rows: PreviewRow[];
    onClose: () => void;
    /** After saving, with the number of positions created. */
    onImported: (created: number, errors: number) => void;
}

/**
 * Shows what an Excel import would do before anything is saved: cards per
 * outcome, the rows as a table, the failed rows as a file to fix. Closing
 * before saving asks first.
 */
export function PositionImportPreview({ fileName, rows: previewRows, onClose, onImported }: Props) {
    const [rows, setRows] = useState(previewRows);
    const [filter, setFilter] = useState<RowStatus | 'all'>('all');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState<number | null>(null);
    const [downloading, setDownloading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [confirmClose, setConfirmClose] = useState(false);

    const counts = useMemo(() => {
        const c: Record<RowStatus, number> = { new: 0, skipped: 0, error: 0 };
        rows.forEach((r) => c[r.status]++);
        return c;
    }, [rows]);
    const shown = filter === 'all' ? rows : rows.filter((r) => r.status === filter);
    const columns = COLUMNS.filter((c) => !c.optional || rows.some((r) => r.values[c.key]));
    const done = saved !== null;

    const save = async (): Promise<boolean> => {
        const ready = rows.filter((r) => r.status === 'new');
        if (!ready.length) return false;
        setSaving(true);
        setError(null);
        try {
            const result = await postJson<{ created: number; rows: PreviewRow[] }>(`${BASE}/import`, {
                rows: ready.map((r) => ({ row: r.row, values: r.values })),
            });
            // What happened to the ready rows now (something may have changed since the preview).
            const byRow = new Map(result.rows.map((r) => [r.row, r]));
            const next = rows.map((r) => byRow.get(r.row) ?? r);
            setRows(next);
            setSaved(result.created);
            setFilter('all');
            onImported(result.created, next.filter((r) => r.status === 'error').length);
            return true;
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal menyimpan.');
            return false;
        } finally {
            setSaving(false);
        }
    };

    const downloadErrors = async () => {
        setDownloading(true);
        setError(null);
        try {
            await downloadPost(
                `${BASE}/fix`,
                { rows: rows.filter((r) => r.status === 'error').map((r) => ({ row: r.row, values: r.values, reason: r.reason })) },
                'fix-import-position.xlsx',
            );
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal mengunduh.');
        } finally {
            setDownloading(false);
        }
    };

    // Nothing is kept until saved: closing first asks.
    const requestClose = () => (done ? onClose() : setConfirmClose(true));

    // Leaving the page with the preview open asks too (see DirtyGuard).
    useDirtyForm('position-import-preview', !done && counts.new > 0, { label: 'Preview import jabatan', save, discard: onClose });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape' || saving) return;
            if (confirmClose) setConfirmClose(false);
            else requestClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm sm:p-6"
            onMouseDown={(e) => e.target === e.currentTarget && !saving && requestClose()}
        >
            <div className="relative flex h-[92vh] w-full max-w-7xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                {/* Header */}
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
                    <div className="min-w-0">
                        <h3 className="font-bold text-slate-800">{done ? 'Hasil Import Jabatan' : 'Preview Import Jabatan'}</h3>
                        <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-slate-400">
                            <FileSpreadsheet size={13} className="shrink-0" />
                            {fileName} • {rows.length} baris
                            {!done && ' • belum disimpan'}
                        </p>
                    </div>
                    <button
                        onClick={requestClose}
                        disabled={saving}
                        className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 disabled:opacity-50"
                        aria-label="Tutup"
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* Cards */}
                <div className="grid shrink-0 grid-cols-2 gap-3 px-6 pt-5 lg:grid-cols-4">
                    <button
                        onClick={() => setFilter('all')}
                        className={`flex items-center gap-3 rounded-2xl border p-4 text-left transition-all ${
                            filter === 'all' ? 'border-brand-300 bg-brand-50 ring-2 ring-brand-200' : 'border-slate-200 bg-white hover:bg-slate-50'
                        }`}
                    >
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-700">
                            <Layers size={18} />
                        </div>
                        <div>
                            <p className="text-2xl leading-none font-bold text-slate-800 tabular-nums">{rows.length}</p>
                            <p className="mt-1 text-xs font-bold text-slate-500">Semua baris</p>
                        </div>
                    </button>
                    {(Object.keys(STATUS) as RowStatus[]).map((status) => {
                        const s = STATUS[status];
                        const Icon = s.icon;
                        const label = done && status === 'new' ? 'Berhasil diinput' : s.label;
                        return (
                            <button
                                key={status}
                                onClick={() => setFilter(status)}
                                className={`flex items-center gap-3 rounded-2xl border p-4 text-left transition-all ${s.card} ${
                                    filter === status ? 'ring-2 ring-current/30' : 'hover:brightness-[0.98]'
                                }`}
                            >
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/70">
                                    <Icon size={18} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="text-2xl leading-none font-bold tabular-nums">{counts[status]}</p>
                                    <p className="mt-1 text-xs font-bold">{label}</p>
                                    <p className="truncate text-[11px] opacity-70">{s.hint}</p>
                                </div>
                            </button>
                        );
                    })}
                </div>

                {counts.error > 0 && (
                    <div className="mx-6 mt-3 flex shrink-0 flex-wrap items-center justify-between gap-3 rounded-2xl border border-rose-100 bg-rose-50/60 px-4 py-2.5">
                        <p className="text-xs text-rose-700">
                            <b>{counts.error} baris error</b> tidak ikut disimpan. Unduh dalam bentuk template (dengan kolom <b>Alasan Error</b>),
                            perbaiki, lalu impor lagi.
                        </p>
                        <button
                            onClick={downloadErrors}
                            disabled={downloading}
                            className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-white px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50"
                        >
                            {downloading ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />}
                            Unduh data error
                        </button>
                    </div>
                )}

                {error && <p className="mx-6 mt-3 shrink-0 rounded-xl border border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-600">{error}</p>}

                {/* Table */}
                <div className="mx-6 my-4 min-h-0 flex-1 overflow-auto rounded-2xl border border-slate-200">
                    <table className="w-full min-w-[1000px] text-left text-xs">
                        <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] font-bold tracking-wider text-slate-500 uppercase">
                            <tr>
                                <th className="px-3 py-2.5">Baris</th>
                                <th className="px-3 py-2.5">Status</th>
                                <th className="px-3 py-2.5">Jabatan (hasil)</th>
                                {columns.map((c) => (
                                    <th key={c.key} className="px-3 py-2.5">
                                        {c.label}
                                    </th>
                                ))}
                                <th className="px-3 py-2.5">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {shown.map((r) => {
                                const s = STATUS[r.status];
                                return (
                                    <tr
                                        key={r.row}
                                        className={r.status === 'error' ? 'bg-rose-50/40' : r.status === 'skipped' ? 'text-slate-400' : ''}
                                    >
                                        <td className="px-3 py-2 text-slate-400 tabular-nums">{r.row}</td>
                                        <td className="px-3 py-2">
                                            <span
                                                className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold whitespace-nowrap ${s.badge}`}
                                            >
                                                {done && r.status === 'new' ? 'Diinput' : s.label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.name ? (
                                                <>
                                                    <p className={`font-bold ${r.status === 'skipped' ? 'text-slate-500' : 'text-slate-800'}`}>
                                                        {r.name}
                                                    </p>
                                                    {r.unit && <p className="text-[11px] text-slate-400">{r.unit}</p>}
                                                </>
                                            ) : (
                                                <span className="text-slate-300">—</span>
                                            )}
                                        </td>
                                        {columns.map((c) => (
                                            <td key={c.key} className="px-3 py-2 whitespace-nowrap">
                                                {r.values[c.key] ?? <span className="text-slate-300">—</span>}
                                            </td>
                                        ))}
                                        <td className={`px-3 py-2 ${r.status === 'error' ? 'font-medium text-rose-600' : 'text-slate-400'}`}>
                                            {r.reason ?? ''}
                                        </td>
                                    </tr>
                                );
                            })}
                            {shown.length === 0 && (
                                <tr>
                                    <td colSpan={columns.length + 4} className="px-3 py-10 text-center text-slate-400">
                                        Tidak ada baris di kelompok ini.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Footer */}
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                    <p className="text-[11px] text-slate-400">
                        {done
                            ? `${saved} jabatan berhasil dibuat.`
                            : 'Hanya baris "Siap diinput" yang disimpan. Baris dilewati dan error tidak mengubah data.'}
                    </p>
                    <div className="flex gap-2">
                        {done ? (
                            <button onClick={onClose} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700">
                                Selesai
                            </button>
                        ) : (
                            <>
                                <button
                                    onClick={requestClose}
                                    disabled={saving}
                                    className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100 disabled:opacity-50"
                                >
                                    Batal
                                </button>
                                <button
                                    onClick={save}
                                    disabled={saving || counts.new === 0}
                                    className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                >
                                    {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                                    Simpan {counts.new} Jabatan
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {/* Closing before saving */}
                {confirmClose && (
                    <div
                        className="absolute inset-0 z-20 flex items-center justify-center bg-slate-900/30 p-6"
                        onMouseDown={(e) => e.stopPropagation()}
                    >
                        <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
                            <div className="flex items-start gap-3 p-6">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
                                    <AlertTriangle size={20} />
                                </div>
                                <div>
                                    <h4 className="font-bold text-slate-800">Tutup tanpa menyimpan?</h4>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {counts.new > 0
                                            ? `${counts.new} jabatan yang siap diinput belum disimpan dan akan dibuang.`
                                            : 'Hasil preview ini akan ditutup.'}
                                        {counts.error > 0 && ' Unduh data error dulu bila ingin memperbaikinya.'}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                                <button onClick={onClose} className="text-xs font-bold text-slate-400 hover:text-rose-600">
                                    Tutup tanpa simpan
                                </button>
                                <div className="flex gap-2">
                                    <button
                                        onClick={() => setConfirmClose(false)}
                                        className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100"
                                    >
                                        Kembali
                                    </button>
                                    {counts.new > 0 && (
                                        <button
                                            onClick={async () => {
                                                setConfirmClose(false);
                                                await save();
                                            }}
                                            className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700"
                                        >
                                            <Save size={16} />
                                            Simpan dulu
                                        </button>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
