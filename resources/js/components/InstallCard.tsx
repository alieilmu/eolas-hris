import { useEffect, useState } from 'react';
import { Download, Share, SquarePlus, X } from 'lucide-react';
import { useBranding } from '@/components/BrandLogo';
import { isIos, isStandalone, useInstallPrompt } from '@/lib/pwa';

const DISMISSED = 'eolas.install-dismissed';

/**
 * "Pasang aplikasi" on Beranda: Android's own install prompt, or on an iPhone the steps of
 * Share → Add to Home Screen. Hidden inside the installed app and once dismissed.
 */
export function InstallCard() {
    const branding = useBranding();
    const { available, install } = useInstallPrompt();
    const [ios, setIos] = useState(false);
    const [steps, setSteps] = useState(false);
    const [hidden, setHidden] = useState(true);

    useEffect(() => {
        let dismissed = false;
        try {
            dismissed = localStorage.getItem(DISMISSED) === '1';
        } catch {
            /* private mode */
        }
        setIos(isIos());
        setHidden(dismissed || isStandalone());
    }, []);

    const dismiss = () => {
        try {
            localStorage.setItem(DISMISSED, '1');
        } catch {
            /* private mode */
        }
        setHidden(true);
    };

    if (hidden || (!available && !ios)) return null;

    return (
        <section className="relative overflow-hidden rounded-3xl bg-brand-700 p-4 text-white shadow-sm">
            <button onClick={dismiss} aria-label="Tutup" className="absolute top-2 right-2 rounded-lg p-1.5 text-white/60 hover:bg-white/10 hover:text-white">
                <X size={16} />
            </button>
            <div className="flex items-center gap-3 pr-6">
                <img src="/app-icon/192.png" alt="" className="h-12 w-12 shrink-0 rounded-xl bg-white" />
                <div className="min-w-0">
                    <p className="text-sm font-black">Pasang {branding.app_name} di HP</p>
                    <p className="text-xs text-white/75">Buka portal langsung dari layar utama, seperti aplikasi.</p>
                </div>
            </div>
            {available ? (
                <button
                    onClick={() => void install().then((ok) => ok && setHidden(true))}
                    className="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl bg-white px-4 py-2.5 text-sm font-bold text-brand-700"
                >
                    <Download size={16} /> Pasang aplikasi
                </button>
            ) : steps ? (
                <ol className="mt-3 space-y-2 rounded-2xl bg-white/10 p-3 text-xs">
                    <li className="flex items-center gap-2">
                        <b className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[11px] text-brand-700">1</b>
                        Ketuk <Share size={15} className="shrink-0" /> <b>Bagikan</b> di bawah layar Safari.
                    </li>
                    <li className="flex items-center gap-2">
                        <b className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[11px] text-brand-700">2</b>
                        Pilih <SquarePlus size={15} className="shrink-0" /> <b>Tambahkan ke Layar Utama</b>.
                    </li>
                    <li className="flex items-center gap-2">
                        <b className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[11px] text-brand-700">3</b>
                        Ketuk <b>Tambah</b>, lalu buka dari ikon {branding.app_name}.
                    </li>
                </ol>
            ) : (
                <button
                    onClick={() => setSteps(true)}
                    className="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl bg-white px-4 py-2.5 text-sm font-bold text-brand-700"
                >
                    <SquarePlus size={16} /> Cara pasang di iPhone
                </button>
            )}
        </section>
    );
}
