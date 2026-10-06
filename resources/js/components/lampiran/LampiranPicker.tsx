import { useEffect, useRef, useState } from 'react';
import { FileText, Loader2, Paperclip, Upload, X } from 'lucide-react';
import { LampiranViewer } from '@/components/lampiran/LampiranViewer';
import {
    draftFiles,
    fileSize,
    isPdf,
    notaCount,
    pdfPageCount,
    shrinkImage,
    type LampiranDraft,
    type LampiranFile,
    type LampiranLimits,
} from '@/lib/lampiran';

const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

/**
 * Photos / PDFs for a form: pick or drop files (photos are shrunk, PDF pages counted),
 * see each one in the carousel, and take any out. Required forms show the count.
 */
export function LampiranPicker({
    value,
    onChange,
    limits,
    label,
    hint,
    countLabel,
    error,
}: {
    value: LampiranDraft;
    onChange: (draft: LampiranDraft) => void;
    limits: LampiranLimits;
    label: string;
    hint?: string;
    /** "nota": shows "n nota" (each photo and PDF page one). */
    countLabel?: string;
    error?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [problems, setProblems] = useState<string[]>([]);
    const [dragging, setDragging] = useState(false);
    const [viewing, setViewing] = useState<number | null>(null);
    const files = draftFiles(value);
    const total = value.added.reduce((n, a) => n + a.file.size, 0);

    // Object URLs of picked files are freed when the form closes.
    const added = useRef(value.added);
    added.current = value.added;
    useEffect(() => () => added.current.forEach((a) => URL.revokeObjectURL(a.url)), []);

    const add = async (list: FileList | File[]) => {
        const picked = Array.from(list);
        if (!picked.length) return;
        setBusy(true);
        const issues: string[] = [];
        const next: LampiranDraft['added'] = [];
        let size = total;
        for (const raw of picked) {
            if (files.length + next.length >= limits.max_files) {
                issues.push(`Paling banyak ${limits.max_files} lampiran.`);
                break;
            }
            if (!ACCEPT.split(',').includes(raw.type)) {
                issues.push(`${raw.name}: harus foto (JPG / PNG / WEBP) atau PDF.`);
                continue;
            }
            const file = await shrinkImage(raw);
            if (file.size > limits.max_bytes) {
                issues.push(`${raw.name}: ${fileSize(file.size)}, paling besar ${fileSize(limits.max_bytes)} per file.`);
                continue;
            }
            if (size + file.size > limits.max_total_bytes * 0.95) {
                issues.push(`${raw.name}: total lampiran melebihi ${fileSize(limits.max_total_bytes)} sekali kirim.`);
                continue;
            }
            let halaman = 1;
            if (file.type === 'application/pdf') {
                try {
                    halaman = await pdfPageCount(file);
                } catch {
                    issues.push(`${raw.name}: PDF tidak bisa dibaca (rusak atau terkunci password).`);
                    continue;
                }
            }
            size += file.size;
            next.push({ file, nama: file.name, mime: file.type, ukuran: file.size, halaman, url: URL.createObjectURL(file) });
        }
        setProblems(issues);
        setBusy(false);
        if (next.length) onChange({ ...value, added: [...value.added, ...next] });
    };

    const remove = (f: LampiranFile) => {
        if (f.id !== undefined) onChange({ ...value, removed: [...value.removed, f.id] });
        else {
            URL.revokeObjectURL(f.url);
            onChange({ ...value, added: value.added.filter((a) => a.url !== f.url) });
        }
    };

    const count = notaCount(value);

    return (
        <div>
            <div className="mb-1.5 flex items-baseline justify-between gap-2">
                <span className="text-[11px] font-bold text-slate-500">
                    {label} <span className="text-rose-500">*</span>
                </span>
                {countLabel && (
                    <span className={`text-[11px] font-bold ${count ? 'text-emerald-700' : 'text-slate-400'}`}>
                        {count} {countLabel}
                    </span>
                )}
            </div>
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    void add(e.dataTransfer.files);
                }}
                className={`rounded-2xl border-2 border-dashed p-2.5 transition ${
                    dragging
                        ? 'border-brand-400 bg-brand-50'
                        : error && !files.length
                          ? 'border-rose-300 bg-rose-50/40'
                          : 'border-slate-200 bg-slate-50/60'
                }`}
            >
                <div className="flex flex-wrap gap-2">
                    {files.map((f, i) => (
                        <div key={f.url} className="group relative h-20 w-20 overflow-hidden rounded-xl border border-slate-200 bg-white">
                            <button type="button" onClick={() => setViewing(i)} title={`Lihat ${f.nama}`} className="h-full w-full">
                                {isPdf(f) ? (
                                    <span className="flex h-full w-full flex-col items-center justify-center gap-0.5 text-rose-600">
                                        <FileText size={22} />
                                        <span className="text-[10px] font-black text-slate-600">PDF · {f.halaman} hal.</span>
                                    </span>
                                ) : (
                                    <img src={f.url} alt={f.nama} className="h-full w-full object-cover" />
                                )}
                            </button>
                            <span className="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-slate-900/60 px-1 py-0.5 text-[9px] text-white">
                                {f.nama}
                            </span>
                            <button
                                type="button"
                                onClick={() => remove(f)}
                                title="Hapus lampiran ini"
                                className="absolute top-1 right-1 rounded-full bg-white/90 p-0.5 text-slate-500 shadow hover:bg-rose-600 hover:text-white"
                            >
                                <X size={12} />
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        onClick={() => input.current?.click()}
                        disabled={busy}
                        className={`flex h-20 flex-col items-center justify-center gap-1 rounded-xl border border-slate-200 bg-white px-3 text-[11px] font-bold text-brand-600 hover:border-brand-300 hover:bg-brand-50 disabled:opacity-60 ${files.length ? 'w-20' : 'min-w-44 flex-1'}`}
                    >
                        {busy ? <Loader2 size={20} className="animate-spin" /> : files.length ? <Paperclip size={20} /> : <Upload size={20} />}
                        {files.length ? 'Tambah' : 'Pilih / foto / seret file ke sini'}
                    </button>
                </div>
                <input
                    ref={input}
                    type="file"
                    accept={ACCEPT}
                    multiple
                    className="hidden"
                    onChange={(e) => {
                        if (e.target.files) void add(e.target.files);
                        e.target.value = '';
                    }}
                />
            </div>
            <p className="mt-1 text-[10px] text-slate-400">
                {hint ? `${hint} ` : ''}Foto (JPG / PNG / WEBP) atau PDF, maks. {fileSize(limits.max_bytes)} per file; foto besar diperkecil otomatis.
            </p>
            {[...problems, ...(error ? [error] : [])].map((p, i) => (
                <p key={i} className="mt-0.5 text-[11px] text-rose-600">
                    {p}
                </p>
            ))}
            {viewing !== null && <LampiranViewer files={files} start={viewing} title={label} onClose={() => setViewing(null)} />}
        </div>
    );
}

/** "📎 3" in a list: opens the attachments in the carousel. */
export function LampiranButton({ files, title, noun = 'lampiran' }: { files: LampiranFile[]; title: string; noun?: string }) {
    const [open, setOpen] = useState(false);
    if (!files.length) return <span className="text-[11px] text-slate-300">—</span>;
    const pages = files.reduce((n, f) => n + f.halaman, 0);
    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                title={`Lihat ${files.length} lampiran (${pages} halaman)`}
                className="inline-flex items-center gap-1 rounded-lg bg-brand-50 px-2 py-1 text-[11px] font-bold whitespace-nowrap text-brand-700 hover:bg-brand-100"
            >
                <Paperclip size={12} />
                {pages} {noun}
            </button>
            {open && <LampiranViewer files={files} title={title} onClose={() => setOpen(false)} />}
        </>
    );
}
