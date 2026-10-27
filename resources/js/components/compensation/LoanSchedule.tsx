import { CalendarClock } from 'lucide-react';
import { rupiah } from '@/lib/compensation';
import { periodLabel, type LoanRow } from '@/lib/loans';

/** A loan's installments: period, amount, and whether it was deducted, with an optional action per open one. */
export function LoanSchedule({ loan, onPostpone }: { loan: LoanRow; onPostpone?: (installmentId: number) => void }) {
    if (loan.installments.length === 0) {
        return <p className="text-xs text-slate-400">Jadwal angsuran dibuat setelah pinjaman disetujui.</p>;
    }

    return (
        <table className="w-full text-sm">
            <thead className="text-left text-xs text-slate-400">
                <tr>
                    <th className="py-1 font-semibold">Ke</th>
                    <th className="py-1 font-semibold">Periode</th>
                    <th className="py-1 text-right font-semibold">Angsuran</th>
                    <th className="py-1 pl-3 font-semibold">Status</th>
                    {onPostpone && <th />}
                </tr>
            </thead>
            <tbody>
                {loan.installments.map((i) => (
                    <tr key={i.id} className="border-t border-slate-100">
                        <td className="py-1.5 text-slate-500 tabular-nums">{i.sequence}</td>
                        <td className="py-1.5">{periodLabel(i.period)}</td>
                        <td className="py-1.5 text-right tabular-nums">{rupiah(i.amount)}</td>
                        <td className={`py-1.5 pl-3 text-xs ${i.status === 'scheduled' ? 'text-slate-500' : 'font-semibold text-brand-700'}`}>
                            {i.status_label}
                            {i.notes && <span className="block text-[11px] text-slate-400">{i.notes}</span>}
                        </td>
                        {onPostpone && (
                            <td className="py-1.5 text-right">
                                {i.status === 'scheduled' && loan.status === 'active' && (
                                    <button onClick={() => onPostpone(i.id)} className="inline-flex items-center gap-1 text-xs font-bold text-slate-500 hover:text-brand-700">
                                        <CalendarClock size={13} /> Tunda
                                    </button>
                                )}
                            </td>
                        )}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
