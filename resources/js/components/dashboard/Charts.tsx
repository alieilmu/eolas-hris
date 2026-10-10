import { useEffect, useRef, useState } from 'react';
import { animate, motion } from 'framer-motion';

/** Green-first palette for the dashboard series (works on white). */
export const PALETTE = ['#15803d', '#22c55e', '#86efac', '#0d9488', '#65a30d', '#a3e635', '#14532d', '#5eead4', '#bbf7d0', '#94a3b8'];

export const rupiah = (n: number) => `Rp ${new Intl.NumberFormat('id-ID').format(Math.round(n))}`;
export const compactRupiah = (n: number) =>
    n >= 1e9 ? `Rp ${(n / 1e9).toFixed(1).replace('.', ',')} M` : n >= 1e6 ? `Rp ${(n / 1e6).toFixed(1).replace('.', ',')} jt` : rupiah(n);
export const num = (n: number) => new Intl.NumberFormat('id-ID').format(n);

/** A number that counts up when it appears or changes. */
export function CountUp({ value, format = num, className }: { value: number; format?: (n: number) => string; className?: string }) {
    const ref = useRef<HTMLSpanElement>(null);
    const last = useRef(0);
    useEffect(() => {
        const controls = animate(last.current, value, {
            duration: 0.9,
            ease: 'easeOut',
            onUpdate: (v) => {
                if (ref.current) ref.current.textContent = format(v);
            },
        });
        last.current = value;
        return () => controls.stop();
    }, [value, format]);
    return (
        <span ref={ref} className={className}>
            {format(0)}
        </span>
    );
}

export interface Slice {
    label: string;
    value: number;
    color?: string;
}

/** Donut with its legend; hovering a slice or a legend row highlights it. */
export function Donut({ data, size = 168, center }: { data: Slice[]; size?: number; center?: string }) {
    const [hover, setHover] = useState<number | null>(null);
    const total = data.reduce((s, d) => s + d.value, 0);
    const r = size / 2 - 14;
    const c = 2 * Math.PI * r;
    let offset = 0;
    const shown = hover !== null ? data[hover] : null;

    return (
        <div className="flex flex-col items-center gap-5 sm:flex-row">
            <div className="relative shrink-0" style={{ width: size, height: size }}>
                <svg viewBox={`0 0 ${size} ${size}`} className="-rotate-90">
                    <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#f1f5f9" strokeWidth={22} />
                    {total > 0 &&
                        data.map((d, i) => {
                            const len = (d.value / total) * c;
                            const el = (
                                <motion.circle
                                    key={d.label}
                                    cx={size / 2}
                                    cy={size / 2}
                                    r={r}
                                    fill="none"
                                    stroke={d.color ?? PALETTE[i % PALETTE.length]}
                                    strokeWidth={hover === i ? 28 : 22}
                                    strokeDasharray={`${len} ${c - len}`}
                                    initial={{ strokeDashoffset: c }}
                                    animate={{ strokeDashoffset: -offset }}
                                    transition={{ duration: 0.8, delay: i * 0.05 }}
                                    onMouseEnter={() => setHover(i)}
                                    onMouseLeave={() => setHover(null)}
                                    className="cursor-pointer transition-[stroke-width]"
                                />
                            );
                            offset += len;
                            return el;
                        })}
                </svg>
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                    <span className="text-2xl font-black text-slate-800">{num(shown ? shown.value : total)}</span>
                    <span className="max-w-24 truncate text-[11px] font-semibold text-slate-400">{shown ? shown.label : (center ?? 'Total')}</span>
                </div>
            </div>
            <ul className="w-full min-w-0 space-y-1.5">
                {data.map((d, i) => (
                    <li
                        key={d.label}
                        onMouseEnter={() => setHover(i)}
                        onMouseLeave={() => setHover(null)}
                        className={`flex items-center gap-2 rounded-lg px-2 py-1 text-xs transition ${hover === i ? 'bg-brand-50' : ''}`}
                    >
                        <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: d.color ?? PALETTE[i % PALETTE.length] }} />
                        <span className="min-w-0 flex-1 truncate font-semibold text-slate-600">{d.label}</span>
                        <span className="font-bold text-slate-800">{num(d.value)}</span>
                        <span className="w-10 text-right text-slate-400">{total ? Math.round((d.value / total) * 100) : 0}%</span>
                    </li>
                ))}
                {data.length === 0 && <li className="text-xs text-slate-400">Belum ada data.</li>}
            </ul>
        </div>
    );
}

