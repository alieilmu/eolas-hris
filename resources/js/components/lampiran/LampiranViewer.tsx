import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronLeft, ChevronRight, Download, ExternalLink, FileText, Loader2, X, ZoomIn, ZoomOut } from 'lucide-react';
import { isPdf, openPdf, type LampiranFile } from '@/lib/lampiran';

/** One photo, or one page of a PDF. */
interface Slide {
    file: LampiranFile;
    fileIndex: number;
    page: number | null;
}

const ZOOMS = [1, 1.5, 2, 3];

/**
 * Every photo and every PDF page of the attachments as a carousel: arrows, ← / →
 * keys, thumbnails, zoom, and the original file to open or save.
 */
export function LampiranViewer({
    files,
    title,
    start = 0,
    onClose,
}: {
    files: LampiranFile[];
    title?: string;
    /** Index of the file to open first. */
    start?: number;
    onClose: () => void;
}) {
    const slides = useMemo<Slide[]>(
        () =>
            files.flatMap<Slide>((file, fileIndex) =>
                isPdf(file)
                    ? Array.from({ length: Math.max(1, file.halaman) }, (_, p) => ({ file, fileIndex, page: p + 1 }))
                    : [{ file, fileIndex, page: null }],
            ),
        [files],
    );
    const [index, setIndex] = useState(() =>
        Math.max(
            0,
            slides.findIndex((s) => s.fileIndex === start),
        ),
    );
    const [zoom, setZoom] = useState(0);
    const slide = slides[Math.min(index, slides.length - 1)];

    const go = useCallback(
        (step: number) => {
            setIndex((i) => (i + step + slides.length) % slides.length);
            setZoom(0);
        },
        [slides.length],
    );

    // Captured first, so Escape closes only the viewer and not a form below it.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            else if (e.key === 'ArrowRight') go(1);
            else if (e.key === 'ArrowLeft') go(-1);
            else return;
            e.preventDefault();
            e.stopPropagation();
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [go, onClose]);

    const thumbs = useRef<HTMLDivElement>(null);
    useEffect(() => {
        thumbs.current?.querySelector(`[data-slide="${index}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
    }, [index]);

    if (!slide) return null;
    const scale = ZOOMS[zoom];

    return createPortal(
        <div
            className="fixed inset-0 z-[80] flex flex-col bg-slate-950/[0.97] text-white backdrop-blur-md"
            role="dialog"
            aria-modal="true"
            aria-label={title ?? 'Lampiran'}
        >
            {/* Top bar */}
            <div className="flex shrink-0 items-center gap-3 px-4 py-3">
                <div className="min-w-0 flex-1">
                    {title && <p className="truncate text-xs font-bold text-white/50">{title}</p>}
                    <p className="truncate text-sm font-bold" title={slide.file.nama}>
                        {slide.file.nama}
                        {slide.page !== null && (
                            <span className="font-normal text-white/60">
                                {' '}
                                · halaman {slide.page} / {slide.file.halaman}
                            </span>
                        )}
                    </p>
                </div>
                <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold tabular-nums">
                    {index + 1} / {slides.length}
                </span>
                <button onClick={() => setZoom((z) => Math.max(0, z - 1))} disabled={!zoom} title="Perkecil" className={iconButton}>
                    <ZoomOut size={18} />
                </button>
                <button
                    onClick={() => setZoom((z) => Math.min(ZOOMS.length - 1, z + 1))}
                    disabled={zoom === ZOOMS.length - 1}
                    title="Perbesar"
                    className={iconButton}
                >
                    <ZoomIn size={18} />
                </button>
                <a href={slide.file.url} target="_blank" rel="noreferrer" title="Buka file asli di tab baru" className={iconButton}>
                    <ExternalLink size={18} />
                </a>
                <a href={slide.file.url} download={slide.file.nama} title="Unduh" className={iconButton}>
                    <Download size={18} />
                </a>
                <button onClick={onClose} title="Tutup (Esc)" className={iconButton}>
                    <X size={20} />
                </button>
            </div>

            {/* Stage */}
            <div className="relative min-h-0 flex-1">
                <div className={`absolute inset-0 ${scale > 1 ? 'overflow-auto' : 'flex items-center justify-center overflow-hidden'} px-14 py-2`}>
                    {slide.page === null ? (
                        <img
                            key={slide.file.url}
                            src={slide.file.url}
                            alt={slide.file.nama}
                            onClick={() => setZoom((z) => (z ? 0 : 1))}
                            style={scale > 1 ? { width: `${scale * 100}%`, maxWidth: 'none' } : undefined}
                            className={`${scale > 1 ? 'mx-auto cursor-zoom-out' : 'max-h-full max-w-full cursor-zoom-in object-contain'} rounded-lg bg-white shadow-2xl`}
                        />
                    ) : (
                        <PdfPage
                            key={`${slide.file.url}#${slide.page}`}
                            url={slide.file.url}
                            page={slide.page}
                            scale={scale}
                            onToggleZoom={() => setZoom((z) => (z ? 0 : 1))}
                        />
                    )}
                </div>
                {slides.length > 1 && (
                    <>
                        <button onClick={() => go(-1)} title="Sebelumnya (←)" className={`${navButton} left-3`}>
                            <ChevronLeft size={26} />
                        </button>
                        <button onClick={() => go(1)} title="Berikutnya (→)" className={`${navButton} right-3`}>
                            <ChevronRight size={26} />
                        </button>
                    </>
                )}
            </div>

            {/* Thumbnails */}
            {slides.length > 1 && (
                <div ref={thumbs} className="flex shrink-0 justify-center-safe gap-2 overflow-x-auto px-4 py-3">
                    {slides.map((s, i) => (
                        <button
                            key={i}
                            data-slide={i}
                            onClick={() => {
                                setIndex(i);
                                setZoom(0);
                            }}
                            title={s.page ? `${s.file.nama} · hal. ${s.page}` : s.file.nama}
                            className={`relative h-16 w-12 shrink-0 overflow-hidden rounded-md ring-2 transition ${
                                i === index ? 'ring-accent-400' : 'opacity-60 ring-transparent hover:opacity-100'
                            }`}
                        >
                            {s.page === null ? (
                                <img src={s.file.url} alt="" loading="lazy" className="h-full w-full bg-white object-cover" />
                            ) : (
                                <span className="flex h-full w-full flex-col items-center justify-center bg-white text-rose-600">
                                    <FileText size={16} />
                                    <span className="text-[10px] font-black text-slate-600">{s.page}</span>
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            )}
        </div>,
        document.body,
    );
}

const iconButton = 'rounded-lg p-2 text-white/70 hover:bg-white/10 hover:text-white disabled:opacity-30 disabled:hover:bg-transparent';
const navButton = 'absolute top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-2 text-white hover:bg-white/25';

/** One PDF page drawn on a canvas, sharp for the screen's pixel density and the zoom. */
function PdfPage({ url, page, scale, onToggleZoom }: { url: string; page: number; scale: number; onToggleZoom: () => void }) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

    useEffect(() => {
        let cancelled = false;
        let task: { cancel: () => void; promise: Promise<void> } | null = null;
        setState('loading');
        openPdf(url)
            .then((doc) => doc.getPage(Math.min(page, doc.numPages)))
            .then((p) => {
                if (cancelled || !canvas.current) return;
                // ~1600 px wide at 1x, more when zoomed or on a dense screen.
                const base = p.getViewport({ scale: 1 });
                const viewport = p.getViewport({ scale: (1600 / base.width) * Math.min(window.devicePixelRatio || 1, 2) * Math.min(scale, 2) });
                canvas.current.width = viewport.width;
                canvas.current.height = viewport.height;
                task = p.render({ canvas: canvas.current, viewport });
                return task.promise.then(() => !cancelled && setState('ready'));
            })
            .catch((e) => {
                if (!cancelled && e?.name !== 'RenderingCancelledException') setState('error');
            });
        return () => {
            cancelled = true;
            task?.cancel();
        };
    }, [url, page, scale]);

    return (
        <>
            {state === 'loading' && <Loader2 size={32} className="absolute top-1/2 left-1/2 -translate-1/2 animate-spin text-white/60" />}
            {state === 'error' && (
                <p className="absolute top-1/2 left-1/2 -translate-1/2 text-center text-sm text-white/70">
                    PDF tidak bisa ditampilkan.
                    <br />
                    <a href={url} target="_blank" rel="noreferrer" className="font-bold text-accent-400 underline">
                        Buka file aslinya
                    </a>
                </p>
            )}
            <canvas
                ref={canvas}
                onClick={onToggleZoom}
                style={scale > 1 ? { width: `${scale * 100}%`, maxWidth: 'none', height: 'auto' } : undefined}
                className={`${state === 'ready' ? '' : 'invisible'} ${scale > 1 ? 'mx-auto block cursor-zoom-out' : 'max-h-full max-w-full cursor-zoom-in'} rounded-lg bg-white shadow-2xl`}
            />
        </>
    );
}
