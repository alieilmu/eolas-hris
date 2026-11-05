import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { BellRing, Loader2, X } from 'lucide-react';
import { deviceName, isStandalone } from '@/lib/pwa';
import { enablePush, pushState } from '@/lib/push';

const DISMISSED = 'eolas.push-dismissed';

/**
 * "Aktifkan notifikasi" on Beranda, in the installed app (or a phone's browser that can):
 * one tap asks the phone and subscribes. Hidden once on, blocked, or dismissed.
 */
export function PushCard() {
    const key = usePage().props.auth.push;
    const [show, setShow] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let dismissed = false;
        try {
            dismissed = localStorage.getItem(DISMISSED) === '1';
        } catch {
            /* private mode */
        }
        if (!key || dismissed || !(isStandalone() || window.matchMedia?.('(pointer: coarse)').matches)) return;
        void pushState().then((s) => setShow(s === 'off'));
    }, [key]);

    const dismiss = () => {
        try {
            localStorage.setItem(DISMISSED, '1');
        } catch {
            /* private mode */
        }
        setShow(false);
    };

    const enable = async () => {
        if (!key) return;
        setBusy(true);
        setError(null);
        try {
            await enablePush(key, deviceName());
            setShow(false);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    if (!show) return null;

    return (
        <section className="relative rounded-3xl border border-brand-100 bg-brand-50 p-4 shadow-sm">
            <button onClick={dismiss} aria-label="Tutup" className="absolute top-2 right-2 rounded-lg p-1.5 text-brand-400 hover:bg-white hover:text-brand-700">
                <X size={16} />
            </button>
            <div className="flex items-center gap-3 pr-6">
                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white text-brand-600">
                    <BellRing size={20} />
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-black text-brand-800">Aktifkan notifikasi</p>
                    <p className="text-xs text-brand-700/75">Kabar pengajuan, persetujuan, dan slip gaji langsung muncul di HP.</p>
                </div>
            </div>
            <button
                onClick={enable}
                disabled={busy}
                className="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50"
            >
                {busy ? <Loader2 size={16} className="animate-spin" /> : <BellRing size={16} />} Aktifkan
            </button>
            {error && <p className="mt-2 text-xs text-rose-600">{error}</p>}
        </section>
    );
}
