import { router } from '@inertiajs/react';
import { Check, Smartphone, X } from 'lucide-react';

export type VerificationStatus = 'pending' | 'approved' | 'rejected';

const STYLE: Record<VerificationStatus, { label: string; className: string }> = {
    pending: { label: 'Menunggu verifikasi', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    approved: { label: 'Disetujui', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    rejected: { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

/** Status of a pengajuan, marked when it came from the employee portal. */
export function StatusBadge({ status, source, notes }: { status: VerificationStatus; source?: string; notes?: string | null }) {
    const s = STYLE[status];
    return (
        <span className="inline-flex flex-col items-start gap-0.5">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap ring-1 ring-inset ${s.className}`}
            >
                {source === 'self_service' && <Smartphone size={11} aria-label="Dari Portal Karyawan" />}
                {s.label}
            </span>
            {notes && <span className="max-w-48 text-[10px] text-rose-600">{notes}</span>}
        </span>
    );
}

/** Approve / reject a pengajuan waiting for HR; a rejection asks for the reason. */
export function VerifyButtons({ url, what }: { url: string; what: string }) {
    const send = (decision: 'approve' | 'reject') => {
        if (decision === 'approve') {
            if (confirm(`Setujui ${what}?`)) router.post(url, { decision }, { preserveScroll: true, preserveState: true });
            return;
        }
        const notes = prompt(`Alasan menolak ${what}:`);
        if (notes?.trim()) router.post(url, { decision, notes: notes.trim() }, { preserveScroll: true, preserveState: true });
    };
    return (
        <>
            <button onClick={() => send('approve')} title="Setujui" className="rounded-lg bg-emerald-50 p-1.5 text-emerald-600 hover:bg-emerald-100">
                <Check size={15} />
            </button>
            <button onClick={() => send('reject')} title="Tolak" className="rounded-lg bg-rose-50 p-1.5 text-rose-600 hover:bg-rose-100">
                <X size={15} />
            </button>
        </>
    );
}
