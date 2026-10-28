import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, FileSignature, Loader2, Undo2, X } from 'lucide-react';
import { FlowNote } from '@/components/ApprovalHistory';
import { DateInput } from '@/components/DateInput';
import { rupiah } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';
import { postJson } from '@/lib/http';
import type { CandidateDetail, JobOffer } from '@/lib/recruitment';

const input = 'w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

const TONE: Record<JobOffer['status'], string> = {
    pending: 'bg-amber-50 text-amber-700',
    approved: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-rose-50 text-rose-600',
    cancelled: 'bg-slate-100 text-slate-500',
};

/**
 * Penawaran Kerja in the candidate window: the offers made, where a waiting one is in its
 * approval flow (decided here by its approver), and the form to propose a new one.
 */
export function OfferSection({ c, canEdit, onChanged }: { c: CandidateDetail; canEdit: boolean; onChanged: () => void }) {
    const pending = c.offers.find((o) => o.status === 'pending');
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({
        position_id: c.job_position ? String(c.job_position.id) : '',
        salary: '',
        start_date: '',
        notes: '',
    });

    const submit = async () => {
        setBusy(true);
        try {
            await postJson(`/recruitment/candidate/${c.id}/offer`, {
                position_id: Number(form.position_id) || null,
                salary: Number(form.salary.replace(/\D/g, '')) || null,
                start_date: form.start_date || null,
                notes: form.notes || null,
            });
            setOpen(false);
            onChanged();
        } catch (e) {
            alert((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    const cancel = async (o: JobOffer) => {
        if (!confirm('Batalkan penawaran ini? Alur persetujuannya ikut berhenti.')) return;
        await postJson(`/recruitment/offers/${o.id}/cancel`, {}).catch((e: Error) => alert(e.message));
        onChanged();
    };

    const decide = (o: JobOffer, approve: boolean) => {
        if (!o.workflow) return;
        const notes = approve ? '' : prompt('Alasan penolakan:');
        if (!approve && !notes?.trim()) return;
        router.post(`/approvals/${o.workflow.id}/${approve ? 'approve' : 'reject'}`, { notes }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onChanged,
            onError: (errors) => alert(Object.values(errors)[0]),
        });
    };

    if (c.offers.length === 0 && (!canEdit || c.employee || c.rejected)) return null;

    return (
        <section>
            <h3 className="mb-2 flex items-center gap-2 text-xs font-bold tracking-widest text-slate-500 uppercase">
                <FileSignature size={14} /> Penawaran kerja
            </h3>
            <div className="space-y-2">
                {c.offers.map((o) => (
                    <div key={o.id} className="rounded-2xl border border-slate-200 p-3 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <b className="text-slate-700">{o.position ?? '-'}</b>
                            {o.work_location && <span className="text-slate-400">· {o.work_location}</span>}
                            <span className={`ml-auto rounded-full px-2.5 py-0.5 text-[11px] font-bold ${TONE[o.status]}`}>{o.status_label}</span>
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Gaji Rp {rupiah(o.salary)} · mulai {fmtDate(o.start_date)}
                        </p>
                        {o.notes && <p className="mt-1 text-xs whitespace-pre-line text-slate-500">{o.notes}</p>}
                        {o.decision_notes && <p className="mt-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600">“{o.decision_notes}”</p>}
                        {o.workflow && <FlowNote flow={o.workflow} />}
                        {o.status === 'pending' && (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {o.workflow?.can_decide && (
                                    <>
                                        <button
                                            onClick={() => decide(o, true)}
                                            className="flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-brand-700"
                                        >
                                            <Check size={13} /> Setujui
                                        </button>
                                        <button
                                            onClick={() => decide(o, false)}
                                            className="flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold text-rose-600 hover:bg-rose-50"
                                        >
                                            <X size={13} /> Tolak
                                        </button>
                                    </>
                                )}
                                {canEdit && (
                                    <button
                                        onClick={() => cancel(o)}
                                        className="flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100"
                                    >
                                        <Undo2 size={13} /> Batalkan
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                ))}

                {canEdit && !pending && !c.employee && !c.rejected && !open && (
                    <button
                        onClick={() => setOpen(true)}
                        className="rounded-xl border border-dashed border-brand-300 px-3 py-2 text-xs font-bold text-brand-700 hover:bg-brand-50"
                    >
                        + Ajukan penawaran untuk disetujui
                    </button>
                )}

                {open && (
                    <div className="space-y-2 rounded-2xl border border-brand-200 bg-brand-50/40 p-3">
                        <label className="block text-xs font-bold text-slate-500">
                            Jabatan *
                            <select value={form.position_id} onChange={(e) => setForm({ ...form, position_id: e.target.value })} className={`${input} mt-1 bg-white`}>
                                <option value="">Pilih jabatan</option>
                                {c.offer_positions.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <label className="block text-xs font-bold text-slate-500">
                                Gaji yang ditawarkan (Rp) *
                                <input
                                    value={form.salary}
                                    inputMode="numeric"
                                    onChange={(e) => setForm({ ...form, salary: e.target.value.replace(/\D/g, '') ? Number(e.target.value.replace(/\D/g, '')).toLocaleString('id-ID') : '' })}
                                    className={`${input} mt-1 bg-white`}
                                />
                            </label>
                            <label className="block text-xs font-bold text-slate-500">
                                Tanggal mulai *
                                <DateInput value={form.start_date} onChange={(v) => setForm({ ...form, start_date: v })} className={`${input} mt-1 bg-white`} />
                            </label>
                        </div>
                        <label className="block text-xs font-bold text-slate-500">
                            Catatan untuk penyetuju
                            <textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className={`${input} mt-1 min-h-16 bg-white`} />
                        </label>
                        <div className="flex justify-end gap-2">
                            <button onClick={() => setOpen(false)} className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-white">
                                Batal
                            </button>
                            <button
                                onClick={submit}
                                disabled={busy || !form.position_id || !form.salary || !form.start_date}
                                className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                            >
                                {busy && <Loader2 size={13} className="animate-spin" />} Ajukan
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
