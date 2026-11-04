import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import type { WorkflowBrief } from '@/components/ApprovalHistory';
import { Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';

export interface OvertimeFormInfo {
    /** The job level gets overtime at this kind of location. */
    eligible: boolean;
    /** The earliest day that may still be asked for. */
    earliest: string;
    minimum_minutes: number;
    daily_limit_hours: number;
}

export interface OvertimeRow {
    id: number;
    date: string;
    summary: string;
    start: string | null;
    end: string | null;
    status: 'pending' | 'approved' | 'rejected';
    /** device: counted from the punches; portal: asked for here. */
    source: string;
    reason: string | null;
    verification_notes: string | null;
    workflow: WorkflowBrief | null;
}

/**
 * Ajukan Lembur: overtime the machines did not count (no machine there, work after the last
 * punch) — the day, from when to when, and what the work was. The server counts the hours as
 * the processing does and sends it through the overtime approval flow.
 */
export function OvertimeForm({ info, today, onClose }: { info: OvertimeFormInfo; today: string; onClose: () => void }) {
    const form = useForm({ date: today, start: '17:00', end: '19:00', reason: '' });
    const span = (() => {
        const [sh, sm] = form.data.start.split(':').map(Number);
        const [eh, em] = form.data.end.split(':').map(Number);
        if ([sh, sm, eh, em].some((n) => Number.isNaN(n))) return null;
        let minutes = eh * 60 + em - (sh * 60 + sm);
        if (minutes <= 0) minutes += 24 * 60;
        return minutes;
    })();
    const submit = () => form.post('/self-service/overtime', { preserveScroll: true, onSuccess: onClose });

    return (
        <Modal
            title="Ajukan Lembur"
            subtitle="Untuk lembur yang tidak tercatat mesin absen. Dikirim ke atasan / HR sesuai alur persetujuan."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={form.processing} className={primaryButton}>
                        {form.processing && <Loader2 size={15} className="animate-spin" />} Kirim
                    </button>
                </>
            }
        >
            <div className="space-y-3">
                <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Tanggal lembur</span>
                    <DateInput value={form.data.date} onChange={(v) => form.setData('date', v)} min={info.earliest} max={today} className={inputClass} />
                    {form.errors.date && <span className="mt-1 block text-xs text-rose-600">{form.errors.date}</span>}
                </label>
                <div className="grid grid-cols-2 gap-3">
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-bold text-slate-500">Mulai</span>
                        <input type="time" value={form.data.start} onChange={(e) => form.setData('start', e.target.value)} className={inputClass} />
                        {form.errors.start && <span className="mt-1 block text-xs text-rose-600">{form.errors.start}</span>}
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[11px] font-bold text-slate-500">Selesai</span>
                        <input type="time" value={form.data.end} onChange={(e) => form.setData('end', e.target.value)} className={inputClass} />
                        {form.errors.end && <span className="mt-1 block text-xs text-rose-600">{form.errors.end}</span>}
                    </label>
                </div>
                {span !== null && (
                    <p className="text-xs text-slate-500">
                        {Math.floor(span / 60)} jam {span % 60 ? `${span % 60} menit` : ''}
                        {form.data.end <= form.data.start ? ' (selesai keesokan hari)' : ''} · paling lama {info.daily_limit_hours} jam sehari
                        {info.minimum_minutes ? `, minimal ${info.minimum_minutes} menit` : ''}. Jam dibayar dihitung server sesuai jenis hari.
                    </p>
                )}
                <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Pekerjaan yang dilemburkan</span>
                    <textarea
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        rows={3}
                        maxLength={500}
                        className={inputClass}
                        placeholder="Mis. perbaikan pompa pit 3, diminta pengawas shift"
                    />
                    {form.errors.reason && <span className="mt-1 block text-xs text-rose-600">{form.errors.reason}</span>}
                </label>
            </div>
        </Modal>
    );
}
