import { rupiah } from '@/lib/compensation';

/** A Rupiah amount typed as digits and shown with thousand dots ("2.250.000"). */
export function MoneyInput({
    value,
    onChange,
    className = '',
    disabled,
    placeholder,
    'aria-label': ariaLabel,
}: {
    value: number | null;
    onChange: (value: number | null) => void;
    className?: string;
    disabled?: boolean;
    placeholder?: string;
    'aria-label'?: string;
}) {
    return (
        <input
            inputMode="numeric"
            value={value === null ? '' : rupiah(value)}
            onChange={(e) => {
                const digits = e.target.value.replace(/\D/g, '').slice(0, 12);
                onChange(digits === '' ? null : Number(digits));
            }}
            disabled={disabled}
            placeholder={placeholder}
            aria-label={ariaLabel}
            className={`text-right tabular-nums ${className}`}
        />
    );
}
