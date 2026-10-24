import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import {
    ArrowRight,
    BadgeCheck,
    CheckCircle2,
    Database,
    FlaskConical,
    LogIn,
    Rocket,
    ShieldCheck,
    Sparkles,
    UserPlus,
    Workflow,
} from 'lucide-react';
import { EolasMark } from '@/components/EolasMark';
import { GUIDE_MODULES } from '@/constants/guide';

const STEPS = [
    { icon: UserPlus, title: 'Ajukan akun demo', desc: 'Isi form singkat: nama, email, perusahaan, dan pilih sumber data.' },
    { icon: Rocket, title: 'Workspace disiapkan', desc: 'Sistem membuat workspace khusus untuk Anda dalam hitungan detik.' },
    { icon: BadgeCheck, title: 'Coba semua modul', desc: 'Masuk sebagai Super Admin dan jelajahi semua fitur selama masa demo.' },
];

export default function Landing({ demo_days }: { demo_days: number }) {
    const [active, setActive] = useState(GUIDE_MODULES[1].id);
    const mod = GUIDE_MODULES.find((m) => m.id === active) ?? GUIDE_MODULES[0];
    const ModIcon = mod.icon;

    return (
        <div className="min-h-screen bg-white text-slate-800">
            <Head title="HRIS Modern untuk Tim Anda" />

            {/* Nav */}
            <header className="sticky top-0 z-30 border-b border-slate-100 bg-white/85 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
                    <EolasMark />
                    <nav className="hidden items-center gap-7 text-sm font-semibold text-slate-500 md:flex">
                        <a href="#modul" className="hover:text-brand-700">
                            Modul
                        </a>
                        <a href="#data" className="hover:text-brand-700">
                            Pilihan Data
                        </a>
                        <a href="#cara" className="hover:text-brand-700">
                            Cara Kerja
                        </a>
                        <a href="/lowongan" className="hover:text-brand-700">
                            Lowongan
                        </a>
                    </nav>
                    <div className="flex items-center gap-2">
                        <Link href="/login" className="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-brand-700 hover:bg-brand-50">
                            <LogIn size={16} /> Masuk
                        </Link>
                        <Link
                            href="/demo"
                            className="hidden items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-md shadow-brand-600/25 hover:bg-brand-700 sm:flex"
                        >
                            Coba Gratis
                        </Link>
                    </div>
                </div>
            </header>

            {/* Hero */}
            <section className="relative overflow-hidden">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_60%_at_80%_0%,var(--color-brand-100),transparent),radial-gradient(40%_50%_at_0%_100%,var(--color-accent-100),transparent)]" />
                <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 md:py-24 lg:grid-cols-2">
                    <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.5 }}>
                        <span className="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white px-3 py-1 text-xs font-bold text-brand-700">
                            <Sparkles size={14} /> Human Capital Management System
                        </span>
                        <h1 className="mt-5 text-4xl leading-[1.1] font-black tracking-tight text-slate-900 sm:text-5xl">
                            Kelola SDM dari <span className="text-brand-600">rekrutmen</span> sampai <span className="text-brand-600">benefit</span> dalam satu
                            sistem.
                        </h1>
                        <p className="mt-5 max-w-xl text-base leading-relaxed text-slate-500">
                            Eolas HRIS menyatukan recruitment, data karyawan, struktur organisasi, man power planning, roster & absensi, cuti, dinas, dan
                            klaim benefit. Cocok untuk kantor pusat maupun operasional site.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link
                                href="/demo"
                                className="flex items-center gap-2 rounded-2xl bg-brand-600 px-6 py-3.5 text-sm font-bold text-white shadow-xl shadow-brand-600/25 transition hover:-translate-y-0.5 hover:bg-brand-700"
                            >
                                Ajukan Akun Demo <ArrowRight size={16} />
                            </Link>
                            <a
                                href="#modul"
                                className="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-6 py-3.5 text-sm font-bold text-slate-700 hover:border-brand-300 hover:text-brand-700"
                            >
                                Lihat Modul
                            </a>
                        </div>
                        <div className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-xs font-semibold text-slate-500">
                            {[`Gratis ${demo_days} hari`, 'Workspace terpisah', 'Data demo atau data Anda sendiri'].map((t) => (
                                <span key={t} className="flex items-center gap-1.5">
                                    <CheckCircle2 size={15} className="text-brand-500" /> {t}
                                </span>
                            ))}
                        </div>
                    </motion.div>

                    {/* Mock dashboard */}
                    <motion.div
                        initial={{ opacity: 0, scale: 0.96 }}
                        animate={{ opacity: 1, scale: 1 }}
                        transition={{ duration: 0.6, delay: 0.1 }}
                        className="relative"
                    >
                        <div className="rounded-3xl border border-brand-100 bg-white p-5 shadow-2xl shadow-brand-900/10">
                            <div className="mb-4 flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-bold text-slate-400">Dashboard</p>
                                    <p className="text-lg font-black text-slate-800">Selamat pagi, Tim HR 👋</p>
                                </div>
                                <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">Semua lokasi</span>
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    ['Karyawan aktif', '1.248'],
                                    ['Onsite hari ini', '86%'],
                                    ['Pengajuan', '12'],
                                ].map(([l, v]) => (
                                    <div key={l} className="rounded-2xl bg-gradient-to-br from-brand-50 to-white p-3">
                                        <p className="text-[11px] font-semibold text-slate-500">{l}</p>
                                        <p className="mt-1 text-xl font-black text-brand-700">{v}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-4 rounded-2xl border border-slate-100 p-3">
                                <p className="mb-2 text-xs font-bold text-slate-500">Man Onsite 30 hari</p>
                                <div className="flex h-28 items-end gap-1">
                                    {Array.from({ length: 30 }, (_, i) => {
                                        const h = 55 + Math.round(30 * Math.abs(Math.sin(i / 3.2)));
                                        return (
                                            <motion.div
                                                key={i}
                                                initial={{ height: 0 }}
                                                animate={{ height: `${h}%` }}
                                                transition={{ delay: 0.3 + i * 0.02 }}
                                                className="flex flex-1 flex-col justify-end overflow-hidden rounded-t"
                                            >
                                                <div className="bg-accent-300" style={{ height: '18%' }} />
                                                <div className="flex-1 bg-brand-500" />
                                            </motion.div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                        <div className="absolute -bottom-6 -left-4 hidden rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-xl sm:block">
                            <p className="flex items-center gap-2 text-xs font-bold text-slate-700">
                                <ShieldCheck size={16} className="text-brand-600" /> Akses per menu & per lokasi
                            </p>
                        </div>
                    </motion.div>
                </div>
            </section>

            {/* Modules */}
            <section id="modul" className="scroll-mt-16 bg-slate-50/70 py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="mx-auto max-w-2xl text-center">
                        <p className="text-xs font-black tracking-widest text-brand-600 uppercase">Modul & Fitur</p>
                        <h2 className="mt-2 text-3xl font-black tracking-tight text-slate-900">Semua proses HR dalam satu tempat</h2>
                        <p className="mt-3 text-slate-500">Klik sebuah modul untuk melihat fitur-fiturnya.</p>
                    </div>

                    <div className="mt-10 flex gap-2 overflow-x-auto pb-2 lg:justify-center">
                        {GUIDE_MODULES.map((m) => {
                            const Icon = m.icon;
                            const on = m.id === active;
                            return (
                                <button
                                    key={m.id}
                                    onClick={() => setActive(m.id)}
                                    className={`flex shrink-0 items-center gap-2 rounded-2xl px-4 py-2.5 text-sm font-bold transition ${
                                        on ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/25' : 'bg-white text-slate-600 hover:text-brand-700'
                                    }`}
                                >
                                    <Icon size={16} /> {m.name}
                                </button>
                            );
                        })}
                    </div>

                    <AnimatePresence mode="wait">
                        <motion.div
                            key={mod.id}
                            initial={{ opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: -8 }}
                            transition={{ duration: 0.25 }}
                            className="mt-8 grid gap-6 rounded-3xl border border-slate-100 bg-white p-6 shadow-sm md:p-8 lg:grid-cols-[1fr_1.4fr]"
                        >
                            <div>
                                <span className={`flex h-12 w-12 items-center justify-center rounded-2xl ${mod.tone}`}>
                                    <ModIcon size={24} />
                                </span>
                                <h3 className="mt-4 text-2xl font-black text-slate-900">{mod.name}</h3>
                                <p className="mt-1 text-sm font-bold text-brand-600">{mod.tagline}</p>
                                <p className="mt-4 text-sm leading-relaxed text-slate-500">{mod.desc}</p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {mod.features.map((f, i) => (
                                    <motion.div
                                        key={f.name}
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: i * 0.05 }}
                                        className="rounded-2xl border border-slate-100 bg-gradient-to-br from-white to-brand-50/50 p-4"
                                    >
                                        <p className="flex items-center gap-2 text-sm font-bold text-slate-800">
                                            <CheckCircle2 size={16} className="shrink-0 text-brand-500" /> {f.name}
                                        </p>
                                        <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{f.desc}</p>
                                    </motion.div>
                                ))}
                            </div>
                        </motion.div>
                    </AnimatePresence>
                </div>
            </section>

            {/* Data choice */}
            <section id="data" className="scroll-mt-16 py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="mx-auto max-w-2xl text-center">
                        <p className="text-xs font-black tracking-widest text-brand-600 uppercase">Pilihan Data Demo</p>
                        <h2 className="mt-2 text-3xl font-black tracking-tight text-slate-900">Mulai dengan cara yang Anda suka</h2>
                        <p className="mt-3 text-slate-500">
                            Setiap akun demo mendapat workspace sendiri yang terpisah dari pengguna lain. Sumber datanya bisa diganti kapan saja dari
                            dashboard.
                        </p>
                    </div>
                    <div className="mt-10 grid gap-5 md:grid-cols-2">
                        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 p-8 text-white shadow-xl shadow-brand-700/20">
                            <FlaskConical className="absolute -right-8 -bottom-10 text-white/10" size={130} />
                            <span className="rounded-full bg-white/15 px-3 py-1 text-xs font-bold">Paling cepat</span>
                            <h3 className="mt-4 text-2xl font-black">Data demo sistem</h3>
                            <p className="relative mt-2 text-sm leading-relaxed text-brand-100">
                                Workspace langsung terisi data contoh yang realistis: karyawan di kantor pusat dan dua site, roster & absensi beberapa bulan,
                                cuti, dinas, deklarasi, recruitment, dan MPP. Cocok untuk langsung melihat semua fitur bekerja.
                            </p>
                        </div>
                        <div className="relative overflow-hidden rounded-3xl border-2 border-brand-100 bg-white p-8 shadow-sm">
                            <Database className="absolute -right-8 -bottom-10 text-brand-50" size={130} />
                            <span className="rounded-full bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">Paling relevan</span>
                            <h3 className="mt-4 text-2xl font-black text-slate-900">Data Anda sendiri</h3>
                            <p className="relative mt-2 text-sm leading-relaxed text-slate-500">
                                Workspace dimulai kosong (hanya master dasar). Bangun organisasi Anda dan masukkan karyawan, bisa lewat template import Excel,
                                untuk menguji Eolas dengan kondisi nyata perusahaan Anda.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Steps */}
            <section id="cara" className="scroll-mt-16 bg-slate-50/70 py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="mx-auto max-w-2xl text-center">
                        <p className="text-xs font-black tracking-widest text-brand-600 uppercase">Cara Kerja</p>
                        <h2 className="mt-2 text-3xl font-black tracking-tight text-slate-900">Tiga langkah menuju demo</h2>
                    </div>
                    <div className="mt-10 grid gap-5 md:grid-cols-3">
                        {STEPS.map((s, i) => {
                            const Icon = s.icon;
                            return (
                                <div key={s.title} className="rounded-3xl border border-slate-100 bg-white p-6">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-600 text-white">
                                            <Icon size={20} />
                                        </span>
                                        <span className="text-4xl font-black text-brand-100">0{i + 1}</span>
                                    </div>
                                    <h3 className="mt-4 font-black text-slate-900">{s.title}</h3>
                                    <p className="mt-1.5 text-sm text-slate-500">{s.desc}</p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="py-20">
                <div className="mx-auto max-w-6xl px-4 sm:px-6">
                    <div className="relative overflow-hidden rounded-[2rem] bg-brand-900 px-6 py-14 text-center text-white sm:px-12">
                        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(50%_80%_at_50%_0%,var(--color-brand-600),transparent)] opacity-60" />
                        <div className="relative">
                            <Workflow className="mx-auto text-accent-300" size={36} />
                            <h2 className="mt-4 text-3xl font-black tracking-tight">Siap mencoba Eolas HRIS?</h2>
                            <p className="mx-auto mt-3 max-w-lg text-brand-100">
                                Ajukan akun demo sekarang. Gratis {demo_days} hari, tanpa kartu kredit.
                            </p>
                            <Link
                                href="/demo"
                                className="mt-8 inline-flex items-center gap-2 rounded-2xl bg-white px-6 py-3.5 text-sm font-black text-brand-800 shadow-xl hover:bg-brand-50"
                            >
                                Ajukan Akun Demo <ArrowRight size={16} />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <footer className="border-t border-slate-100 py-8">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 text-xs text-slate-400 sm:flex-row sm:px-6">
                    <EolasMark />
                    <p>© {new Date().getFullYear()} Eolas HRIS · Human Capital Management System</p>
                </div>
            </footer>
        </div>
    );
}
