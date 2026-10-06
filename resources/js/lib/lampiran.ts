import type { PDFDocumentProxy } from 'pdfjs-dist';

/** An attachment as the server lists it, or a file picked in the browser (url: an object URL). */
export interface LampiranFile {
    id?: number;
    nama: string;
    mime: string;
    ukuran: number;
    halaman: number;
    url: string;
}

export interface LampiranLimits {
    max_bytes: number;
    max_total_bytes: number;
    max_files: number;
}

/** What a form holds: the saved files, the ones taken out, and the new ones. */
export interface LampiranDraft {
    existing: LampiranFile[];
    removed: number[];
    added: (LampiranFile & { file: File })[];
}

export const emptyDraft = (existing: LampiranFile[] = []): LampiranDraft => ({ existing, removed: [], added: [] });

export const draftFiles = (d: LampiranDraft): LampiranFile[] => [...d.existing.filter((f) => !d.removed.includes(f.id!)), ...d.added];

/** Each photo, and each page of a PDF, is one nota. */
export const notaCount = (d: LampiranDraft) => draftFiles(d).reduce((n, f) => n + f.halaman, 0);

/** The fields the server reads: lampiran[] with halaman[] (pages of each PDF), hapus_lampiran[]. */
export const draftPayload = (d: LampiranDraft) => ({
    lampiran: d.added.map((a) => a.file),
    halaman: d.added.map((a) => (isPdf(a) ? a.halaman : null)),
    hapus_lampiran: d.removed,
});

export const isPdf = (f: { mime: string }) => f.mime === 'application/pdf';

export function fileSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

let pdfLib: Promise<typeof import('pdfjs-dist')> | null = null;

/** pdf.js, loaded only when a PDF is opened. */
function pdfjs() {
    pdfLib ??= Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?url')]).then(([lib, worker]) => {
        lib.GlobalWorkerOptions.workerSrc = worker.default;
        return lib;
    });
    return pdfLib;
}

const documents = new Map<string, Promise<PDFDocumentProxy>>();

/** A PDF by its URL, opened once and kept while the page is open. */
export function openPdf(url: string): Promise<PDFDocumentProxy> {
    let doc = documents.get(url);
    if (!doc) {
        doc = pdfjs().then(async (lib) => {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return lib.getDocument({ data: new Uint8Array(await response.arrayBuffer()) }).promise;
        });
        doc.catch(() => documents.delete(url));
        documents.set(url, doc);
    }
    return doc;
}

export async function pdfPageCount(file: File): Promise<number> {
    const lib = await pdfjs();
    const task = lib.getDocument({ data: new Uint8Array(await file.arrayBuffer()) });
    const pages = (await task.promise).numPages;
    await task.destroy();
    return pages;
}

/**
 * Phone photos are often several MB: anything larger than ~1.5 MB or 2400 px is
 * scaled to 2000 px on its long side and saved as JPEG, still sharp enough to read a nota.
 */
export async function shrinkImage(file: File): Promise<File> {
    if (!file.type.startsWith('image/')) return file;
    try {
        const bitmap = await createImageBitmap(file);
        const long = Math.max(bitmap.width, bitmap.height);
        if (file.size <= 1.5 * 1024 * 1024 && long <= 2400) {
            bitmap.close();
            return file;
        }
        const scale = Math.min(1, 2000 / long);
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d')!;
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob || blob.size >= file.size) return file;
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}
