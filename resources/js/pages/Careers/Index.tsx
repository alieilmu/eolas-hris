import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Briefcase, CalendarClock, ChevronRight, MapPin, Search } from 'lucide-react';
import { useBranding } from '@/components/BrandLogo';
import { PublicShell } from '@/components/recruitment/PublicShell';
import { fmtDate } from '@/lib/format';

interface Vacancy {
    id: number;
    title: string;
    description: string | null;
    position_title: string | null;
    location: string | null;
    closing_date: string | null;
    opened: string | null;
}

/** Lowongan: the open vacancies, for anyone. */
export default function LowonganIndex({ forms }: { forms: Vacancy[] }) {
    const b = useBranding();
    const [q, setQ] = useState('');
    const s = q.trim().toLowerCase();
    const list = forms.filter((f) => !s || [f.title, f.position_title, f.location, f.description].some((v) => v?.toLowerCase().includes(s)));

    return (
        <PublicShell>
            <Head title="Lowongan Kerja" />
            <section className="bg-brand-700 px-4 pt-12 pb-24 text-center text-white">
                <p className="mx-auto mb-4 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold tracking-wide uppercase">
                    <Briefcase size={13} /> Karier di {b.company_name ?? b.app_name}
                </p>
                <h1 className="text-3xl font-black sm:text-5xl">Bergabung bersama kami</h1>
                <p className="mx-auto mt-3 max-w-xl text-sm text-brand-100 sm:text-base">
                    Lihat lowongan yang sedang dibuka, lalu kirim lamaran Anda langsung dari halaman ini.
                </p>
            </section>

            <div className="mx-auto -mt-14 max-w-4xl px-4 pb-16">
                <div className="relative mb-6 rounded-3xl bg-white p-3 shadow-xl">
                    <Search size={18} className="absolute top-1/2 left-7 -translate-y-1/2 text-slate-300" />
                    <input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Cari posisi atau lokasi…"
                        aria-label="Cari lowongan"
                        className="w-full rounded-2xl border border-slate-100 bg-slate-50 py-3 pr-4 pl-11 text-sm outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10"
                    />
                </div>

                <h2 className="mb-3 px-1 text-sm font-bold text-slate-500">{list.length} lowongan dibuka</h2>
                <div className="space-y-3">
                    {list.map((f) => (
                        <Link
                            key={f.id}
                            href={`/careers/${f.id}`}
                            className="group flex items-center gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-brand-200 hover:shadow-lg sm:p-6"
                        >
                            <div className="min-w-0 flex-1">
                                <h3 className="text-lg font-black text-slate-800 group-hover:text-brand-700 sm:text-xl">{f.title}</h3>
                                <p className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                    {f.position_title && <span>{f.position_title}</span>}
                                    {f.location && (
                                        <span className="flex items-center gap-1">
                                            <MapPin size={13} className="text-brand-400" /> {f.location}
                                        </span>
                                    )}
                                    {f.closing_date && (
                                        <span className="flex items-center gap-1">
                                            <CalendarClock size={13} className="text-brand-400" /> Hingga {fmtDate(f.closing_date)}
                                        </span>
                                    )}
                                </p>
                                {f.description && <p className="mt-2 line-clamp-2 text-sm text-slate-500">{f.description}</p>}
                            </div>
                            <span className="rounded-2xl bg-slate-50 p-3 text-slate-400 group-hover:bg-brand-600 group-hover:text-white">
                                <ChevronRight size={20} />
                            </span>
                        </Link>
                    ))}
                    {list.length === 0 && (
                        <div className="rounded-3xl border border-slate-200 bg-white p-12 text-center">
                            <Search size={28} className="mx-auto text-slate-200" />
                            <p className="mt-2 font-bold text-slate-700">
                                {forms.length ? 'Tidak ada yang cocok' : 'Belum ada lowongan yang dibuka'}
                            </p>
                            <p className="text-sm text-slate-400">{forms.length ? 'Coba kata kunci lain.' : 'Silakan kembali lagi nanti.'}</p>
                        </div>
                    )}
                </div>
            </div>
        </PublicShell>
    );
}
