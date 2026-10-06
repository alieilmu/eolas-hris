import { router } from '@inertiajs/react';
import { Check, Smartphone, X } from 'lucide-react';

export type VerificationStatus = 'diajukan' | 'disetujui' | 'ditolak';

const STYLE: Record<VerificationStatus, { label: string; className: string }> = {
    diajukan: { label: 'Menunggu verifikasi', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    disetujui: { label: 'Disetujui', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    ditolak: { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

/** Status of a pengajuan, marked when it came from the employee portal. */
export function StatusBadge({ status, sumber, catatan }: { status: VerificationStatus; sumber?: string; catatan?: string | null }) {
    const s = STYLE[status];
    return (
        <span className="inline-flex flex-col items-start gap-0.5">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap ring-1 ring-inset ${s.className}`}
            >
                {sumber === 'portal' && <Smartphone size={11} aria-label="Dari Portal Karyawan" />}
                {s.label}
            </span>
            {catatan && <span className="max-w-48 text-[10px] text-rose-600">{catatan}</span>}
        </span>
    );
}

/** Approve / reject a pengajuan waiting for HR; a rejection asks for the reason. */
export function VerifyButtons({ url, what }: { url: string; what: string }) {
    const send = (keputusan: 'setujui' | 'tolak') => {
        if (keputusan === 'setujui') {
            if (confirm(`Setujui ${what}?`)) router.post(url, { keputusan }, { preserveScroll: true, preserveState: true });
            return;
        }
        const catatan = prompt(`Alasan menolak ${what}:`);
        if (catatan?.trim()) router.post(url, { keputusan, catatan: catatan.trim() }, { preserveScroll: true, preserveState: true });
    };
    return (
        <>
            <button onClick={() => send('setujui')} title="Setujui" className="rounded-lg bg-emerald-50 p-1.5 text-emerald-600 hover:bg-emerald-100">
                <Check size={15} />
            </button>
            <button onClick={() => send('tolak')} title="Tolak" className="rounded-lg bg-rose-50 p-1.5 text-rose-600 hover:bg-rose-100">
                <X size={15} />
            </button>
        </>
    );
}
