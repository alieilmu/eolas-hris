import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, KeyRound, Loader2, ShieldCheck, ShieldOff, Smartphone } from 'lucide-react';

interface Props {
    enabled: boolean;
    confirmed_at: string | null;
    required: boolean;
    skippable: boolean;
    setup: { qr: string; secret: string } | null;
    recovery_codes: string[] | null;
    recovery_left: number | null;
}

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const card = 'space-y-4 rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-6 shadow-sm';
const button =
    'inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50';

/** Verifikasi 2 langkah: turn it on with an authenticator app, keep the recovery codes, or turn it off. */
export default function TwoFactor({ enabled, confirmed_at, required, skippable, setup, recovery_codes, recovery_left }: Props) {
    return (
        <div className="flex min-h-0 flex-1 items-start justify-center overflow-y-auto bg-slate-50 p-6">
            <Head title="Verifikasi 2 Langkah" />
            <div className="mx-auto w-full max-w-lg space-y-4">
                <div className={card}>
                    <h1 className="flex items-center gap-2 text-lg font-bold text-slate-800">
                        <ShieldCheck size={19} className="text-brand-500" /> Verifikasi 2 Langkah (2FA)
                    </h1>
                    <p className="text-sm text-slate-500">
                        Setelah password, login meminta kode 6 angka dari aplikasi authenticator di HP Anda (Google Authenticator, Microsoft Authenticator,
                        Authy, dll.). Password yang bocor saja tidak cukup untuk masuk.
                        {required && <b className="text-slate-700"> Wajib untuk akun dengan menu payroll.</b>}
                    </p>
                    {enabled ? (
                        <p className="flex items-center gap-2 rounded-xl bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700">
                            <Check size={16} /> Aktif sejak {confirmed_at?.slice(0, 16)} · {recovery_left} kode cadangan tersisa
                        </p>
                    ) : setup ? (
                        <Confirm setup={setup} />
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            <button onClick={() => router.post('/account/two-factor', {}, { preserveScroll: true })} className={button}>
                                <Smartphone size={16} /> Aktifkan 2FA
                            </button>
                            {skippable && (
                                <button
                                    onClick={() => router.post('/account/two-factor/skip')}
                                    className="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100"
                                >
                                    Lewati dulu (khusus workspace demo)
                                </button>
                            )}
                        </div>
                    )}
                </div>

                {recovery_codes && <RecoveryCodes codes={recovery_codes} />}
                {enabled && <Manage required={required} />}
            </div>
        </div>
    );
}

function Confirm({ setup }: { setup: NonNullable<Props['setup']> }) {
    const form = useForm({ code: '' });

    return (
        <div className="space-y-4">
            <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                <li>Buka aplikasi authenticator, pilih tambah akun / scan QR.</li>
                <li>Scan kode QR ini (atau ketik kunci di bawahnya).</li>
                <li>Masukkan kode 6 angka yang muncul di aplikasi.</li>
            </ol>
            <div className="flex flex-wrap items-center gap-4">
                <div className="rounded-2xl border border-slate-200 bg-white p-2" dangerouslySetInnerHTML={{ __html: setup.qr }} />
                <div className="min-w-0 text-xs text-slate-500">
                    Kunci manual
                    <code className="mt-1 block rounded-lg bg-slate-100 px-2 py-1.5 font-mono text-[13px] break-all text-slate-700">{setup.secret}</code>
                </div>
            </div>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/account/two-factor/confirm', { preserveScroll: true });
                }}
                className="flex flex-wrap items-start gap-2"
            >
                <div className="w-40">
                    <input
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        maxLength={7}
                        placeholder="123456"
                        className={`${inputClass} text-center font-mono tracking-widest`}
                        autoFocus
                    />
                </div>
                <button type="submit" disabled={form.processing || form.data.code.replace(/\D/g, '').length !== 6} className={button}>
                    {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Konfirmasi
                </button>
                {form.errors.code && <p className="w-full text-xs font-semibold text-rose-600">{form.errors.code}</p>}
            </form>
        </div>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="space-y-3 rounded-3xl border border-amber-200 bg-amber-50 p-6">
            <h2 className="flex items-center gap-2 font-bold text-amber-900">
                <KeyRound size={17} /> Kode cadangan: simpan sekarang
            </h2>
            <p className="text-sm text-amber-800">
                Pakai salah satu kode ini bila HP hilang atau aplikasi terhapus. Setiap kode hanya berlaku sekali, dan kode ini tidak ditampilkan lagi.
            </p>
            <div className="grid grid-cols-2 gap-1.5 font-mono text-sm text-slate-800">
                {codes.map((c) => (
                    <span key={c} className="rounded-lg bg-white px-2 py-1 text-center">
                        {c}
                    </span>
                ))}
            </div>
            <button
                onClick={() => navigator.clipboard?.writeText(codes.join('\n')).then(() => setCopied(true))}
                className="inline-flex items-center gap-1.5 text-sm font-bold text-amber-900 hover:underline"
            >
                {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? 'Tersalin' : 'Salin semua'}
            </button>
        </div>
    );
}

function Manage({ required }: { required: boolean }) {
    const form = useForm({ password: '' });
    const send = (method: 'post' | 'delete', url: string) =>
        form.submit(method, url, { preserveScroll: true, onSuccess: () => form.reset() });

    return (
        <div className={card}>
            <h2 className="font-bold text-slate-700">Kelola 2FA</h2>
            <input
                type="password"
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
                placeholder="Password Anda untuk konfirmasi"
                autoComplete="current-password"
                className={inputClass}
            />
            {form.errors.password && <p className="text-xs font-semibold text-rose-600">{form.errors.password}</p>}
            <div className="flex flex-wrap gap-2">
                <button
                    onClick={() => send('post', '/account/two-factor/recovery-codes')}
                    disabled={!form.data.password || form.processing}
                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                >
                    <KeyRound size={15} /> Buat kode cadangan baru
                </button>
                {!required && (
                    <button
                        onClick={() => confirm('Matikan 2FA?') && send('delete', '/account/two-factor')}
                        disabled={!form.data.password || form.processing}
                        className="inline-flex items-center gap-2 rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                    >
                        <ShieldOff size={15} /> Matikan 2FA
                    </button>
                )}
            </div>
            {required && <p className="text-[11px] text-slate-400">Akun dengan menu payroll tidak bisa mematikan 2FA. HP hilang? Minta Super Admin mereset 2FA Anda.</p>}
        </div>
    );
}
