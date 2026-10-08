import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, CircleAlert, CircleCheck, Download, FileUp, Loader2 } from 'lucide-react';
import { Modal, ghostButton, primaryButton, secondaryButton } from '@/components/attendance/ui';
import { fmtDate } from '@/lib/format';
import { postForm } from '@/lib/http';

interface Row {
    baris: number;
    nrp: string | null;
    nama: string | null;
    jenis: string | null;
    tanggal_awal: string | null;
    tanggal_akhir: string | null;
    tujuan: string | null;
    ok: boolean;
    pesan: string[];
}

interface Preview {
    rows: Row[];
    ok: number;
    gagal: number;
}

const URL = '/attendance/pengajuan-dinas/import';
const date = (d: string | null) => (d && /^\d{4}-\d{2}-\d{2}$/.test(d) ? fmtDate(d) : (d ?? '—'));

/** Pengajuan Dinas / cuti in bulk: the template, a check of every row, then the valid rows are saved. */
export function TripImportDialog({ onClose }: { onClose: () => void }) {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [onlyErrors, setOnlyErrors] = useState(false);

    const check = async (f: File) => {
        setFile(f);
        setPreview(null);
        setError(null);
        setBusy(true);
        const body = new FormData();
        body.append('file', f);
        try {
            const p = await postForm<Preview>(URL, body);
            setPreview(p);
            setOnlyErrors(p.gagal > 0);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const save = () => {
        if (!file) return;
        setBusy(true);
        router.post(URL, { file, simpan: 1 }, { forceFormData: true, preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) });
    };
    const rows = preview ? preview.rows.filter((r) => !onlyErrors || !r.ok) : [];

    return (
        <Modal
            title="Import Pengajuan Dinas / Cuti"
            subtitle="Tiap baris dicek seperti input manual di Trip & Leave, lalu disimpan sebagai disetujui dan mengisi Absensi. Form bertanda tangan tidak diperlukan."
            onClose={onClose}
            width="max-w-3xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy || !preview || preview.ok === 0} className={primaryButton}>
                        {busy && preview ? <Loader2 size={16} className="animate-spin" /> : <Check size={16} />}
                        {preview && preview.gagal > 0 ? `Import ${preview.ok} baris valid` : 'Import'}
                    </button>
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <a href="/attendance/pengajuan-dinas/template" className={secondaryButton}>
                    <Download size={16} />
                    Unduh template
                </a>
                <span className="text-xs text-slate-500">
                    Isi sheet pertama mulai baris 5; contoh dan aturan kolom ada di sheet Petunjuk & Aturan.
                </span>
            </div>
            <label className="mt-3 flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 hover:border-brand-300">
                <FileUp size={22} className="text-slate-400" />
                <span className="min-w-0 flex-1 truncate text-sm font-bold text-slate-600">{file?.name ?? 'Pilih file .xlsx'}</span>
                {busy && !preview && <Loader2 size={16} className="animate-spin text-slate-400" />}
                <input
                    type="file"
                    accept=".xlsx,.xls"
                    className="hidden"
                    onChange={(e) => {
                        if (e.target.files?.[0]) check(e.target.files[0]);
                        e.target.value = '';
                    }}
                />
            </label>
            {error && <p className="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700">{error}</p>}
            {preview && (
                <div className="mt-4 space-y-2 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p>
                            <b className="text-emerald-700">{preview.ok} baris valid</b>
                            {preview.gagal > 0 && <b className="text-rose-600"> · {preview.gagal} baris bermasalah (dilewati)</b>}
                        </p>
                        {preview.gagal > 0 && preview.ok > 0 && (
                            <label className="flex items-center gap-1.5 text-xs text-slate-500">
                                <input
                                    type="checkbox"
                                    checked={onlyErrors}
                                    onChange={(e) => setOnlyErrors(e.target.checked)}
                                    className="accent-brand-600"
                                />
                                Hanya yang bermasalah
                            </label>
                        )}
                    </div>
                    <div className="max-h-80 overflow-y-auto rounded-2xl border border-slate-200">
                        <table className="w-full text-left text-xs">
                            <thead className="sticky top-0 bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-3 py-2">Baris</th>
                                    <th className="px-3 py-2">Karyawan</th>
                                    <th className="px-3 py-2">Jenis</th>
                                    <th className="px-3 py-2">Tanggal</th>
                                    <th className="px-3 py-2">Hasil</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((r) => (
                                    <tr key={r.baris} className={r.ok ? '' : 'bg-rose-50/40'}>
                                        <td className="px-3 py-2 text-slate-400 tabular-nums">{r.baris}</td>
                                        <td className="px-3 py-2">
                                            <p className="font-bold text-slate-700">{r.nama ?? '—'}</p>
                                            <p className="text-[11px] text-slate-400">{r.nrp ?? '—'}</p>
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">
                                            {r.jenis ?? '—'}
                                            {r.tujuan && <span className="block text-[11px] text-slate-400">ke {r.tujuan}</span>}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap text-slate-600 tabular-nums">
                                            {date(r.tanggal_awal)} – {date(r.tanggal_akhir)}
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.ok ? (
                                                <span className="inline-flex items-center gap-1 font-bold text-emerald-700">
                                                    <CircleCheck size={13} /> Siap diimport
                                                </span>
                                            ) : (
                                                <span className="flex items-start gap-1 text-rose-700">
                                                    <CircleAlert size={13} className="mt-0.5 shrink-0" />
                                                    <span>{r.pesan.join(' ')}</span>
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </Modal>
    );
}
