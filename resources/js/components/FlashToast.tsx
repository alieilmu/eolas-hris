import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert, X } from 'lucide-react';

const VISIBLE_MS = 4500;

/**
 * Shows the "success" (or "error") flash message a server action left behind, then fades it out.
 * aboveBar: the portal on a phone, where the menu bar sits at the bottom.
 */
export function FlashToast({ aboveBar = false }: { aboveBar?: boolean }) {
    const { flash } = usePage();
    const [message, setMessage] = useState<{ text: string; error: boolean } | null>(null);

    useEffect(() => {
        const text = flash?.error ?? flash?.success;
        if (!text) return;
        setMessage({ text, error: !!flash?.error });
        const timer = setTimeout(() => setMessage(null), flash?.error ? VISIBLE_MS * 2 : VISIBLE_MS);
        return () => clearTimeout(timer);
    }, [flash]);

    if (!message) return null;

    return (
        <div
            role={message.error ? 'alert' : 'status'}
            className={`fixed z-[70] flex max-w-md ${aboveBar ? 'right-3 bottom-[calc(5rem+env(safe-area-inset-bottom))] left-3 sm:right-6 sm:bottom-6 sm:left-auto' : 'right-6 bottom-6'} items-start gap-3 rounded-2xl border bg-white px-4 py-3 text-sm text-slate-700 shadow-xl ${
                message.error ? 'border-rose-200' : 'border-emerald-100'
            }`}
        >
            {message.error ? (
                <ShieldAlert size={18} className="mt-0.5 shrink-0 text-rose-500" />
            ) : (
                <CheckCircle2 size={18} className="mt-0.5 shrink-0 text-emerald-500" />
            )}
            <span className="flex-1">{message.text}</span>
            <button onClick={() => setMessage(null)} className="shrink-0 text-slate-300 hover:text-slate-500" aria-label="Tutup">
                <X size={16} />
            </button>
        </div>
    );
}
