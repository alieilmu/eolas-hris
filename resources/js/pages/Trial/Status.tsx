import { useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { AlertTriangle, CheckCircle2, Clock, Hourglass, Loader2, LogIn } from 'lucide-react';
import { EolasMark } from '@/components/EolasMark';

interface Account {
    code: string;
    name: string;
    company_name: string;
    email: string;
    status: 'pending' | 'waiting' | 'provisioning' | 'active' | 'inactive' | 'failed' | 'ends' | 'archived';
    status_label: string;
    data_source: 'demo' | 'blank';
    industry_label: string | null;
    source_label: string;
    expires_at: string | null;
    days_left: number | null;
}

const STEPS = ['Membuat database workspace', 'Menyiapkan tabel & master data', 'Mengisi data', 'Membuat akun Super Admin'];

/** Follows a demo request: refreshes while the workspace is being prepared, then offers the sign-in. */
export default function DemoStatus({ account }: { account: Account }) {
    const busy = account.status === 'provisioning';

    useEffect(() => {
        if (!busy) return;
        const timer = setInterval(() => router.reload({ only: ['account'] }), 2500);
        return () => clearInterval(timer);
    }, [busy]);

    return (
        <div className="flex min-h-screen flex-col items-center bg-gradient-to-br from-brand-50 via-white to-accent-50 px-4 py-10">
            <Head title="Status Akun Demo" />
            <Link href="/" className="mb-10">
                <EolasMark size="lg" />
            </Link>

            <motion.div
                initial={{ opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                className="w-full max-w-lg rounded-3xl border border-slate-100 bg-white p-8 text-center shadow-xl shadow-brand-900/5"
            >
                {busy && (
                    <>
                        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-brand-50 text-brand-600">
                            <Loader2 size={30} className="animate-spin" />
                        </span>
                        <h1 className="mt-5 text-xl font-black text-slate-900">Workspace sedang disiapkan…</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            {account.company_name} · {account.source_label}{account.industry_label ? ` (${account.industry_label})` : ''}. Biasanya selesai sekitar 1–2 menit.
                        </p>
                        <ul className="mx-auto mt-6 max-w-xs space-y-2 text-left text-sm">
                            {STEPS.map((s, i) => (
                                <motion.li
                                    key={s}
                                    initial={{ opacity: 0.3 }}
                                    animate={{ opacity: [0.3, 1, 0.3] }}
                                    transition={{ repeat: Infinity, duration: 2.4, delay: i * 0.6 }}
                                    className="flex items-center gap-2 text-slate-600"
                                >
                                    <span className="h-2 w-2 rounded-full bg-brand-500" /> {s}
                                </motion.li>
                            ))}
                        </ul>
                    </>
                )}

                {account.status === 'active' && (
                    <>
                        <motion.span
                            initial={{ scale: 0.5 }}
                            animate={{ scale: 1 }}
                            className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-brand-600 text-white shadow-lg shadow-brand-600/30"
                        >
                            <CheckCircle2 size={32} />
                        </motion.span>
                        <h1 className="mt-5 text-xl font-black text-slate-900">Workspace Anda siap! 🎉</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            Halo {account.name}, workspace <b>{account.company_name}</b> sudah aktif dengan <b>{account.source_label.toLowerCase()}{account.industry_label ? ` ${account.industry_label.toLowerCase()}` : ''}</b>.
                        </p>
                        <dl className="mt-6 grid grid-cols-2 gap-3 text-left text-sm">
                            <div className="rounded-2xl bg-slate-50 p-3">
                                <dt className="text-[11px] font-bold text-slate-400 uppercase">Email masuk</dt>
                                <dd className="mt-0.5 truncate font-bold text-slate-700">{account.email}</dd>
                            </div>
                            <div className="rounded-2xl bg-slate-50 p-3">
                                <dt className="text-[11px] font-bold text-slate-400 uppercase">Kode workspace</dt>
                                <dd className="mt-0.5 font-mono font-bold text-brand-700">{account.code}</dd>
                            </div>
                        </dl>
                        <p className="mt-3 text-xs text-slate-400">
                            Simpan kode workspace untuk login karyawan dengan NRP.
                            {account.days_left !== null && ` Masa demo tersisa ${account.days_left} hari.`}
                        </p>
                        <Link
                            href={`/login?ws=${account.code}`}
                            className="mt-6 flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-5 py-3.5 text-sm font-black text-white shadow-lg shadow-brand-600/25 hover:bg-brand-700"
                        >
                            <LogIn size={16} /> Masuk ke Workspace
                        </Link>
                    </>
                )}

                {account.status === 'pending' && (
                    <>
                        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-accent-100 text-brand-700">
                            <Hourglass size={30} />
                        </span>
                        <h1 className="mt-5 text-xl font-black text-slate-900">Pengajuan diterima</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            Terima kasih {account.name}. Pengajuan untuk <b>{account.company_name}</b> sedang ditinjau admin Eolas. Setelah disetujui, Anda
                            bisa masuk dengan email <b>{account.email}</b> dan password yang Anda buat.
                        </p>
                        <p className="mt-4 text-xs text-slate-400">
                            Simpan alamat halaman ini untuk mengecek status: /demo/status/{account.code}
                        </p>
                    </>
                )}

                {account.status === 'waiting' && (
                    <>
                        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-accent-100 text-brand-700">
                            <Hourglass size={30} />
                        </span>
                        <h1 className="mt-5 text-xl font-black text-slate-900">Anda di daftar tunggu</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            Terima kasih {account.name}. Banyak perusahaan sedang mencoba Eolas, jadi workspace <b>{account.company_name}</b> disiapkan
                            otomatis begitu ada tempat, biasanya dalam beberapa jam. Setelah itu Anda bisa masuk dengan email <b>{account.email}</b> dan
                            password yang Anda buat.
                        </p>
                        <p className="mt-4 text-xs text-slate-400">
                            Simpan alamat halaman ini untuk mengecek status: /demo/status/{account.code}
                        </p>
                    </>
                )}

                {['failed', 'inactive', 'ends', 'archived'].includes(account.status) && (
                    <>
                        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-rose-50 text-rose-500">
                            {account.status === 'ends' || account.status === 'archived' ? <Clock size={30} /> : <AlertTriangle size={30} />}
                        </span>
                        <h1 className="mt-5 text-xl font-black text-slate-900">{account.status_label}</h1>
                        <p className="mt-2 text-sm text-slate-500">Silakan hubungi admin Eolas untuk bantuan.</p>
                    </>
                )}
            </motion.div>

            <Link href="/" className="mt-6 text-sm font-bold text-slate-500 hover:text-brand-700">
                Kembali ke beranda
            </Link>
        </div>
    );
}
