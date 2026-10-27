import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, ShieldCheck } from 'lucide-react';
import { LoginMark, useBranding } from '@/components/BrandLogo';

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/** After the password: the code from the authenticator app, or one of the recovery codes. */
export default function TwoFactorChallenge() {
    const branding = useBranding();
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });
    const submit = () => {
        form.transform((d) => (recovery ? { recovery_code: d.recovery_code } : { code: d.code }));
        form.post('/two-factor/challenge', { onError: () => form.reset() });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-50 via-slate-50 to-accent-50 p-6">
            <Head title="Verifikasi 2 Langkah" />
            <div className="w-full max-w-sm">
                <div className="mb-8 flex justify-center">
                    {branding.logo_full_url ? <img src={branding.logo_full_url} alt={branding.app_name} className="max-h-24 w-52 object-contain" /> : <LoginMark b={branding} />}
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="space-y-5 rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-8 shadow-sm"
                >
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-bold text-slate-800">
                            <ShieldCheck size={19} className="text-brand-500" /> Verifikasi 2 Langkah
                        </h1>
                        <p className="mt-1 text-xs text-slate-400">
                            {recovery ? 'Masukkan salah satu kode cadangan Anda.' : 'Masukkan kode 6 angka dari aplikasi authenticator di HP Anda.'}
                        </p>
                    </div>
                    {form.errors.code && <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{form.errors.code}</div>}
                    {recovery ? (
                        <input
                            key="recovery"
                            value={form.data.recovery_code}
                            onChange={(e) => form.setData('recovery_code', e.target.value)}
                            placeholder="xxxxx-xxxxx"
                            autoFocus
                            className={`${inputClass} font-mono`}
                        />
                    ) : (
                        <input
                            key="code"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={7}
                            placeholder="123456"
                            autoFocus
                            className={`${inputClass} text-center font-mono text-lg tracking-[0.4em]`}
                        />
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                        {form.processing && <Loader2 className="animate-spin" size={16} />} Verifikasi
                    </button>
                    <div className="flex justify-between text-xs font-bold">
                        <button type="button" onClick={() => setRecovery(!recovery)} className="text-brand-600 hover:underline">
                            {recovery ? 'Pakai kode aplikasi' : 'Pakai kode cadangan'}
                        </button>
                        <Link href="/logout" method="post" as="button" className="text-slate-400 hover:text-slate-600">
                            Keluar
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    );
}
