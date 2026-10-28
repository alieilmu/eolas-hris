import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ExternalLink, Inbox, Loader2, UserRoundCheck, X } from 'lucide-react';
import { ApprovalHistory, type ApprovalAction, type ApprovalStep } from '@/components/ApprovalHistory';
import { AttendanceHeader, Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';
import { DelegationDialog } from '@/components/DelegationDialog';

type Tab = 'pending' | 'decided' | 'mine';

interface Item {
    id: number;
    type: string;
    type_label: string;
    summary: string | null;
    url: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_label: string;
    employee: { name: string; employee_number: string; position: string | null; location: string | null } | null;
    submitted_at: string | null;
    step: string | null;
    steps: ApprovalStep[];
    events: ApprovalAction[];
    can_decide: boolean;
    can_cancel: boolean;
}

interface Props {
    tab: Tab;
    type: string | null;
    types: { value: string; label: string }[];
    counts: { pending: number };
    has_own: boolean;
    /** Approvers this account stands in for today. */
    acting_for: string[];
    items: Item[];
}

const STATUS_TONE: Record<Item['status'], string> = {
    pending: 'bg-amber-50 text-amber-700',
    approved: 'bg-brand-50 text-brand-700',
    rejected: 'bg-rose-50 text-rose-700',
    cancelled: 'bg-slate-100 text-slate-500',
};

const date = (at: string | null) => (at ? new Date(at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '');

/** Persetujuan Saya: pengajuan waiting for this account, decided one by one or many at once; past decisions; own pengajuan. */
export default function Approvals({ tab, type, types, counts, has_own, acting_for, items }: Props) {
    const portal = usePage().props.auth.user?.role === 'employee';
    const [selected, setSelected] = useState<number[]>([]);
    const [open, setOpen] = useState<number | null>(null);
    const [deciding, setDeciding] = useState<{ ids: number[]; decision: 'approve' | 'reject' } | null>(null);
    const [notes, setNotes] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [delegating, setDelegating] = useState(false);

    const go = (params: Record<string, string | null>) =>
        router.get('/approvals', { tab, type, ...params }, { preserveState: false, preserveScroll: true });
    const decidable = items.filter((i) => i.can_decide);
    const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const submit = () => {
        if (!deciding) return;
        if (deciding.decision === 'reject' && !notes.trim()) return setError('Tulis alasan penolakan.');
        setBusy(true);
        const done = { onSuccess: () => (setDeciding(null), setSelected([]), setNotes('')), onError: (e: Record<string, string>) => setError(Object.values(e)[0] ?? null), onFinish: () => setBusy(false), preserveScroll: true };
        if (deciding.ids.length === 1) router.post(`/approvals/${deciding.ids[0]}/${deciding.decision}`, { notes }, done);
        else router.post('/approvals/bulk', { ids: deciding.ids, decision: deciding.decision, notes }, done);
    };
    const ask = (ids: number[], decision: 'approve' | 'reject') => (setDeciding({ ids, decision }), setNotes(''), setError(null));

    const tabs: { key: Tab; label: string }[] = [
        { key: 'pending', label: `Menunggu saya${counts.pending ? ` (${counts.pending})` : ''}` },
        { key: 'decided', label: 'Sudah saya putuskan' },
        ...(has_own ? [{ key: 'mine' as Tab, label: 'Pengajuan saya' }] : []),
    ];

    const body = (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-1 rounded-2xl bg-white p-1 shadow-sm ring-1 ring-slate-200">
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            onClick={() => go({ tab: t.key })}
                            className={`rounded-xl px-3 py-1.5 text-sm font-bold ${tab === t.key ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-100'}`}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <button onClick={() => setDelegating(true)} className="ml-auto inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-slate-600 hover:bg-white">
                    <UserRoundCheck size={15} /> Delegasi
                </button>
                <select value={type ?? ''} onChange={(e) => go({ type: e.target.value || null })} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                    <option value="">Semua jenis</option>
                    {types.map((t) => (
                        <option key={t.value} value={t.value}>
                            {t.label}
                        </option>
                    ))}
                </select>
            </div>

            {acting_for.length > 0 && (
                <p className="rounded-2xl bg-sky-50 px-4 py-2.5 text-sm text-sky-800">
                    Hari ini Anda juga menyetujui atas nama <b>{acting_for.join(', ')}</b> (delegasi).
                </p>
            )}

            {tab === 'pending' && decidable.length > 1 && (
                <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <label className="flex items-center gap-2 text-sm font-semibold text-slate-600">
                        <input
                            type="checkbox"
                            checked={selected.length === decidable.length}
                            onChange={(e) => setSelected(e.target.checked ? decidable.map((i) => i.id) : [])}
                        />
                        Pilih semua
                    </label>
                    <span className="text-sm text-slate-400">{selected.length} dipilih</span>
                    <div className="ml-auto flex gap-2">
                        <button disabled={!selected.length} onClick={() => ask(selected, 'reject')} className="rounded-xl px-3 py-1.5 text-sm font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-40">
                            Tolak
                        </button>
                        <button disabled={!selected.length} onClick={() => ask(selected, 'approve')} className={primaryButton}>
                            <Check size={15} /> Setujui
                        </button>
                    </div>
                </div>
            )}

            {items.length === 0 && (
                <div className="flex flex-col items-center gap-2 py-16 text-center text-sm text-slate-400">
                    <Inbox size={28} />
                    {tab === 'pending' ? 'Tidak ada pengajuan yang menunggu persetujuan Anda.' : 'Belum ada pengajuan.'}
                </div>
            )}

            {items.map((i) => (
                <section key={i.id} className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-start gap-3 p-4 sm:p-5">
                        {tab === 'pending' && i.can_decide && decidable.length > 1 && (
                            <input type="checkbox" className="mt-1.5" checked={selected.includes(i.id)} onChange={() => toggle(i.id)} aria-label="Pilih" />
                        )}
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600">{i.type_label}</span>
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${STATUS_TONE[i.status]}`}>
                                    {i.status === 'pending' && i.step ? i.step : i.status_label}
                                </span>
                                <span className="text-xs text-slate-400">diajukan {date(i.submitted_at)}</span>
                            </div>
                            {i.employee && (
                                <p className="mt-1.5 font-bold text-slate-800">
                                    {i.employee.name} <span className="font-normal text-slate-400">· {i.employee.employee_number}</span>
                                </p>
                            )}
                            {i.employee && (i.employee.position || i.employee.location) && (
                                <p className="text-xs text-slate-500">{[i.employee.position, i.employee.location].filter(Boolean).join(' · ')}</p>
                            )}
                            {i.summary && <p className="mt-1 text-sm text-slate-600">{i.summary}</p>}
                            <div className="mt-2 flex flex-wrap items-center gap-3">
                                <button onClick={() => setOpen(open === i.id ? null : i.id)} className="inline-flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline">
                                    <ChevronDown size={14} className={open === i.id ? 'rotate-180' : ''} /> Riwayat persetujuan
                                </button>
                                {i.url && (
                                    <Link href={i.url} className="inline-flex items-center gap-1 text-xs font-bold text-slate-500 hover:underline">
                                        <ExternalLink size={13} /> Buka pengajuan
                                    </Link>
                                )}
                                {i.can_cancel && tab === 'mine' && (
                                    <button
                                        onClick={() => confirm('Batalkan pengajuan ini?') && router.post(`/approvals/${i.id}/cancel`, {}, { preserveScroll: true })}
                                        className="inline-flex items-center gap-1 text-xs font-bold text-rose-600 hover:underline"
                                    >
                                        <X size={13} /> Batalkan
                                    </button>
                                )}
                            </div>
                        </div>
                        {i.can_decide && (
                            <div className="flex shrink-0 flex-col gap-1.5 sm:flex-row">
                                <button onClick={() => ask([i.id], 'reject')} className="rounded-xl px-3 py-1.5 text-sm font-bold text-rose-600 hover:bg-rose-50">
                                    Tolak
                                </button>
                                <button onClick={() => ask([i.id], 'approve')} className={primaryButton}>
                                    <Check size={15} /> Setujui
                                </button>
                            </div>
                        )}
                    </div>
                    {open === i.id && (
                        <div className="border-t border-slate-100 px-4 py-4 sm:px-5">
                            <ApprovalHistory steps={i.steps} events={i.events} />
                        </div>
                    )}
                </section>
            ))}
        </div>
    );

    return (
        <>
            <Head title="Persetujuan Saya" />
            {portal ? (
                <div className="space-y-4">
                    <h1 className="text-xl font-black text-slate-800">Persetujuan</h1>
                    {body}
                </div>
            ) : (
                <>
                    <AttendanceHeader title="Persetujuan Saya" subtitle="Pengajuan yang menunggu keputusan Anda, dari semua modul" />
                    <main className="flex-1 overflow-y-auto bg-slate-50 p-6">
                        <div className="mx-auto max-w-4xl">{body}</div>
                    </main>
                </>
            )}
            {delegating && <DelegationDialog onClose={() => (setDelegating(false), router.reload())} />}
            {deciding && (
                <Modal
                    title={`${deciding.decision === 'approve' ? 'Setujui' : 'Tolak'} ${deciding.ids.length > 1 ? `${deciding.ids.length} pengajuan` : 'pengajuan'}`}
                    onClose={() => setDeciding(null)}
                    footer={
                        <>
                            <button onClick={() => setDeciding(null)} className={ghostButton}>
                                Batal
                            </button>
                            <button
                                onClick={submit}
                                disabled={busy}
                                className={deciding.decision === 'approve' ? primaryButton : 'inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700 disabled:opacity-50'}
                            >
                                {busy && <Loader2 size={15} className="animate-spin" />} {deciding.decision === 'approve' ? 'Setujui' : 'Tolak'}
                            </button>
                        </>
                    }
                >
                    <label className="mb-1.5 block text-xs font-bold text-slate-500">
                        {deciding.decision === 'approve' ? 'Catatan (opsional)' : 'Alasan penolakan'}
                    </label>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} className={inputClass} maxLength={500} autoFocus />
                    {error && <p className="mt-2 text-sm font-semibold text-rose-600">{error}</p>}
                </Modal>
            )}
        </>
    );
}
