import { Form, Head, usePage } from '@inertiajs/react';
import { KeyRound, Loader2, Save, ShieldAlert } from 'lucide-react';

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/** Change the own password; a first-time password has to be replaced before anything else opens. */
export default function Password({ must_change }: { must_change: boolean }) {
    const { auth } = usePage().props;
    const employee = auth.user?.role === 'employee';

    return (
        <div className={employee ? '' : 'flex min-h-0 flex-1 items-start justify-center overflow-y-auto bg-slate-50 p-6'}>
            <Head title="Ganti Password" />
            <div className="mx-auto w-full max-w-md space-y-4">
                {must_change && (
                    <div className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <ShieldAlert size={18} className="mt-0.5 shrink-0" />
                        <span>
                            Ini login pertama Anda (atau password baru saja direset). Ganti password dulu sebelum melanjutkan
                            {employee ? ' — password awal adalah tanggal lahir (ddmmyyyy).' : '.'}
                        </span>
                    </div>
                )}
                <Form
                    action="/account/password"
                    method="put"
                    resetOnError
                    className="space-y-4 rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-6 shadow-sm"
                >
                    {({ errors, processing }) => (
                        <>
                            <h1 className="flex items-center gap-2 text-lg font-bold text-slate-800">
                                <KeyRound size={18} className="text-brand-500" />
                                Ganti Password
                            </h1>
                            {[
                                ['current_password', 'Password saat ini', 'current-password'],
                                ['password', 'Password baru (minimal 8 karakter)', 'new-password'],
                                ['password_confirmation', 'Ulangi password baru', 'new-password'],
                            ].map(([name, label, autoComplete]) => (
                                <div key={name}>
                                    <label htmlFor={name} className="mb-1.5 block text-xs font-bold text-slate-500">
                                        {label}
                                    </label>
                                    <input id={name} name={name} type="password" autoComplete={autoComplete} required className={inputClass} />
                                    {errors[name as keyof typeof errors] && (
                                        <p className="mt-1 text-xs text-rose-600">{errors[name as keyof typeof errors]}</p>
                                    )}
                                </div>
                            ))}
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                            >
                                {processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                                Simpan password
                            </button>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}
