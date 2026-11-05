import { useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import { WifiOff } from 'lucide-react';
import { deviceName, isStandalone, useOnline, wasOpen } from '@/lib/pwa';
import { syncPush } from '@/lib/push';

/**
 * In every signed-in shell: a bar while the phone has no connection, and the installed app's
 * lock — opened again from the home screen (a new app session), it asks for the PIN first —
 * and this phone's push subscription told to the server again.
 */
export function AppGuard() {
    const { auth } = usePage().props;
    const online = useOnline();
    const app = auth.app;

    useEffect(() => {
        if (app && (app.pin || app.passkey) && isStandalone() && !wasOpen()) {
            router.post('/app-lock/lock', {}, { replace: true });
        }
    }, [app]);

    useEffect(() => {
        void syncPush(auth.push, deviceName());
    }, [auth.push]);

    if (online) return null;

    return (
        <div
            role="status"
            className="fixed inset-x-0 top-0 z-[60] flex items-center justify-center gap-2 bg-slate-800 px-3 pt-[max(0.375rem,env(safe-area-inset-top))] pb-1.5 text-xs font-bold text-white"
        >
            <WifiOff size={14} /> Tidak ada koneksi. Data terbaru tampil lagi saat sinyal kembali.
        </div>
    );
}
