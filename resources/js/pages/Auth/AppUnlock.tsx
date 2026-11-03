import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Delete, Fingerprint, Loader2, Lock, LogOut } from 'lucide-react';
import { LoginMark, useBranding } from '@/components/BrandLogo';
import { markOpen, signWithPasskey } from '@/lib/pwa';

interface Props {
    name: string;
    pin: boolean;
    passkey: boolean;
    tries_left: number;
}

const LENGTH = 6;

/** The installed app, opened again: the PIN on a keypad, or the phone's fingerprint / face. */
export default function AppUnlock({ name, pin, passkey, tries_left }: Props) {
    const branding = useBranding();
    const [digits, setDigits] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const send = (value: string) => {
        setBusy(true);
        router.post('/app-lock/pin', { pin: value }, {
            onError: (errors) => {
                setError(errors.pin ?? Object.values(errors)[0] ?? 'PIN salah.');
                setDigits('');
            },
            onFinish: () => setBusy(false),
        });
    };

    const press = (key: string) => {
        if (busy) return;
        setError(null);
        const next = (digits + key).slice(0, LENGTH);
        setDigits(next);
        if (next.length === LENGTH) send(next);
    };

    const biometric = async () => {
        setError(null);
        setBusy(true);
        try {
            const res = await fetch('/app-lock/passkey', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const answer = await signWithPasskey(await res.json());
            router.post('/app-lock/passkey', answer, {
                onError: (errors) => setError(errors.passkey ?? 'Sidik jari / wajah tidak dikenali.'),
                onFinish: () => setBusy(false),
            });
        } catch {
            setError('Sidik jari / wajah dibatalkan. Coba lagi' + (pin ? ' atau pakai PIN.' : '.'));
            setBusy(false);
        }
    };

    // This opening of the app is handled here: once unlocked, it is not locked again until the app is closed.
    // Opening with the fingerprint is offered at once when there is one.
    useEffect(() => {
        markOpen();
        if (passkey) void biometric();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The keyboard too (a tablet or a phone with a keyboard).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (/^\d$/.test(e.key)) press(e.key);
            if (e.key === 'Backspace') setDigits((d) => d.slice(0, -1));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-brand-50 via-slate-50 to-accent-50 px-6 pt-[env(safe-area-inset-top)] pb-[max(1.5rem,env(safe-area-inset-bottom))]">
            <Head title="Buka aplikasi" />
            <div className="w-full max-w-xs text-center">
                <div className="mb-5 flex justify-center">
                    {branding.logo_full_url ? <img src={branding.logo_full_url} alt={branding.app_name} className="max-h-16 w-44 object-contain" /> : <LoginMark b={branding} />}
                </div>
                <p className="flex items-center justify-center gap-1.5 text-xs font-bold text-slate-400">
                    <Lock size={13} /> Aplikasi terkunci
                </p>
                <h1 className="mt-1 truncate text-lg font-black text-slate-800">{name}</h1>

                {pin && (
                    <>
                        <p className="mt-4 text-sm text-slate-500">Masukkan PIN</p>
                        <div className="mt-3 flex justify-center gap-3" aria-label={`${digits.length} dari ${LENGTH} angka`}>
                            {Array.from({ length: LENGTH }, (_, i) => (
                                <span
                                    key={i}
                                    className={`h-3.5 w-3.5 rounded-full border-2 transition ${i < digits.length ? 'border-brand-600 bg-brand-600' : 'border-slate-300'}`}
                                />
                            ))}
                        </div>
                    </>
                )}

                <p className={`mt-3 min-h-10 text-xs ${error ? 'font-bold text-rose-600' : 'text-slate-400'}`}>
                    {error ?? (pin && tries_left < 5 ? `Sisa ${tries_left} kali percobaan.` : '')}
                </p>

                {pin && (
                    <div className="mx-auto grid max-w-64 grid-cols-3 gap-3">
                        {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((k) => (
                            <Key key={k} onClick={() => press(k)} disabled={busy}>
                                {k}
                            </Key>
                        ))}
                        {passkey ? (
                            <Key onClick={biometric} disabled={busy} label="Sidik jari / wajah">
                                <Fingerprint size={24} />
                            </Key>
                        ) : (
                            <span />
                        )}
                        <Key onClick={() => press('0')} disabled={busy}>
                            0
                        </Key>
                        <Key onClick={() => setDigits((d) => d.slice(0, -1))} disabled={busy || !digits} label="Hapus">
                            {busy ? <Loader2 size={20} className="animate-spin" /> : <Delete size={22} />}
                        </Key>
                    </div>
                )}

                {!pin && passkey && (
                    <button
                        onClick={biometric}
                        disabled={busy}
                        className="mx-auto mt-2 flex items-center gap-2 rounded-2xl bg-brand-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-brand-200 disabled:opacity-50"
                    >
                        {busy ? <Loader2 size={18} className="animate-spin" /> : <Fingerprint size={18} />} Buka dengan sidik jari / wajah
                    </button>
                )}

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="mx-auto mt-8 flex items-center gap-1.5 text-xs font-bold text-slate-400 hover:text-rose-600"
                >
                    <LogOut size={14} /> Bukan Anda? Keluar
                </Link>
            </div>
        </div>
    );
}

function Key({ onClick, disabled, label, children }: { onClick: () => void; disabled?: boolean; label?: string; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            className="flex h-16 items-center justify-center rounded-2xl bg-white text-2xl font-bold text-slate-700 shadow-sm ring-1 ring-slate-200 transition active:scale-95 active:bg-brand-50 disabled:opacity-40"
        >
            {children}
        </button>
    );
}
