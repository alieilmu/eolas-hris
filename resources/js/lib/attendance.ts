/** Shared types and rules of the Attendance module (Roster, Absensi, Trip & Leave). */

export type Jenis = 'hadir' | 'dinas' | 'off' | 'cuti' | 'sakit' | 'izin' | 'mangkir';

export interface ShiftCode {
    id: number;
    kode: string;
    /** What an export for payroll carries instead of the code. */
    dws: string | null;
    keterangan: string | null;
    jenis: Jenis;
}

export interface PatternStep {
    shift_code_id: number;
    hari: number;
}

export interface Pattern {
    id: number;
    nama: string;
    langkah: PatternStep[];
}

export interface PeriodDay {
    day: number;
    date: string;
    /** 0 = Minggu ... 6 = Sabtu */
    weekday: number;
    holiday: string | null;
}

export interface PeriodInfo {
    /** "2026-10" */
    value: string;
    label: string;
    prev: string;
    next: string;
    today: string;
    days: PeriodDay[];
}

export interface PeriodOption {
    value: string;
    label: string;
}

export interface SheetRow {
    id: number;
    nrp: string;
    name: string;
    status: string;
    jabatan: string | null;
    departemen: string | null;
    departemen_id: number | null;
    work_location: string | null;
    work_location_id: number | null;
    /** The Sub Lokasi Kerja they are placed in, if any. */
    sub_location: string | null;
    sub_location_id: number | null;
    /** First and last day of the month the employee was employed. */
    from: number;
    until: number;
    /** day => shift_code_id */
    cells: Record<string, number>;
    /** Absensi only: the roster of the same days. */
    plan?: Record<string, number>;
    /** Absensi only: days filled by a Trip & Leave entry. */
    trip_days?: number[];
    /** Left during this month: from this date on the row is blocked, labelled with the reason. */
    keluar?: { tanggal: string; alasan: string } | null;
}

export const WEEKDAY_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

export const JENIS_LABEL: Record<Jenis, string> = {
    hadir: 'Hadir',
    dinas: 'Dinas',
    off: 'Off',
    cuti: 'Cuti',
    sakit: 'Sakit',
    izin: 'Izin',
    mangkir: 'Mangkir',
};

/** Cell colours per jenis (light fill, dark text of the same hue). */
export const JENIS_CELL: Record<Jenis, string> = {
    hadir: 'bg-emerald-100 text-emerald-800',
    dinas: 'bg-sky-100 text-sky-800',
    off: 'bg-slate-200/70 text-slate-600',
    cuti: 'bg-violet-100 text-violet-800',
    sakit: 'bg-amber-100 text-amber-800',
    izin: 'bg-orange-100 text-orange-800',
    mangkir: 'bg-rose-100 text-rose-700',
};

/** The last day of the period that has happened: all of a past month, none of a future one. */
export function lastDayOf(period: PeriodInfo): number {
    const days = period.days;
    if (period.today > days[days.length - 1].date) return days.length;
    if (period.today < days[0].date) return 0;
    return Number(period.today.slice(8, 10));
}

/**
 * % Kehadiran: days worked (hadir + dinas) out of the days due to work (off and cuti not counted against).
 * % Sesuai Roster: days whose Absensi equals the Roster, out of the days both are filled. Same as Stats.php.
 */
export function attendanceStats(
    cells: Record<string, number | null>,
    plan: Record<string, number>,
    jenisOf: Map<number, Jenis>,
    lastDay: number,
): { kehadiran: number | null; sesuai: number | null } {
    let worked = 0;
    let due = 0;
    let same = 0;
    let compared = 0;
    for (const [day, code] of Object.entries(cells)) {
        if (code === null || Number(day) > lastDay) continue;
        const kind = jenisOf.get(code);
        if (kind === 'hadir' || kind === 'dinas') worked++;
        if (kind && kind !== 'off' && kind !== 'cuti') due++;
        if (plan[day] !== undefined) {
            compared++;
            if (plan[day] === code) same++;
        }
    }
    const pct = (a: number, b: number) => (b ? Math.round((a / b) * 1000) / 10 : null);
    return { kehadiran: pct(worked, due), sesuai: pct(same, compared) };
}

/** Whole days from one YYYY-MM-DD to another. */
export function daysBetween(fromIso: string, toIso: string): number {
    return Math.round((Date.parse(`${toIso}T00:00:00Z`) - Date.parse(`${fromIso}T00:00:00Z`)) / 86400000);
}

/** The code a pattern gives on `dateIso` when its first day falls on `startIso` (repeating both ways). */
export function patternCodeOn(pattern: Pattern, startIso: string, dateIso: string): number | null {
    const sequence = pattern.langkah.flatMap((s) => Array<number>(s.hari).fill(s.shift_code_id));
    if (!sequence.length) return null;
    const n = sequence.length;
    return sequence[((daysBetween(startIso, dateIso) % n) + n) % n];
}

/** "D×10 · OFF×2" */
export function describePattern(pattern: Pattern, codes: ShiftCode[]): string {
    return pattern.langkah.map((s) => `${codes.find((c) => c.id === s.shift_code_id)?.kode ?? '?'}×${s.hari}`).join(' · ');
}

export function pct(value: number | null): string {
    return value === null ? '-' : `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;
}
