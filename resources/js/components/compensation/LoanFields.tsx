import { Field, inputClass } from '@/components/time/ui';
import { rupiah } from '@/lib/compensation';
import { firstInstallment, periodLabel, periodsFrom } from '@/lib/loans';

/** Amount, tenor, first period and purpose of a loan (HR form and the portal request). */
export function LoanFields({
    data,
    setData,
    errors,
    next,
}: {
    data: { principal: number; tenor: number; start_period: string; purpose: string };
    setData: (key: 'principal' | 'tenor' | 'start_period' | 'purpose', value: never) => void;
    errors: Partial<Record<string, string>>;
    next: string;
}) {
    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Jumlah pinjaman (Rp)">
                    <input type="number" min={100000} step={100000} value={data.principal || ''} onChange={(e) => setData('principal', Number(e.target.value) as never)} className={inputClass} />
                    {errors.principal && <p className="mt-1 text-xs font-semibold text-rose-600">{errors.principal}</p>}
                </Field>
                <Field label="Tenor (bulan)" hint={data.principal && data.tenor ? `Angsuran ± Rp ${rupiah(firstInstallment(data.principal, data.tenor))} / bulan` : undefined}>
                    <input type="number" min={1} max={60} value={data.tenor || ''} onChange={(e) => setData('tenor', Number(e.target.value) as never)} className={inputClass} />
                </Field>
            </div>
            {errors.tenor && <p className="text-xs font-semibold text-rose-600">{errors.tenor}</p>}
            <Field label="Mulai dipotong">
                <select value={data.start_period} onChange={(e) => setData('start_period', e.target.value as never)} className={inputClass}>
                    {periodsFrom(next, 6).map((p) => (
                        <option key={p} value={p}>
                            Gaji {periodLabel(p)}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Keperluan">
                <input value={data.purpose} onChange={(e) => setData('purpose', e.target.value as never)} maxLength={255} className={inputClass} />
            </Field>
        </>
    );
}
