import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Check, Copy, CopyPlus, ExternalLink, FilePlus2, Globe, Link2, Lock, MapPin, Pencil, Trash2, Users } from 'lucide-react';
import { AttendanceHeader, primaryButton, secondaryButton } from '@/components/time/ui';
import { fmtDate } from '@/lib/format';

interface FormCard {
    id: number;
    title: string;
    description: string | null;
    position_title: string | null;
    location: string | null;
    closing_date: string | null;
    is_open: boolean;
    accepting: boolean;
    fields_count: number;
    applicants_count: number;
    new_count: number;
    created_at: string | null;
    url: string;
}

const visit = { preserveScroll: true, preserveState: true } as const;

/** Pengaturan Form: each vacancy's application form, opened, shared and edited from here. */
export default function JobPostings({ forms, careers_url, can_edit }: { forms: FormCard[]; careers_url: string; can_edit: boolean }) {
    const [copied, setCopied] = useState<number | 'all' | null>(null);
    const copy = (url: string, key: number | 'all') =>
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(key);
            setTimeout(() => setCopied(null), 1800);
        });

    return (
        <>
            <Head title="Lowongan" />
            <AttendanceHeader title="Lowongan" subtitle="Form lamaran tiap lowongan; yang dibuka tampil di halaman lowongan publik">
                <button onClick={() => copy(careers_url, 'all')} className={secondaryButton} title={careers_url}>
                    {copied === 'all' ? <Check size={16} /> : <Link2 size={16} />} {copied === 'all' ? 'Tersalin' : 'Salin tautan lowongan'}
                </button>
                {can_edit && (
                    <button onClick={() => router.post('/recruitment/job-postings')} className={primaryButton}>
                        <FilePlus2 size={16} /> Form baru
                    </button>
                )}
            </AttendanceHeader>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                    {forms.map((f) => (
                        <article key={f.id} className="flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                            <div className="flex-1 p-5">
                                <div className="mb-3 flex items-center gap-2">
                                    <span
                                        className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold tracking-wide uppercase ${
                                            f.accepting
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : f.is_open
                                                  ? 'bg-amber-50 text-amber-700'
                                                  : 'bg-slate-100 text-slate-500'
                                        }`}
                                    >
                                        {f.accepting ? <Globe size={11} /> : <Lock size={11} />}
                                        {f.accepting ? 'Menerima lamaran' : f.is_open ? 'Lewat batas' : 'Ditutup'}
                                    </span>
                                    {can_edit && (
                                        <div className="ml-auto flex">
                                            <Link
                                                href={`/recruitment/job-postings/${f.id}`}
                                                title="Ubah form"
                                                aria-label="Ubah form"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                            >
                                                <Pencil size={16} />
                                            </Link>
                                            <button
                                                onClick={() => router.post(`/recruitment/job-postings/${f.id}/duplicate`)}
                                                title="Duplikat jadi form baru"
                                                aria-label="Duplikat form"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                                            >
                                                <CopyPlus size={16} />
                                            </button>
                                            <button
                                                onClick={() => confirm(`Hapus form ${f.title}?`) && router.delete(`/recruitment/job-postings/${f.id}`, visit)}
                                                disabled={f.applicants_count > 0}
                                                title={f.applicants_count > 0 ? 'Sudah ada lamaran; tutup saja' : 'Hapus form'}
                                                aria-label="Hapus form"
                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </div>
                                    )}
                                </div>
                                <h3 className="text-lg leading-tight font-bold text-slate-800">{f.title}</h3>
                                <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
                                    {f.position_title && <span>{f.position_title}</span>}
                                    {f.location && (
                                        <span className="flex items-center gap-1">
                                            <MapPin size={12} /> {f.location}
                                        </span>
                                    )}
                                </p>
                                <p className="mt-3 line-clamp-2 text-sm text-slate-500">{f.description || 'Tanpa deskripsi.'}</p>
                                <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-400">
                                    <span>{f.fields_count} pertanyaan</span>
                                    <Link
                                        href={`/recruitment/applicants?status=all&form=${f.id}`}
                                        className="flex items-center gap-1 hover:text-brand-600"
                                    >
                                        <Users size={12} /> {f.applicants_count} pelamar
                                        {f.new_count > 0 && <b className="rounded-full bg-rose-500 px-1.5 text-white">{f.new_count} baru</b>}
                                    </Link>
                                    {f.closing_date && (
                                        <span className="flex items-center gap-1">
                                            <CalendarClock size={12} /> s/d {fmtDate(f.closing_date)}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center gap-2 border-t border-slate-100 bg-slate-50/60 p-3">
                                {can_edit && (
                                    <button
                                        onClick={() => router.post(`/recruitment/job-postings/${f.id}/toggle`, {}, visit)}
                                        className={`flex-1 rounded-xl py-2 text-xs font-bold ${f.is_open ? 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-100' : 'bg-emerald-600 text-white hover:bg-emerald-700'}`}
                                    >
                                        {f.is_open ? 'Tutup form' : 'Buka form'}
                                    </button>
                                )}
                                <button
                                    onClick={() => copy(f.url, f.id)}
                                    title="Salin tautan form"
                                    className="rounded-xl border border-slate-200 bg-white p-2 text-slate-500 hover:bg-slate-100"
                                >
                                    {copied === f.id ? <Check size={15} className="text-emerald-600" /> : <Copy size={15} />}
                                </button>
                                <a
                                    href={f.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    title="Buka halaman form"
                                    className="rounded-xl border border-slate-200 bg-white p-2 text-slate-500 hover:bg-slate-100"
                                >
                                    <ExternalLink size={15} />
                                </a>
                            </div>
                        </article>
                    ))}
                    {can_edit && (
                        <button
                            onClick={() => router.post('/recruitment/job-postings')}
                            className="flex min-h-56 flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-200 p-8 text-slate-400 hover:border-brand-300 hover:bg-white hover:text-brand-600"
                        >
                            <FilePlus2 size={28} className="mb-2" />
                            <span className="font-bold">Buat form baru</span>
                            <span className="text-xs">Sudah berisi pertanyaan standar (nama, email, HP, CV)</span>
                        </button>
                    )}
                </div>
            </div>
        </>
    );
}
