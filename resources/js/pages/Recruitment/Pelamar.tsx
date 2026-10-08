import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRightCircle,
    Ban,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    FileText,
    Inbox,
    Loader2,
    Mail,
    Phone,
    RotateCcw,
    Search,
    Trash2,
} from 'lucide-react';
import { AttendanceHeader, Modal, ghostButton, inputClass, primaryButton, secondaryButton, smallInput } from '@/components/attendance/ui';
import { Jawaban } from '@/components/recruitment/Jawaban';
import { tint, type Jawaban as JawabanItem } from '@/lib/recruitment';

interface Row {
    id: number;
    nama: string;
    email: string | null;
    no_hp: string | null;
    posisi: string | null;
    form: { id: number; title: string } | null;
    submitted_at: string | null;
    status: 'baru' | 'diproses' | 'ditolak';
    cv: { nama: string; url: string } | null;
    tahap: { title: string | null; color: string | null; ditolak: boolean } | null;
}

interface Detail extends Row {
    jawaban: JawabanItem[];
    catatan: string | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Props {
    applicants: Paginated<Row>;
    filters: { status: string; form: string | number; q: string };
    counts: Record<string, number>;
    forms: { id: number; title: string }[];
    stages: { id: number; title: string; color: string | null }[];
    can_edit: boolean;
}

const TABS = [
    ['baru', 'Baru'],
    ['diproses', 'Di Tracking'],
    ['ditolak', 'Ditolak'],
    ['semua', 'Semua'],
] as const;

const visit = { preserveScroll: true, preserveState: true } as const;

function waktu(iso: string | null): string {
    if (!iso) return '-';
    const d = new Date(iso);
    const days = Math.floor((Date.now() - d.getTime()) / 86400000);
    const date = d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    return days <= 0
        ? `Hari ini, ${d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}`
        : days === 1
          ? `Kemarin · ${date}`
          : `${date}`;
}

/** Pelamar: applications from the public forms, read and passed on to Tracking (or turned down). */
export default function Pelamar({ applicants, filters, counts, forms, stages, can_edit }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [open, setOpen] = useState<number | null>(() => Number(new URLSearchParams(window.location.search).get('lihat')) || null);
    const first = useRef(true);

    const apply = (next: Partial<Props['filters']>) => {
        const m = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (m.status && m.status !== 'baru') query.status = String(m.status);
        if (m.form) query.form = String(m.form);
        if (m.q.trim()) query.q = m.q.trim();
        router.get('/recruitment/pelamar', query, { ...visit, replace: true });
    };
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => apply({ q: search }), 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const total = (k: string) => (k === 'semua' ? Object.values(counts).reduce((a, b) => a + b, 0) : (counts[k] ?? 0));
    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <>
            <Head title="Pelamar" />
            <AttendanceHeader title="Pelamar" subtitle="Lamaran yang masuk lewat form lowongan publik">
                <a href="/lowongan" target="_blank" rel="noreferrer" className={secondaryButton}>
                    <ExternalLink size={16} /> Halaman lowongan
                </a>
            </AttendanceHeader>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-4 sm:px-5">
                        <div className="flex flex-wrap rounded-xl bg-slate-100 p-1">
                            {TABS.map(([value, label]) => (
                                <button
                                    key={value}
                                    onClick={() => apply({ status: value })}
                                    className={`flex items-center gap-1.5 rounded-lg px-3 py-1 text-sm font-bold ${filters.status === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                                >
                                    {label}
                                    <span
                                        className={`rounded-full px-1.5 text-[10px] ${filters.status === value ? 'bg-white/20' : value === 'baru' && total('baru') ? 'bg-rose-500 text-white' : 'bg-slate-200'}`}
                                    >
                                        {total(value)}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <select
                            value={filters.form}
                            onChange={(e) => apply({ form: e.target.value })}
                            className={smallInput}
                            aria-label="Form lowongan"
                        >
                            <option value="">Semua lowongan</option>
                            {forms.map((f) => (
                                <option key={f.id} value={f.id}>
                                    {f.title}
                                </option>
                            ))}
                        </select>
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama / email / HP"
                                className={`${smallInput} w-56 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className={`${th} pl-5`}>Pelamar</th>
                                    <th className={th}>Lowongan</th>
                                    <th className={th}>Kontak</th>
                                    <th className={th}>Masuk</th>
                                    <th className={th}>Status</th>
                                    <th className={`${th} pr-5 text-right`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {applicants.data.map((a) => (
                                    <tr key={a.id} className="cursor-pointer hover:bg-slate-50/70" onClick={() => setOpen(a.id)}>
                                        <td className="py-3 pl-5">
                                            <div className="flex items-center gap-3">
                                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-xs font-black text-brand-600 uppercase">
                                                    {a.nama.charAt(0)}
                                                </span>
                                                <div className="min-w-0">
                                                    <p className="truncate font-bold text-slate-800">{a.nama}</p>
                                                    <p className="truncate text-[11px] text-slate-500">{a.posisi ?? '-'}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-3 py-3 text-xs text-slate-600">{a.form?.title ?? '-'}</td>
                                        <td className="px-3 py-3 text-xs text-slate-500">
                                            {a.email && (
                                                <p className="flex items-center gap-1.5">
                                                    <Mail size={12} /> {a.email}
                                                </p>
                                            )}
                                            {a.no_hp && (
                                                <p className="flex items-center gap-1.5">
                                                    <Phone size={12} /> {a.no_hp}
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-xs whitespace-nowrap text-slate-500">{waktu(a.submitted_at)}</td>
                                        <td className="px-3 py-3">
                                            <StatusBadge row={a} />
                                        </td>
                                        <td className="py-3 pr-5 text-right" onClick={(e) => e.stopPropagation()}>
                                            <div className="flex justify-end gap-1">
                                                {a.cv && (
                                                    <a
                                                        href={a.cv.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        title="Buka CV"
                                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                    >
                                                        <FileText size={16} />
                                                    </a>
                                                )}
                                                {can_edit && a.status === 'baru' && (
                                                    <button
                                                        onClick={() => setOpen(a.id)}
                                                        className="flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-brand-700"
                                                    >
                                                        <ArrowRightCircle size={14} /> Proses
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {applicants.data.length === 0 && (
                            <div className="flex flex-col items-center py-16 text-center text-slate-400">
                                <Inbox size={36} className="mb-2 text-slate-200" />
                                <p className="text-sm font-bold text-slate-500">Tidak ada lamaran di sini</p>
                                <p className="text-xs">Lamaran dari halaman lowongan masuk ke tab Baru.</p>
                            </div>
                        )}
                    </div>

                    {applicants.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                            <span>
                                {applicants.from}–{applicants.to} dari {applicants.total}
                            </span>
                            <div className="flex gap-1">
                                <PageLink url={applicants.prev_page_url} label="Sebelumnya" icon={ChevronLeft} />
                                <PageLink url={applicants.next_page_url} label="Berikutnya" icon={ChevronRight} />
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {open !== null && <DetailModal id={open} stages={stages} canEdit={can_edit} onClose={() => setOpen(null)} />}
        </>
    );
}

function PageLink({ url, label, icon: Icon }: { url: string | null; label: string; icon: typeof ChevronLeft }) {
    return url ? (
        <Link href={url} preserveScroll className="rounded-lg border border-slate-200 p-1.5 hover:bg-slate-50" aria-label={label}>
            <Icon size={15} />
        </Link>
    ) : (
        <span className="rounded-lg border border-slate-100 p-1.5 text-slate-300">
            <Icon size={15} />
        </span>
    );
}

function StatusBadge({ row }: { row: Row }) {
    if (row.status === 'diproses' && row.tahap) {
        return row.tahap.ditolak ? (
            <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-600">Tidak dilanjutkan</span>
        ) : (
            <span className="rounded-full px-2 py-0.5 text-[11px] font-bold" style={tint(row.tahap.color)}>
                {row.tahap.title}
            </span>
        );
    }
    if (row.status === 'ditolak') return <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">Ditolak</span>;
    return <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700">Baru</span>;
}

function DetailModal({ id, stages, canEdit, onClose }: { id: number; stages: Props['stages']; canEdit: boolean; onClose: () => void }) {
    const [d, setD] = useState<Detail | null>(null);
    const [mode, setMode] = useState<'lihat' | 'tracking' | 'tolak'>('lihat');
    const [stageId, setStageId] = useState(String(stages[0]?.id ?? ''));
    const [posisi, setPosisi] = useState('');
    const [catatan, setCatatan] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        fetch(`/recruitment/pelamar/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data: Detail) => {
                setD(data);
                setPosisi(data.posisi ?? '');
            });
    }, [id]);

    const done = { ...visit, onFinish: () => setBusy(false), onSuccess: onClose };
    const toTracking = () => {
        setBusy(true);
        router.post(`/recruitment/pelamar/${id}/tracking`, { stage_id: stageId, position: posisi }, done);
    };
    const reject = (tolak: boolean) => {
        setBusy(true);
        router.post(`/recruitment/pelamar/${id}/tolak`, { tolak, catatan }, done);
    };
    const destroy = () =>
        d && confirm(`Hapus lamaran ${d.nama} beserta file-nya?`) && router.delete(`/recruitment/pelamar/${id}`, { ...visit, onSuccess: onClose });

    const footer =
        d && canEdit ? (
            mode === 'tracking' ? (
                <>
                    <button onClick={() => setMode('lihat')} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={toTracking} disabled={busy || !posisi.trim()} className={primaryButton}>
                        {busy ? <Loader2 size={16} className="animate-spin" /> : <ArrowRightCircle size={16} />} Masukkan ke Tracking
                    </button>
                </>
            ) : mode === 'tolak' ? (
                <>
                    <button onClick={() => setMode('lihat')} className={ghostButton}>
                        Batal
                    </button>
                    <button
                        onClick={() => reject(true)}
                        disabled={busy}
                        className="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700 disabled:opacity-50"
                    >
                        <Ban size={16} /> Tolak lamaran
                    </button>
                </>
            ) : (
                <>
                    {d.status !== 'diproses' && (
                        <button
                            onClick={destroy}
                            className="mr-auto rounded-xl p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                            title="Hapus lamaran"
                            aria-label="Hapus lamaran"
                        >
                            <Trash2 size={17} />
                        </button>
                    )}
                    {d.status === 'baru' && (
                        <>
                            <button onClick={() => setMode('tolak')} className={secondaryButton}>
                                <Ban size={16} /> Tolak
                            </button>
                            <button onClick={() => setMode('tracking')} className={primaryButton}>
                                <ArrowRightCircle size={16} /> Proses ke Tracking
                            </button>
                        </>
                    )}
                    {d.status === 'ditolak' && (
                        <button onClick={() => reject(false)} disabled={busy} className={secondaryButton}>
                            <RotateCcw size={16} /> Kembalikan ke Baru
                        </button>
                    )}
                    {d.status === 'diproses' && (
                        <Link href="/recruitment/tracking" className={primaryButton}>
                            Lihat di Tracking
                        </Link>
                    )}
                </>
            )
        ) : undefined;

    return (
        <Modal
            title={d?.nama ?? 'Memuat…'}
            subtitle={d ? `${d.form?.title ?? ''} · ${waktu(d.submitted_at)}` : undefined}
            onClose={onClose}
            width="max-w-2xl"
            footer={footer}
        >
            {!d ? (
                <p className="flex items-center justify-center gap-2 py-10 text-sm text-slate-400">
                    <Loader2 size={16} className="animate-spin" /> Memuat…
                </p>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge row={d} />
                        {d.catatan && <span className="text-xs text-slate-500">Catatan: {d.catatan}</span>}
                    </div>
                    {mode === 'tracking' && (
                        <div className="grid gap-3 rounded-2xl border border-brand-100 bg-brand-50/50 p-3 sm:grid-cols-2">
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-bold text-slate-500">Masuk ke tahap</span>
                                <select value={stageId} onChange={(e) => setStageId(e.target.value)} className={inputClass}>
                                    {stages.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-bold text-slate-500">Posisi</span>
                                <input value={posisi} onChange={(e) => setPosisi(e.target.value)} className={inputClass} />
                            </label>
                        </div>
                    )}
                    {mode === 'tolak' && (
                        <label className="block rounded-2xl border border-rose-100 bg-rose-50/50 p-3">
                            <span className="mb-1 block text-[11px] font-bold text-slate-500">Alasan (opsional)</span>
                            <input
                                value={catatan}
                                onChange={(e) => setCatatan(e.target.value)}
                                maxLength={255}
                                placeholder="mis. kualifikasi belum sesuai"
                                className={inputClass}
                                autoFocus
                            />
                        </label>
                    )}
                    <Jawaban items={d.jawaban} />
                </div>
            )}
        </Modal>
    );
}
