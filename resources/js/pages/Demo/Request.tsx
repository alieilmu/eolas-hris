import type { FormEvent, ReactNode } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Database, FlaskConical, Loader2, Rocket } from 'lucide-react';
import { EolasMark } from '@/components/EolasMark';

interface Props {
    sumber: Record<'demo' | 'kosong', string>;
    jumlah_karyawan: string[];
    auto_approve: boolean;
    demo_days: number;
}

const input =
    'w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-brand-400 focus:ring-4 focus:ring-brand-100';

function Field({ label, error, children, hint }: { label: string; error?: string; children: ReactNode; hint?: string }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-xs font-bold text-slate-600">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-[11px] text-slate-400">{hint}</span>}
            {error && <span className="mt-1 block text-xs font-semibold text-rose-600">{error}</span>}
        </label>
    );
}

const CHOICES = [
    {
        value: 'demo' as const,
        icon: FlaskConical,
        title: 'Data demo sistem',
        desc: 'Langsung terisi karyawan, roster, absensi, cuti, dinas, deklarasi, recruitment, dan MPP contoh.',
    },
    {
        value: 'kosong' as const,
        icon: Database,
        title: 'Data saya sendiri',
        desc: 'Mulai kosong (hanya master dasar). Masukkan organisasi dan karyawan Anda sendiri atau lewat import Excel.',
    },
];

