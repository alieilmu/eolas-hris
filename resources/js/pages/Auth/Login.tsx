import { useState } from 'react';
import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Briefcase, FlaskConical, Loader2, LogIn } from 'lucide-react';
import { LoginMark, useBranding } from '@/components/BrandLogo';

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

export default function Login({ workspace }: { workspace: string | null }) {
    const branding = useBranding();
    const [showWorkspace, setShowWorkspace] = useState(!!workspace);

    return (
        <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-50 via-slate-50 to-accent-50 p-6">
            <Head title="Masuk" />

            <div className="w-full max-w-sm">
                <div className="mb-8 flex flex-col items-center gap-3">
                    {branding.logo_full_url ? (
                        <img
                            src={branding.logo_full_url}
                            alt={[branding.company_name, branding.tagline].filter(Boolean).join(' — ') || branding.app_name}
                            className="max-h-28 w-60 object-contain"
                        />
                    ) : (
                        <LoginMark b={branding} />
                    )}
                    <span className="rounded-full bg-brand-600 px-3 py-1 text-xs font-bold tracking-wide text-white">{branding.app_name}</span>
                </div>

                <Form
                    action="/login"
                    method="post"
                    resetOnError={['password']}
                    className="space-y-5 rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-8 shadow-sm"
                >
                    {({ errors, processing }) => (
                        <>
                            <div>
                                <h1 className="text-lg font-bold text-slate-800">Masuk</h1>
                                <p className="mt-1 text-xs text-slate-400">Admin: email. Karyawan: NRP (password awal tanggal lahir ddmmyyyy).</p>
                                {workspace && (
                                    <p className="mt-2 rounded-lg bg-brand-50 px-2.5 py-1.5 text-xs font-semibold text-brand-700">
                                        Masuk ke workspace demo <span className="font-mono">{workspace}</span>
                                    </p>
                                )}
                            </div>

                            {(errors.email || errors.password) && (
                                <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                                    {errors.email || errors.password}
                                </div>
                            )}

                            <div>
                                <label htmlFor="email" className="mb-1.5 block text-xs font-bold text-slate-500">
                                    Email atau NRP
                                </label>
                                <input
                                    id="email"
                                    name="email"
                                    type="text"
                                    autoComplete="username"
                                    autoFocus
                                    required
                                    placeholder="Email atau NRP"
                                    className={inputClass}
                                />
                            </div>

                            <div>
                                <label htmlFor="password" className="mb-1.5 block text-xs font-bold text-slate-500">
                                    Password
                                </label>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    className={inputClass}
                                />
                            </div>

                            {showWorkspace ? (
                                <div>
                                    <label htmlFor="workspace" className="mb-1.5 block text-xs font-bold text-slate-500">
                                        Kode workspace demo
                                    </label>
                                    <input
                                        id="workspace"
                                        name="workspace"
                                        type="text"
                                        defaultValue={workspace ?? ''}
                                        maxLength={16}
                                        placeholder="mis. ab12cd"
                                        className={`${inputClass} font-mono`}
                                    />
                                    <p className="mt-1 text-[11px] text-slate-400">Wajib untuk login karyawan (NRP) di workspace demo.</p>
                                </div>
                            ) : (
                                <button type="button" onClick={() => setShowWorkspace(true)} className="text-xs font-bold text-brand-600 hover:underline">
                                    Login karyawan di workspace demo? Isi kode workspace
                                </button>
                            )}

                            <label className="flex items-center gap-2 text-xs font-medium text-slate-500">
                                <input type="checkbox" name="remember" value="1" className="rounded border-slate-300 text-brand-600" />
                                Ingat saya
                            </label>

                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-brand-200 transition-all hover:bg-brand-700 disabled:opacity-50"
                            >
                                {processing ? <Loader2 className="animate-spin" size={16} /> : <LogIn size={16} />}
                                {processing ? 'Memproses...' : 'Masuk'}
                            </button>
                        </>
                    )}
                </Form>
                <Link
                    href="/demo"
                    className="mt-4 flex items-center justify-center gap-2 rounded-2xl border border-brand-200 bg-white px-4 py-3 text-sm font-bold text-brand-700 shadow-sm hover:bg-brand-50"
                >
                    <FlaskConical size={16} /> Belum punya akun? Ajukan akun demo
                </Link>
                <a
                    href="/lowongan"
                    className="mt-4 flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-bold text-brand-700 hover:bg-white/70"
                >
                    <Briefcase size={16} /> Melamar kerja? Lihat lowongan
                </a>
                <Link href="/" className="flex items-center justify-center gap-2 px-4 py-1 text-xs font-bold text-slate-400 hover:text-brand-700">
                    <ArrowLeft size={14} /> Kembali ke beranda
                </Link>
            </div>
        </div>
    );
}
