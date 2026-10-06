import { useEffect, useRef, useState } from 'react';
import { CalendarDays } from 'lucide-react';
import { dotsToIso, isoToDots } from '@/lib/format';

interface Props {
    /** YYYY-MM-DD, or '' when empty. */
    value: string;
    /** Called with YYYY-MM-DD once the typed date is complete and valid, '' while it is empty or incomplete. */
    onChange: (iso: string) => void;
    /** When the date is committed: the field loses focus, or a day is picked from the calendar. */
    onBlur?: () => void;
    className?: string;
    min?: string;
    max?: string;
    autoFocus?: boolean;
    disabled?: boolean;
    'aria-label'?: string;
}

/**
 * A date field shown as dd.mm.yyyy whatever the browser's locale: typed (the dots
 * are added as you go) or picked from the browser's calendar.
 */
export function DateInput({ value, onChange, onBlur, className = '', min, max, autoFocus, disabled, ...rest }: Props) {
    const [text, setText] = useState(() => isoToDots(value));
    const picker = useRef<HTMLInputElement>(null);
    const latestBlur = useRef(onBlur);
    latestBlur.current = onBlur;

    // Follow a value set from outside (e.g. a reset), but not while the typed text already means it.
    useEffect(() => {
        if ((dotsToIso(text) ?? '') !== value) setText(isoToDots(value));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value]);

    const type = (raw: string) => {
        let next = raw.replace(/[^\d.]/g, '');
        // Typing digits only: put the dots in after the day and the month.
        if (next.length > text.length && !next.includes('.', text.length)) {
            const digits = next.replace(/\./g, '');
            if (/^\d+$/.test(digits) && digits.length <= 8 && next.split('.').length - 1 < 2) {
                next = [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4, 8)].filter(Boolean).join('.');
                if ((digits.length === 2 || digits.length === 4) && next.length < 10) next += '.';
            }
        }
        next = next.slice(0, 10);
        setText(next);
        onChange(dotsToIso(next) ?? '');
    };

    const blur = () => {
        const iso = dotsToIso(text);
        // A partial date is dropped on leaving the field, so what is shown is what is kept.
        if (!iso && text) setText(isoToDots(value));
        onBlur?.();
    };

    return (
        <div className={`relative flex items-center ${disabled ? 'opacity-60' : ''}`}>
            <input
                type="text"
                inputMode="numeric"
                value={text}
                onChange={(e) => type(e.target.value)}
                onBlur={blur}
                placeholder="dd.mm.yyyy"
                autoFocus={autoFocus}
                disabled={disabled}
                aria-label={rest['aria-label']}
                className={`${className} pr-9 tabular-nums`}
            />
            <button
                type="button"
                tabIndex={-1}
                disabled={disabled}
                onClick={() => {
                    try {
                        picker.current?.showPicker();
                    } catch {
                        picker.current?.focus();
                    }
                }}
                title="Pilih dari kalender"
                aria-label="Pilih dari kalender"
                className="absolute right-1.5 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
            >
                <CalendarDays size={15} />
            </button>
            {/* The browser's own calendar; only its picker is used. */}
            <input
                ref={picker}
                type="date"
                tabIndex={-1}
                aria-hidden="true"
                value={value}
                min={min}
                max={max}
                onChange={(e) => {
                    setText(isoToDots(e.target.value));
                    onChange(e.target.value);
                    requestAnimationFrame(() => latestBlur.current?.());
                }}
                className="pointer-events-none absolute right-0 bottom-0 h-0 w-0 opacity-0"
            />
        </div>
    );
}
