/** Shared types and rules of the Attendance module (Roster, Absensi, Cuti & Dinas). */

export type Jenis = 'work' | 'business_trip' | 'off' | 'leave' | 'sick' | 'permission' | 'absent';

export interface ShiftCode {
    id: number;
    code: string;
    /** What an export for payroll carries instead of the code. */
    daily_work_schedule: string | null;
    description: string | null;
    type: Jenis;
}

export interface PatternStep {
    shift_code_id: number;
    days: number;
}

export interface Pattern {
    id: number;
    name: string;
    steps: PatternStep[];
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
    employee_number: string;
    name: string;
    status: string;
    position: string | null;
    department: string | null;
    department_id: number | null;
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
    /** Absensi only: days filled by a Cuti & Dinas entry. */
    trip_days?: number[];
    /** Left during this month: from this date on the row is blocked, labelled with the reason. */
    termination?: { date: string; reason: string } | null;
}

export const WEEKDAY_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

export const JENIS_LABEL: Record<Jenis, string> = {
    work: 'Hadir',
    business_trip: 'Dinas',
    off: 'Off',
    leave: 'Cuti',
    sick: 'Sakit',
    permission: 'Izin',
    absent: 'Mangkir',
};

/** Cell colours per jenis (light fill, dark text of the same hue). */
export const JENIS_CELL: Record<Jenis, string> = {
    work: 'bg-emerald-100 text-emerald-800',
    business_trip: 'bg-sky-100 text-sky-800',
    off: 'bg-slate-200/70 text-slate-600',
    leave: 'bg-violet-100 text-violet-800',
    sick: 'bg-amber-100 text-amber-800',
    permission: 'bg-orange-100 text-orange-800',
    absent: 'bg-rose-100 text-rose-700',
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
    typeOf: Map<number, Jenis>,
    lastDay: number,
): { time_clock: number | null; matching: number | null } {
    let worked = 0;
    let due = 0;
    let same = 0;
    let compared = 0;
    for (const [day, code] of Object.entries(cells)) {
        if (code === null || Number(day) > lastDay) continue;
        const kind = typeOf.get(code);
        if (kind === 'work' || kind === 'business_trip') worked++;
        if (kind && kind !== 'off' && kind !== 'leave') due++;
        if (plan[day] !== undefined) {
            compared++;
            if (plan[day] === code) same++;
        }
    }
    const pct = (a: number, b: number) => (b ? Math.round((a / b) * 1000) / 10 : null);
    return { time_clock: pct(worked, due), matching: pct(same, compared) };
}

/** Whole days from one YYYY-MM-DD to another. */
export function daysBetween(fromIso: string, toIso: string): number {
    return Math.round((Date.parse(`${toIso}T00:00:00Z`) - Date.parse(`${fromIso}T00:00:00Z`)) / 86400000);
}

/** The code a pattern gives on `dateIso` when its first day falls on `startIso` (repeating both ways). */
export function patternCodeOn(pattern: Pattern, startIso: string, dateIso: string): number | null {
    const sequence = pattern.steps.flatMap((s) => Array<number>(s.days).fill(s.shift_code_id));
    if (!sequence.length) return null;
    const n = sequence.length;
    return sequence[((daysBetween(startIso, dateIso) % n) + n) % n];
}

/** "D×10 · OFF×2" */
export function describePattern(pattern: Pattern, codes: ShiftCode[]): string {
    return pattern.steps.map((s) => `${codes.find((c) => c.id === s.shift_code_id)?.code ?? '?'}×${s.days}`).join(' · ');
}

export function pct(value: number | null): string {
    return value === null ? '-' : `${value.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`;
}

/** The URL of a sheet (Roster = shift schedule, Absensi = attendance records). */
export const SHEET_PATH = { schedule: '/time/shift-schedule', attendance: '/time/attendance' } as const;
