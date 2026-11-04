import '../css/app.css';

import { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { CloudOff, LogIn, LogOut, RefreshCw } from 'lucide-react';
import { MobileClockSheet } from '@/components/time/MobileClockSheet';
import { clockTime, flush, savedClock, useClockQueue, type SavedClock } from '@/lib/clockQueue';

/**
 * The page the service worker shows when the app is opened with no signal. For an employee
 * who uses Absen HP (and agreed to it) on this phone, it still takes a punch: it waits on the
 * phone and is sent once online. Everything else needs the connection.
 */
function OfflineClock({ saved }: { saved: SavedClock }) {
    const [clock, setClock] = useState<'in' | 'out' | null>(null);
    const { waiting } = useClockQueue(saved.user);
    const today = new Date().toDateString();
    const queuedIn = waiting.some((p) => p.type === 'in' && new Date(p.taken_at).toDateString() === today);
    const savedToday = new Date(saved.saved_at).toDateString() === today;
    const next = queuedIn || (savedToday && saved.state.next === 'out') ? 'out' : 'in';

    // Back online: send what waits, then the app itself.
    useEffect(() => {
        const back = () => void flush(saved.user).finally(() => location.replace('/self-service'));
        window.addEventListener('online', back);
        return () => window.removeEventListener('online', back);
    }, [saved.user]);

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-8">
            <div className="w-full max-w-sm rounded-3xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-5 text-center shadow-sm">
                <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
                    <CloudOff size={24} />
                </span>
                <h1 className="mt-3 text-lg font-black text-slate-800">Tidak ada koneksi</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Halo, {saved.name}. Absen tetap bisa: disimpan di HP ini dan terkirim otomatis saat sinyal kembali.
                </p>
                <button
                    onClick={() => setClock(next)}
                    className="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 px-4 py-3.5 text-base font-black text-white shadow-sm hover:bg-brand-700"
                >
                    {next === 'in' ? <LogIn size={18} /> : <LogOut size={18} />}
                    {next === 'in' ? 'Absen Masuk' : 'Absen Pulang'}
                </button>
                {next === 'out' && (
                    <button onClick={() => setClock('in')} className="mt-2 text-xs font-bold text-slate-500 hover:text-brand-700">
                        Absen Masuk
                    </button>
                )}
                {waiting.length > 0 && (
                    <div className="mt-4 rounded-2xl bg-amber-50 px-3 py-2.5 text-left text-xs text-amber-800">
                        <p className="font-bold">{waiting.length} absen menunggu dikirim</p>
                        {waiting.map((p) => (
                            <p key={p.id}>
                                {p.type === 'in' ? 'Masuk' : 'Pulang'} {clockTime(p.taken_at)}
                            </p>
                        ))}
                    </div>
                )}
                <button
                    onClick={() => location.reload()}
                    className="mt-4 inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-100"
                >
                    <RefreshCw size={14} /> Coba sambung lagi
                </button>
            </div>
            {clock && <MobileClockSheet state={saved.state} consent={null} type={clock} user={saved.user} offlineOnly onClose={() => setClock(null)} />}
        </div>
    );
}

const root = document.getElementById('offline-clock');
if (root) {
    void savedClock().then((saved) => {
        // Only for the employee who uses Absen HP and agreed to it; otherwise the plain page stays.
        if (!saved || !saved.state.enabled || !saved.state.consented) return;
        document.getElementById('offline-plain')?.remove();
        createRoot(root).render(<OfflineClock saved={saved} />);
    });
}
