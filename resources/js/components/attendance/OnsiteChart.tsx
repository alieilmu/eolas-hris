import { useEffect, useRef, useState } from 'react';
import { WEEKDAY_SHORT } from '@/lib/attendance';
import { fmtDate } from '@/lib/format';

/** One day of Man Onsite (from Onsite.php). */
export interface OnsiteDay {
    date: string;
    total: number;
    onsite: number;
    dinas: number;
    cuti: number;
    off: number;
    absen: number;
    kosong: number;
    /** People whose status that day comes from the roster (no Absensi yet). */
    from_plan: number;
}

export const onsitePct = (d: OnsiteDay) => (d.total ? (d.onsite / d.total) * 100 : 0);

const BREAKDOWN: { key: keyof OnsiteDay; label: string }[] = [
    { key: 'onsite', label: 'Onsite (hadir)' },
    { key: 'dinas', label: 'Dinas' },
    { key: 'cuti', label: 'Cuti' },
    { key: 'off', label: 'Off' },
    { key: 'absen', label: 'Sakit / Izin / Mangkir' },
    { key: 'kosong', label: 'Belum ada data' },
];

const HEIGHT = 260;
const PAD = { top: 16, right: 12, bottom: 34, left: 40 };

/**
 * % Man Onsite per day as bars: solid where Absensi (and Trip & Leave) say it,
 * light where the day is still ahead and the Roster stands in.
 */
export function OnsiteChart({ days, today }: { days: OnsiteDay[]; today: string }) {
    const box = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(800);
    const [hover, setHover] = useState<number | null>(null);

    useEffect(() => {
        if (!box.current) return;
        const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
        observer.observe(box.current);
        return () => observer.disconnect();
    }, []);

    const plotW = Math.max(width - PAD.left - PAD.right, 100);
    const plotH = HEIGHT - PAD.top - PAD.bottom;
    const slot = plotW / Math.max(days.length, 1);
    const barW = Math.max(Math.min(slot - 2, 22), 3);
    const y = (pct: number) => PAD.top + plotH - (pct / 100) * plotH;
    const labelEvery = slot < 16 ? 3 : slot < 24 ? 2 : 1;
    const shown = hover !== null ? days[hover] : null;
    const isPlan = (d: OnsiteDay) => d.date > today;

    return (
        <div ref={box} className="relative" onMouseLeave={() => setHover(null)}>
            <svg width={width} height={HEIGHT} role="img" aria-label="Persentase Man Onsite per hari">
                {[0, 25, 50, 75, 100].map((t) => (
                    <g key={t}>
                        <line
                            x1={PAD.left}
                            x2={PAD.left + plotW}
                            y1={y(t)}
                            y2={y(t)}
                            className={t === 0 ? 'stroke-slate-300' : 'stroke-slate-100'}
                            strokeWidth={1}
                        />
                        <text x={PAD.left - 8} y={y(t) + 3} textAnchor="end" className="fill-slate-400 text-[10px] tabular-nums">
                            {t}%
                        </text>
                    </g>
                ))}
                {days.map((d, i) => {
                    const p = onsitePct(d);
                    const x = PAD.left + i * slot + (slot - barW) / 2;
                    const top = y(p);
                    const h = PAD.top + plotH - top;
                    const r = Math.min(4, barW / 2, h);
                    const day = Number(d.date.slice(8));
                    const weekday = new Date(`${d.date}T00:00:00Z`).getUTCDay();
                    return (
                        <g key={d.date}>
                            {/* Hit area: the whole column, wider than the bar. */}
                            <rect
                                x={PAD.left + i * slot}
                                y={PAD.top}
                                width={slot}
                                height={plotH}
                                fill="transparent"
                                onMouseEnter={() => setHover(i)}
                            />
                            {h > 0 && (
                                <path
                                    d={`M${x},${top + h} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW - r} Q${x + barW},${top} ${x + barW},${top + r} V${top + h} Z`}
                                    className={`${isPlan(d) ? 'fill-brand-300' : 'fill-brand-600'} ${hover === i ? 'opacity-80' : ''}`}
                                    pointerEvents="none"
                                />
                            )}
                            {d.date === today && (
                                <line
                                    x1={x + barW / 2}
                                    x2={x + barW / 2}
                                    y1={PAD.top}
                                    y2={PAD.top + plotH}
                                    className="stroke-accent-500"
                                    strokeWidth={2}
                                    strokeDasharray="3 3"
                                    pointerEvents="none"
                                />
                            )}
                            {(i % labelEvery === 0 || d.date === today) && (
                                <text
                                    x={PAD.left + i * slot + slot / 2}
                                    y={HEIGHT - PAD.bottom + 14}
                                    textAnchor="middle"
                                    className={`text-[10px] tabular-nums ${d.date === today ? 'fill-slate-800 font-bold' : weekday === 0 ? 'fill-rose-400' : 'fill-slate-400'}`}
                                >
                                    {day}
                                </text>
                            )}
                        </g>
                    );
                })}
                <text x={PAD.left} y={HEIGHT - 4} className="fill-slate-400 text-[10px]">
                    Tanggal
                </text>
            </svg>

            {shown && hover !== null && (
                <div
                    className="pointer-events-none absolute z-10 w-56 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-lg"
                    style={{
                        left: Math.min(Math.max(PAD.left + hover * slot + slot / 2 - 112, 0), width - 224),
                        top: 4,
                    }}
                >
                    <p className="font-bold text-slate-800">
                        {WEEKDAY_SHORT[new Date(`${shown.date}T00:00:00Z`).getUTCDay()]}, {fmtDate(shown.date)}
                        {isPlan(shown) && <span className="ml-1.5 rounded bg-brand-50 px-1 text-[10px] font-bold text-brand-600">rencana</span>}
                    </p>
                    <p className="mt-0.5 text-lg font-black text-slate-800 tabular-nums">
                        {onsitePct(shown).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%
                        <span className="ml-1 text-xs font-medium text-slate-400">onsite dari {shown.total} orang</span>
                    </p>
                    <div className="mt-2 space-y-0.5">
                        {BREAKDOWN.map((b) => (
                            <div key={b.key} className="flex justify-between text-slate-600">
                                <span>{b.label}</span>
                                <span className="font-bold tabular-nums">{shown[b.key] as number}</span>
                            </div>
                        ))}
                    </div>
                    {shown.from_plan > 0 && (
                        <p className="mt-2 text-[10px] text-slate-400">{shown.from_plan} orang dihitung dari Roster (Absensi belum diisi).</p>
                    )}
                </div>
            )}
        </div>
    );
}
