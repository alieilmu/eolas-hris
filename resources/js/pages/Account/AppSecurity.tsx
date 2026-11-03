import { useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Fingerprint, KeyRound, Loader2, Save, ShieldCheck, Smartphone, Trash2 } from 'lucide-react';
import { createPasskey, deviceName, isStandalone, passkeySupported } from '@/lib/pwa';
import { fmtDate } from '@/lib/format';

interface Props {
    pin: boolean;
    passkeys: { id: number; name: string; created_at: string | null; last_used_at: string | null }[];
    /** Days a sign-in in the installed app lasts (Settings → White Label); 0 = off. */
    days: number;
    /** This session was started in the installed app. */
    device: boolean;
}

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/** Akun → Keamanan Aplikasi: the PIN and this phone's fingerprint / face that open the installed app. */
export default function AppSecurity({ pin, passkeys, days, device }: Props) {
    const { auth } = usePage().props;
    const employee = auth.user?.role === 'employee';
    const form = useForm({ password: '', pin: '', pin_confirmation: '' });
    const [supported, setSupported] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        void passkeySupported().then(setSupported);
    }, []);

    const savePin = () => form.post('/account/app/pin', { preserveScroll: true, onSuccess: () => form.reset() });
    const removePin = () => {
        const password = prompt('Masukkan password untuk menghapus PIN:');
        if (password) router.delete('/account/app/pin', { data: { password }, preserveScroll: true, onError: (e) => alert(Object.values(e)[0]) });
    };

    const addPasskey = async () => {
        setError(null);
        setBusy(true);
        try {
            const res = await fetch('/account/app/passkey', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const created = await createPasskey(await res.json());
            router.post('/account/app/passkey', { ...created, name: deviceName() }, {
                preserveScroll: true,
                onError: (e) => setError(Object.values(e)[0] ?? 'Gagal mendaftarkan.'),
                onFinish: () => setBusy(false),
            });
        } catch (e) {
            setError((e as Error).name === 'NotAllowedError' ? 'Dibatalkan.' : (e as Error).message);
            setBusy(false);
        }
    };

    const removePasskey = (id: number) => {
        if (confirm('Hapus perangkat ini? Sidik jari / wajahnya tidak bisa membuka aplikasi lagi.')) router.delete(`/account/app/passkey/${id}`, { preserveScroll: true });
    };

    return (
        <div className={employee ? '' : 'flex min-h-0 flex-1 items-start justify-center overflow-y-auto bg-slate-50 p-6'}>
            <Head title="Keamanan Aplikasi" />
            <div className="mx-auto w-full max-w-md space-y-4">
                <div className="rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-5 shadow-sm">
                    <h1 className="flex items-center gap-2 text-lg font-bold text-slate-800">
                        <ShieldCheck size={19} className="text-brand-500" /> Keamanan Aplikasi
                    </h1>
                    <p className="mt-1 text-sm text-slate-500">
                        {days > 0
                            ? `Di aplikasi yang dipasang di HP, Anda tetap masuk sampai ${days} hari. Setiap kali aplikasi dibuka lagi, buka dengan PIN atau sidik jari / wajah.`
                            : 'PIN dan sidik jari / wajah membuka aplikasi di HP setiap kali dibuka lagi.'}
                    </p>
                    {!device && !isStandalone() && (
                        <p className="mt-2 flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500">
                            <Smartphone size={15} className="mt-0.5 shrink-0" />
                            Berlaku saat Anda masuk dari aplikasi yang dipasang di layar utama HP.
                        </p>
                    )}
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        savePin();
                    }}
                    className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <KeyRound size={16} className="text-brand-500" /> PIN aplikasi
                        </h2>
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${pin ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                            {pin ? 'Aktif' : 'Belum dibuat'}
                        </span>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <Pin label={pin ? 'PIN baru (6 angka)' : 'PIN (6 angka)'} value={form.data.pin} onChange={(v) => form.setData('pin', v)} />
                        <Pin label="Ulangi PIN" value={form.data.pin_confirmation} onChange={(v) => form.setData('pin_confirmation', v)} />
                    </div>
                    <label className="block">
                        <span className="mb-1 block text-xs font-bold text-slate-500">Password akun</span>
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            className={inputClass}
                        />
                    </label>
                    {(form.errors.pin || form.errors.password) && <p className="text-xs text-rose-600">{form.errors.pin || form.errors.password}</p>}
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={form.processing || form.data.pin.length !== 6 || !form.data.password}
                            className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                        >
                            {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                            {pin ? 'Ganti PIN' : 'Buat PIN'}
                        </button>
                        {pin && (
                            <button type="button" onClick={removePin} className="rounded-xl px-3 py-2.5 text-sm font-bold text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                Hapus
                            </button>
                        )}
                    </div>
                </form>

                <div className="space-y-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <Fingerprint size={16} className="text-brand-500" /> Sidik jari / wajah
                    </h2>
                    {passkeys.length > 0 && (
                        <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200">
                            {passkeys.map((p) => (
                                <li key={p.id} className="flex items-center gap-3 px-3 py-2.5">
                                    <Smartphone size={16} className="shrink-0 text-slate-400" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-slate-700">{p.name}</p>
                                        <p className="text-[11px] text-slate-400">
                                            Didaftarkan {fmtDate(p.created_at)}
                                            {p.last_used_at ? ` · terakhir dipakai ${fmtDate(p.last_used_at)}` : ''}
                                        </p>
                                    </div>
                                    <button onClick={() => removePasskey(p.id)} title="Hapus" className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                                        <Trash2 size={15} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {supported ? (
                        <button
                            onClick={addPasskey}
                            disabled={busy}
                            className="flex w-full items-center justify-center gap-2 rounded-xl border border-brand-200 px-4 py-2.5 text-sm font-bold text-brand-700 hover:bg-brand-50 disabled:opacity-50"
                        >
                            {busy ? <Loader2 size={16} className="animate-spin" /> : <Fingerprint size={16} />} Daftarkan HP ini
                        </button>
                    ) : (
                        <p className="text-xs text-slate-400">HP / browser ini tidak menyediakan sidik jari atau wajah untuk aplikasi web. PIN tetap bisa dipakai.</p>
                    )}
                    {error && <p className="text-xs text-rose-600">{error}</p>}
                </div>

                <Link href="/account/password" className="flex items-center justify-center gap-2 py-1 text-xs font-bold text-slate-400 hover:text-brand-700">
                    <KeyRound size={14} /> Ganti password akun
                </Link>
            </div>
        </div>
    );
}

function Pin({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-bold text-slate-500">{label}</span>
            <input
                type="password"
                inputMode="numeric"
                autoComplete="off"
                maxLength={6}
                value={value}
                onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 6))}
                className={`${inputClass} text-center font-mono tracking-[0.4em]`}
            />
        </label>
    );
}
