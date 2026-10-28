import { Check, CircleDashed, Clock, MinusCircle, Send, X } from 'lucide-react';

export interface ApprovalAction {
    decision: 'submitted' | 'approved' | 'rejected' | 'cancelled';
    user: string | null;
    notes: string | null;
    at: string | null;
}

export interface ApprovalStep {
    sequence: number;
    name: string;
    approver: string;
    condition: string | null;
    mode: 'any' | 'all';
    state: 'approved' | 'rejected' | 'current' | 'upcoming' | 'skipped' | 'stopped';
    waiting_for: string[];
    actions: ApprovalAction[];
}

const DECISION: Record<ApprovalAction['decision'], string> = {
    submitted: 'Diajukan',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    cancelled: 'Dibatalkan',
};

const when = (at: string | null) =>
    at ? new Date(at).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

function Dot({ state }: { state: ApprovalStep['state'] }) {
    const tone = {
        approved: 'bg-brand-600 text-white',
        rejected: 'bg-rose-600 text-white',
        current: 'bg-amber-100 text-amber-700 ring-2 ring-amber-300',
        upcoming: 'bg-slate-100 text-slate-400',
        skipped: 'bg-slate-50 text-slate-300',
        stopped: 'bg-slate-100 text-slate-400',
    }[state];
    const Icon = { approved: Check, rejected: X, current: Clock, upcoming: CircleDashed, skipped: MinusCircle, stopped: MinusCircle }[state];
    return (
        <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${tone}`}>
            <Icon size={13} />
        </span>
    );
}

/** The steps of a pengajuan's approval flow: who decided what and when, who it waits for, what is still to come. */
export function ApprovalHistory({ steps, events = [] }: { steps: ApprovalStep[]; events?: ApprovalAction[] }) {
    return (
        <ol className="space-y-3">
            {events.map((e, i) => (
                <li key={`e${i}`} className="flex gap-3">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                        <Send size={12} />
                    </span>
                    <p className="text-sm text-slate-600">
                        <span className="font-bold text-slate-700">{DECISION[e.decision]}</span>
                        {e.user && ` oleh ${e.user}`} <span className="text-xs text-slate-400">· {when(e.at)}</span>
                    </p>
                </li>
            ))}
            {steps.map((s) => (
                <li key={s.sequence} className={`flex gap-3 ${s.state === 'skipped' ? 'opacity-60' : ''}`}>
                    <Dot state={s.state} />
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-sm">
                            <span className="font-bold text-slate-700">{s.name}</span>
                            <span className="text-slate-400"> · {s.approver}</span>
                            {s.mode === 'all' && <span className="text-slate-400"> · semua harus setuju</span>}
                        </p>
                        {s.condition && <p className="text-xs text-slate-400">Berlaku bila {s.condition}</p>}
                        {s.state === 'skipped' && <p className="text-xs text-slate-400">Dilewati: syarat tidak terpenuhi.</p>}
                        {s.state === 'current' && s.waiting_for.length > 0 && (
                            <p className="text-xs font-semibold text-amber-700">Menunggu {s.waiting_for.join(', ')}</p>
                        )}
                        {s.actions.map((a, i) => (
                            <div key={i} className="text-xs text-slate-500">
                                <span className={`font-bold ${a.decision === 'rejected' ? 'text-rose-600' : a.decision === 'approved' ? 'text-brand-700' : 'text-slate-600'}`}>
                                    {DECISION[a.decision]}
                                </span>
                                {a.user && ` oleh ${a.user}`} · {when(a.at)}
                                {a.notes && <p className="mt-0.5 rounded-lg bg-slate-50 px-2 py-1 text-slate-600">“{a.notes}”</p>}
                            </div>
                        ))}
                    </div>
                </li>
            ))}
        </ol>
    );
}
