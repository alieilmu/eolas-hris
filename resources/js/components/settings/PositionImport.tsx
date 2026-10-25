import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2, FileSpreadsheet, Loader2, Upload, X } from 'lucide-react';
import { PositionImportPreview, type PreviewRow } from '@/components/settings/PositionImportPreview';
import { postForm } from '@/lib/http';

const BASE = '/core-hr/settings/position/excel';
const button =
    'inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50';

/** Bulk-create positions from Excel: previewed first, then only the ready rows are saved. Existing ones are skipped, never overwritten. */
export function PositionImport({ onImported }: { onImported?: () => void }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [preview, setPreview] = useState<{ fileName: string; rows: PreviewRow[] } | null>(null);
    const [last, setLast] = useState<{ created: number; errors: number } | null>(null);

    const upload = async (file: File) => {
        setBusy(true);
        setError(null);
        setLast(null);
        const body = new FormData();
        body.append('file', file);
        try {
            const outcome = await postForm<{ rows: PreviewRow[] }>(`${BASE}/preview`, body);
            setPreview({ fileName: file.name, rows: outcome.rows });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal membaca file.');
        } finally {
            setBusy(false);
            if (input.current) input.current.value = '';
        }
    };

    return (
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-xs text-slate-500">
                    Tambah banyak jabatan sekaligus dari Excel. Sebelum disimpan, hasilnya ditampilkan dulu: mana yang <b>siap diinput</b>,{' '}
                    <b>dilewati</b> (sudah ada), atau <b>error</b> — baris error bisa diunduh untuk diperbaiki.
                </p>
                <div className="flex gap-2">
                    <a href={`${BASE}/template`} className={button}>
                        <FileSpreadsheet size={14} />
                        Template
                    </a>
                    <button onClick={() => input.current?.click()} disabled={busy} className={`${button} disabled:opacity-50`}>
                        {busy ? <Loader2 size={14} className="animate-spin" /> : <Upload size={14} />}
                        {busy ? 'Membaca file...' : 'Import Excel'}
                    </button>
                    <input
                        ref={input}
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        className="hidden"
                        onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])}
                    />
                </div>
            </div>

            {error && <p className="rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-600">{error}</p>}

            {last && (
                <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                    <p className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <CheckCircle2 size={16} className="text-emerald-500" />
                        {last.created} jabatan dibuat
                        {last.errors > 0 && <span className="font-medium text-rose-500">• {last.errors} baris error belum diimpor</span>}
                    </p>
                    <button onClick={() => setLast(null)} className="text-slate-300 hover:text-slate-500" aria-label="Tutup">
                        <X size={15} />
                    </button>
                </div>
            )}

            {preview && (
                <PositionImportPreview
                    fileName={preview.fileName}
                    rows={preview.rows}
                    onClose={() => setPreview(null)}
                    onImported={(created, errors) => {
                        setLast({ created, errors });
                        if (created > 0) {
                            router.reload();
                            onImported?.();
                        }
                    }}
                />
            )}
        </div>
    );
}