export default function DemoRequest({ jumlah_karyawan, auto_approve, demo_days }: Props) {
    const form = useForm({
        nama: '',
        email: '',
        perusahaan: '',
        jabatan: '',
        no_hp: '',
        jumlah_karyawan: '',
        kebutuhan: '',
        sumber_data: 'demo' as 'demo' | 'kosong',
        password: '',
        password_confirmation: '',
        setuju: false,
    });
    const { data, setData, errors, processing } = form;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/demo', { preserveScroll: true });
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-brand-50 via-white to-accent-50">
            <Head title="Ajukan Akun Demo" />
            <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <Link href="/">
                    <EolasMark />
                </Link>
                <Link href="/" className="flex items-center gap-1.5 text-sm font-bold text-slate-500 hover:text-brand-700">
                    <ArrowLeft size={16} /> Beranda
                </Link>
            </header>

            <main className="mx-auto grid max-w-6xl gap-10 px-4 pt-6 pb-20 sm:px-6 lg:grid-cols-[1fr_1.35fr]">
                <section className="lg:pt-8">
                    <span className="inline-flex items-center gap-2 rounded-full bg-brand-600 px-3 py-1 text-xs font-bold text-white">
                        <Rocket size={14} /> Gratis {demo_days} hari
                    </span>
                    <h1 className="mt-4 text-3xl font-black tracking-tight text-slate-900 sm:text-4xl">Ajukan akun demo Eolas HRIS</h1>
                    <p className="mt-3 text-slate-500">
                        Anda mendapat workspace sendiri yang terpisah dari pengguna lain, dengan akses Super Admin ke semua modul.
                    </p>
                    <ul className="mt-6 space-y-3 text-sm text-slate-600">
                        {[
                            auto_approve ? 'Workspace aktif otomatis dalam hitungan detik' : 'Pengajuan ditinjau admin Eolas sebelum workspace dibuat',
                            'Masuk dengan email dan password yang Anda buat di form ini',
                            'Pilih data demo sistem atau mulai dengan data Anda sendiri',
                            'Sumber data bisa diganti kapan saja dari dashboard',
                        ].map((t) => (
                            <li key={t} className="flex gap-2">
                                <CheckCircle2 size={18} className="shrink-0 text-brand-500" /> {t}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-8 text-sm text-slate-500">
                        Sudah punya akun demo?{' '}
                        <Link href="/login" className="font-bold text-brand-700 hover:underline">
                            Masuk di sini
                        </Link>
                    </p>
                </section>

                <form onSubmit={submit} className="space-y-6 rounded-3xl border border-slate-100 bg-white p-6 shadow-xl shadow-brand-900/5 sm:p-8">
                    <div>
                        <h2 className="text-sm font-black tracking-wide text-slate-800 uppercase">1. Pilih sumber data</h2>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {CHOICES.map((c) => {
                                const on = data.sumber_data === c.value;
                                const Icon = c.icon;
                                return (
                                    <button
                                        type="button"
                                        key={c.value}
                                        onClick={() => setData('sumber_data', c.value)}
                                        aria-pressed={on}
                                        className={`rounded-2xl border-2 p-4 text-left transition ${
                                            on ? 'border-brand-500 bg-brand-50 ring-4 ring-brand-100' : 'border-slate-100 hover:border-brand-200'
                                        }`}
                                    >
                                        <span className="flex items-center justify-between">
                                            <span
                                                className={`flex h-9 w-9 items-center justify-center rounded-xl ${on ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500'}`}
                                            >
                                                <Icon size={18} />
                                            </span>
                                            {on && <CheckCircle2 size={20} className="text-brand-600" />}
                                        </span>
                                        <span className="mt-3 block text-sm font-black text-slate-800">{c.title}</span>
                                        <span className="mt-1 block text-xs leading-relaxed text-slate-500">{c.desc}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div>
                        <h2 className="text-sm font-black tracking-wide text-slate-800 uppercase">2. Data Anda</h2>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field label="Nama lengkap *" error={errors.nama}>
                                <input className={input} value={data.nama} onChange={(e) => setData('nama', e.target.value)} required maxLength={100} />
                            </Field>
                            <Field label="Email kerja *" error={errors.email} hint="Dipakai untuk masuk ke workspace">
                                <input
                                    type="email"
                                    className={input}
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                    maxLength={150}
                                    autoComplete="email"
                                />
                            </Field>
                            <Field label="Perusahaan *" error={errors.perusahaan}>
                                <input
                                    className={input}
                                    value={data.perusahaan}
                                    onChange={(e) => setData('perusahaan', e.target.value)}
                                    required
                                    maxLength={150}
                                />
                            </Field>
                            <Field label="Jabatan" error={errors.jabatan}>
                                <input className={input} value={data.jabatan} onChange={(e) => setData('jabatan', e.target.value)} maxLength={100} />
                            </Field>
                            <Field label="No. HP / WhatsApp" error={errors.no_hp}>
                                <input className={input} value={data.no_hp} onChange={(e) => setData('no_hp', e.target.value)} inputMode="tel" maxLength={30} />
                            </Field>
                            <Field label="Jumlah karyawan" error={errors.jumlah_karyawan}>
                                <select className={input} value={data.jumlah_karyawan} onChange={(e) => setData('jumlah_karyawan', e.target.value)}>
                                    <option value="">Pilih…</option>
                                    {jumlah_karyawan.map((j) => (
                                        <option key={j} value={j}>
                                            {j} orang
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <div className="sm:col-span-2">
                                <Field label="Kebutuhan / modul yang ingin dicoba" error={errors.kebutuhan}>
                                    <textarea
                                        className={`${input} min-h-20`}
                                        value={data.kebutuhan}
                                        onChange={(e) => setData('kebutuhan', e.target.value)}
                                        maxLength={1000}
                                        placeholder="Misal: roster site, cuti lapangan, klaim pengobatan…"
                                    />
                                </Field>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h2 className="text-sm font-black tracking-wide text-slate-800 uppercase">3. Buat password</h2>
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field label="Password *" error={errors.password} hint="Minimal 8 karakter, berisi huruf dan angka">
                                <input
                                    type="password"
                                    className={input}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    required
                                    autoComplete="new-password"
                                />
                            </Field>
                            <Field label="Ulangi password *" error={errors.password_confirmation}>
                                <input
                                    type="password"
                                    className={input}
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    required
                                    autoComplete="new-password"
                                />
                            </Field>
                        </div>
                    </div>

                    <label className="flex items-start gap-2.5 text-xs text-slate-500">
                        <input
                            type="checkbox"
                            checked={data.setuju}
                            onChange={(e) => setData('setuju', e.target.checked)}
                            className="mt-0.5 rounded border-slate-300 text-brand-600"
                        />
                        <span>
                            Saya memahami bahwa ini adalah lingkungan demo selama {demo_days} hari dan data di workspace demo dapat dihapus setelah masa
                            demo berakhir.
                            {errors.setuju && <span className="block font-semibold text-rose-600">{errors.setuju}</span>}
                        </span>
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-5 py-3.5 text-sm font-black text-white shadow-lg shadow-brand-600/25 transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {processing ? <Loader2 size={16} className="animate-spin" /> : <Rocket size={16} />}
                        {processing ? 'Mengirim…' : 'Ajukan Akun Demo'}
                    </button>
                </form>
            </main>
        </div>
    );
}
