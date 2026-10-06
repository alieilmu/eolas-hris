import { DateInput } from '@/components/DateInput';
import type { FieldValue, ProfileField } from '@/lib/dataUpdate';

const base =
    'w-full rounded-xl border bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-500';

/** One profile field, by its type. */
export function FieldInput({
    field,
    value,
    onChange,
    changed,
    invalid,
    disabled,
}: {
    field: ProfileField;
    value: FieldValue;
    onChange: (value: FieldValue) => void;
    changed?: boolean;
    invalid?: boolean;
    disabled?: boolean;
}) {
    const className = `${base} ${invalid ? 'border-rose-300' : changed ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'}`;
    const text = value === null || value === undefined ? '' : String(value);

    switch (field.type) {
        case 'select':
            return (
                <select
                    value={text}
                    onChange={(e) => onChange(e.target.value || null)}
                    disabled={disabled}
                    className={className}
                    aria-label={field.label}
                >
                    <option value="">Pilih...</option>
                    {field.options?.map((o) => (
                        <option key={o} value={o}>
                            {o}
                        </option>
                    ))}
                </select>
            );
        case 'textarea':
            return (
                <textarea
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    rows={2}
                    disabled={disabled}
                    className={`${className} resize-none`}
                    aria-label={field.label}
                />
            );
        case 'date':
            return (
                <DateInput value={text} onChange={(v) => onChange(v || null)} disabled={disabled} className={className} aria-label={field.label} />
            );
        default:
            return (
                <input
                    type="text"
                    inputMode={
                        field.type === 'number' || /nik|npwp|bpjs|rekening|hp|telepon|whatsapp|kode_pos|_rt$|_rw$/.test(field.key)
                            ? 'numeric'
                            : undefined
                    }
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    className={className}
                    aria-label={field.label}
                />
            );
    }
}
