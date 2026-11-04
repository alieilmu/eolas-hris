import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, CloudOff, Loader2, TriangleAlert, X } from 'lucide-react';
import { clearRejected, clockTime, flush, useClockQueue } from '@/lib/clockQueue';
import { useOnline } from '@/lib/pwa';

/**
 * In the portal: punches taken without a signal and not sent yet, sent as soon as the phone
 * is online (and every half minute while some wait); those the server refused, with why.
 */
export function ClockQueueBar() {
    const user = usePage().props.auth.user?.id;
    const online = useOnline();
    const { waiting, refused } = useClockQueue(user);
    const [busy, setBusy] = useState(false);
    const [sent, setSent] = useState(0);

    const send = () => {
        if (!user) return;
        setBusy(true);
        void flush(user)
            .then((n) => {
                if (n) {
                    setSent(n);
                    router.reload();
                }
            })
            .finally(() => setBusy(false));
    };

    useEffect(() => {
        if (!user || !online || !waiting.length) return;
        send();
        const timer = setInterval(send, 30000);
        return () => clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user, online, waiting.length]);

    useEffect(() => {
        if (!sent) return;
        const timer = setTimeout(() => setSent(0), 6000);
        return () => clearTimeout(timer);
    }, [sent]);

    if (!waiting.length && !refused.length && !sent) return null;

    return (
        <div className="mb-4 space-y-2">
            {waiting.length > 0 && (
                <div role="status" className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
                    <CloudOff size={18} className="mt-0.5 shrink-0" />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="font-bold">
                            {waiting.length} absen belum terkirim
                        </p>
                        <p className="text-xs">
                            {waiting.map((p) => `${p.type === 'in' ? 'Masuk' : 'Pulang'} ${clockTime(p.taken_at)}`).join(' · ')} —{' '}
                            {online ? 'sedang dikirim…' : 'terkirim otomatis saat ada sinyal.'}
                        </p>
                    </div>
                    {online && (
                        <button onClick={send} disabled={busy} className="shrink-0 rounded-xl bg-white px-3 py-1.5 text-xs font-bold text-amber-800 ring-1 ring-amber-200 disabled:opacity-60">
                            {busy ? <Loader2 size={14} className="animate-spin" /> : 'Kirim'}
                        </button>
                    )}
                </div>
            )}
            {sent > 0 && (
                <p role="status" className="flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                    <CheckCircle2 size={18} /> {sent} absen offline terkirim.
                </p>
            )}
            {refused.length > 0 && (
                <div role="alert" className="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
                    <TriangleAlert size={18} className="mt-0.5 shrink-0" />
                    <div className="min-w-0 flex-1 text-sm">
                        <p className="font-bold">Absen offline ditolak</p>
                        {refused.map((r) => (
                            <p key={r.id} className="text-xs">
                                {r.type === 'in' ? 'Masuk' : 'Pulang'} {clockTime(r.taken_at)}: {r.message}
                            </p>
                        ))}
                    </div>
                    <button onClick={() => void clearRejected()} aria-label="Tutup" className="shrink-0 rounded-lg p-1 text-rose-400 hover:bg-rose-100">
                        <X size={16} />
                    </button>
                </div>
            )}
        </div>
    );
}