/** Horizontal bars, one row per item. */
export function HBars({ data, format = num }: { data: Slice[]; format?: (n: number) => string }) {
    const max = Math.max(1, ...data.map((d) => d.value));
    return (
        <ul className="space-y-2.5">
            {data.map((d, i) => (
                <li key={d.label}>
                    <div className="mb-1 flex justify-between gap-2 text-xs">
                        <span className="truncate font-semibold text-slate-600">{d.label}</span>
                        <span className="font-bold text-slate-800">{format(d.value)}</span>
                    </div>
                    <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                        <motion.div
                            className="h-full rounded-full"
                            style={{ background: d.color ?? PALETTE[i % PALETTE.length] }}
                            initial={{ width: 0 }}
                            animate={{ width: `${(d.value / max) * 100}%` }}
                            transition={{ duration: 0.7, delay: i * 0.04 }}
                        />
                    </div>
                </li>
            ))}
            {data.length === 0 && <li className="text-xs text-slate-400">Belum ada data.</li>}
        </ul>
    );
}

/** Vertical bars with a hover tooltip (e.g. per month). */
export function VBars({ data, format = num, height = 160, highlight }: { data: Slice[]; format?: (n: number) => string; height?: number; highlight?: number }) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(1, ...data.map((d) => d.value));
    return (
        <div className="relative">
            <div className="flex items-end gap-1.5" style={{ height }}>
                {data.map((d, i) => (
                    <div
                        key={d.label}
                        className="relative flex h-full flex-1 flex-col justify-end"
                        onMouseEnter={() => setHover(i)}
                        onMouseLeave={() => setHover(null)}
                    >
                        {hover === i && (
                            <div className="absolute -top-1 left-1/2 z-10 -translate-x-1/2 -translate-y-full rounded-lg bg-slate-900 px-2 py-1 text-[11px] font-bold whitespace-nowrap text-white shadow-lg">
                                {d.label}: {format(d.value)}
                            </div>
                        )}
                        <motion.div
                            className={`w-full rounded-t-md ${hover === i ? 'bg-brand-700' : i === highlight ? 'bg-brand-600' : 'bg-brand-400/80'}`}
                            initial={{ height: 0 }}
                            animate={{ height: `${Math.max(d.value > 0 ? 3 : 0, (d.value / max) * 100)}%` }}
                            transition={{ duration: 0.6, delay: i * 0.03 }}
                        />
                    </div>
                ))}
            </div>
            <div className="mt-1.5 flex gap-1.5">
                {data.map((d) => (
                    <span key={d.label} className="flex-1 truncate text-center text-[10px] font-semibold text-slate-400">
                        {d.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

export interface OnsiteDay {
    date: string;
    total: number;
    onsite: number;
    dinas: number;
    cuti: number;
    off: number;
    absen: number;
    kosong: number;
    from_plan: number;
}

export const ONSITE_SERIES: { key: keyof OnsiteDay; label: string; color: string }[] = [
    { key: 'onsite', label: 'Onsite', color: '#15803d' },
    { key: 'dinas', label: 'Dinas', color: '#0d9488' },
    { key: 'cuti', label: 'Cuti', color: '#86efac' },
    { key: 'off', label: 'Off', color: '#d9f99d' },
    { key: 'absen', label: 'Sakit/Izin/Absen', color: '#f59e0b' },
    { key: 'kosong', label: 'Belum diisi', color: '#e2e8f0' },
];

/** Man Onsite per day as stacked bars; days after today come from the roster (striped). */
export function OnsiteChart({ days, today }: { days: OnsiteDay[]; today: string }) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(1, ...days.map((d) => d.total));
    const h = hover !== null ? days[hover] : (days.find((d) => d.date === today) ?? days[days.length - 1]);
    const dd = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1">
                {ONSITE_SERIES.map((s) => (
                    <span key={s.key} className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500">
                        <span className="h-2.5 w-2.5 rounded-sm" style={{ background: s.color }} /> {s.label}
                    </span>
                ))}
            </div>
            <div className="flex h-44 items-end gap-[3px]">
                {days.map((d, i) => {
                    const future = d.date > today;
                    return (
                        <div
                            key={d.date}
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                            className={`relative flex h-full flex-1 cursor-pointer flex-col justify-end rounded-t ${hover === i ? 'bg-brand-50' : ''}`}
                        >
                            <motion.div
                                initial={{ height: 0 }}
                                animate={{ height: `${(d.total / max) * 100}%` }}
                                transition={{ duration: 0.6, delay: i * 0.012 }}
                                className={`flex w-full flex-col-reverse overflow-hidden rounded-t ${future ? 'opacity-55' : ''} ${d.date === today ? 'ring-2 ring-brand-700 ring-offset-1' : ''}`}
                            >
                                {ONSITE_SERIES.map((s) => (
                                    <div key={s.key} style={{ height: `${d.total ? ((d[s.key] as number) / d.total) * 100 : 0}%`, background: s.color }} />
                                ))}
                            </motion.div>
                        </div>
                    );
                })}
            </div>
            <div className="mt-1 flex justify-between text-[10px] font-semibold text-slate-400">
                <span>{days[0] && dd(days[0].date)}</span>
                <span className="text-brand-700">Hari ini</span>
                <span>{days.length > 0 && dd(days[days.length - 1].date)}</span>
            </div>
            {h && (
                <div className="mt-3 grid grid-cols-3 gap-2 rounded-2xl bg-slate-50 p-3 sm:grid-cols-6">
                    <div className="col-span-3 text-xs font-bold text-slate-700 sm:col-span-6">
                        {new Date(`${h.date}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' })}
                        {h.date > today && <span className="ml-2 rounded bg-white px-1.5 py-0.5 text-[10px] text-slate-500">rencana roster</span>}
                        <span className="float-right text-slate-400">{num(h.total)} orang</span>
                    </div>
                    {ONSITE_SERIES.map((s) => (
                        <div key={s.key}>
                            <p className="truncate text-[10px] font-semibold text-slate-400">{s.label}</p>
                            <p className="text-sm font-black" style={{ color: s.key === 'kosong' || s.key === 'off' ? '#64748b' : s.color }}>
                                {num(h[s.key] as number)}
                            </p>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/** A ring gauge for a percentage. */
export function Gauge({ value, label }: { value: number | null; label: string }) {
    const r = 52;
    const c = 2 * Math.PI * r;
    const pct = Math.max(0, Math.min(100, value ?? 0));
    return (
        <div className="relative h-36 w-36">
            <svg viewBox="0 0 128 128" className="-rotate-90">
                <circle cx="64" cy="64" r={r} fill="none" stroke="#f1f5f9" strokeWidth="12" />
                <motion.circle
                    cx="64"
                    cy="64"
                    r={r}
                    fill="none"
                    stroke="url(#gaugeGreen)"
                    strokeWidth="12"
                    strokeLinecap="round"
                    strokeDasharray={c}
                    initial={{ strokeDashoffset: c }}
                    animate={{ strokeDashoffset: c - (pct / 100) * c }}
                    transition={{ duration: 1 }}
                />
                <defs>
                    <linearGradient id="gaugeGreen" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stopColor="#22c55e" />
                        <stop offset="100%" stopColor="#15803d" />
                    </linearGradient>
                </defs>
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-2xl font-black text-slate-800">{value === null ? '–' : `${value.toString().replace('.', ',')}%`}</span>
                <span className="text-[11px] font-semibold text-slate-400">{label}</span>
            </div>
        </div>
    );
}
