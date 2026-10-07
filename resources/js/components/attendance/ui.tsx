import { useEffect, type ReactNode } from 'react';
import { Briefcase, ChevronLeft, ChevronRight, Palmtree, X } from 'lucide-react';
import type { PeriodInfo, PeriodOption } from '@/lib/attendance';

export const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
export const smallInput = 'rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/** Page title bar of the Attendance pages. */
export function AttendanceHeader({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-4 border-b-[3px] border-accent-400 bg-white px-8">
            <div className="min-w-0">
                <h1 className="truncate text-xl font-bold tracking-tight text-slate-800">{title}</h1>
                {subtitle && <p className="truncate text-[11px] text-slate-400">{subtitle}</p>}
            </div>
            <div className="flex shrink-0 items-center gap-2">{children}</div>
        </header>
    );
}

/** ‹ Oktober 2026 › — previous / next month buttons around a dropdown of periods. */
export function PeriodPicker({ period, periods, onChange }: { period: PeriodInfo; periods: PeriodOption[]; onChange: (value: string) => void }) {
    const options = periods.some((p) => p.value === period.value) ? periods : [{ value: period.value, label: period.label }, ...periods];
    const button = 'rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-white hover:text-brand-600 hover:shadow-sm';

    return (
        <div className="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
            <button onClick={() => onChange(period.prev)} title="Bulan sebelumnya" aria-label="Bulan sebelumnya" className={button}>
                <ChevronLeft size={17} />
            </button>
            <select
                value={period.value}
                onChange={(e) => onChange(e.target.value)}
                aria-label="Periode"
                className="min-w-[150px] cursor-pointer rounded-lg border-0 bg-white px-3 py-1.5 text-center text-sm font-bold text-slate-700 shadow-sm outline-none focus:ring-2 focus:ring-brand-500"
            >
                {options.map((p) => (
                    <option key={p.value} value={p.value}>
                        {p.label}
                    </option>
                ))}
            </select>
            <button onClick={() => onChange(period.next)} title="Bulan berikutnya" aria-label="Bulan berikutnya" className={button}>
                <ChevronRight size={17} />
            </button>
        </div>
    );
}

/** A centred dialog with a title bar; Esc closes it. */
export function Modal({
    title,
    subtitle,
    onClose,
    children,
    footer,
    width = 'max-w-lg',
}: {
    title: string;
    subtitle?: ReactNode;
    onClose: () => void;
    children: ReactNode;
    footer?: ReactNode;
    width?: string;
}) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 backdrop-blur-sm sm:items-center sm:p-6"
            onMouseDown={onClose}
        >
            <div
                className={`flex max-h-[92dvh] w-full min-w-0 ${width} flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-h-full sm:rounded-3xl`}
                onMouseDown={(e) => e.stopPropagation()}
            >
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-100 px-4 py-4 sm:px-6 sm:py-5">
                    <div className="min-w-0">
                        <h3 className="font-bold text-slate-800">{title}</h3>
                        {subtitle && <div className="mt-0.5 text-xs text-slate-400">{subtitle}</div>}
                    </div>
                    <button onClick={onClose} className="rounded-xl p-1.5 text-slate-400 hover:bg-slate-100" aria-label="Tutup">
                        <X size={18} />
                    </button>
                </div>
                <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">{children}</div>
                {footer && (
                    <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-slate-100 px-4 py-3 sm:gap-3 sm:px-6 sm:py-4">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}

export function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: ReactNode }) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-bold text-slate-500">{label}</label>
            {children}
            {hint && <p className="mt-1 text-[11px] text-slate-400">{hint}</p>}
        </div>
    );
}

export const primaryButton =
    'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-all hover:bg-brand-700 disabled:opacity-50';
export const secondaryButton =
    'inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-50 disabled:opacity-50';
export const ghostButton = 'rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100';

export function JenisBadge({ jenis }: { jenis: 'dinas' | 'cuti' }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ${jenis === 'dinas' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'}`}
        >
            {jenis === 'dinas' ? <Briefcase size={11} /> : <Palmtree size={11} />}
            {jenis === 'dinas' ? 'Dinas' : 'Cuti'}
        </span>
    );
}

export interface SubLocationOption {
    id: number;
    name: string;
    work_location_id: number;
}

/** "Tanpa sub lokasi": people at the work location itself, not in any Sub Lokasi Kerja. */
export const NO_SUB_LOCATION = 'none';

/** Whether a row with this sub_location_id passes the Sub Lokasi filter ('' = all, 'none', or an id). */
export function matchesSubLocation(filter: string, subLocationId: number | null): boolean {
    if (!filter) return true;
    if (filter === NO_SUB_LOCATION) return subLocationId === null;
    return String(subLocationId) === filter;
}

/** Sub Lokasi Kerja filter, grouped by work location (only the chosen one's when a location is picked). */
export function SubLocationSelect({
    value,
    onChange,
    subLocations,
    locations,
    lokasi,
}: {
    value: string;
    onChange: (value: string) => void;
    subLocations: SubLocationOption[];
    locations: { id: number; name: string }[];
    /** The chosen work location id ('' or null = all). */
    lokasi: string | number | null;
}) {
    const groups = locations
        .filter((l) => !lokasi || String(l.id) === String(lokasi))
        .map((l) => ({ location: l, subs: subLocations.filter((s) => s.work_location_id === l.id) }))
        .filter((g) => g.subs.length > 0);

    return (
        <select value={value} onChange={(e) => onChange(e.target.value)} className={`${smallInput} max-w-56`} aria-label="Sub lokasi kerja">
            <option value="">Semua sub lokasi</option>
            <option value={NO_SUB_LOCATION}>Tanpa sub lokasi (di lokasi induk)</option>
            {groups.map((g) => (
                <optgroup key={g.location.id} label={g.location.name}>
                    {g.subs.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}

/** A Sub Lokasi filter that no longer belongs to the chosen work location falls back to all. */
export function subLocationFor(sub: string, lokasi: string, subLocations: SubLocationOption[]): string {
    if (!sub || sub === NO_SUB_LOCATION || !lokasi) return sub;
    return subLocations.some((s) => String(s.id) === sub && String(s.work_location_id) === lokasi) ? sub : '';
}
