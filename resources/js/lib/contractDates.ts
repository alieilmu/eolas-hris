/** Pure date helpers for the PKWT forms (all values are YYYY-MM-DD). */

const parts = (iso: string): [number, number, number] => {
    const [y, m, d] = iso.split('-').map(Number);
    return [y, m - 1, d];
};

const toIso = (ms: number) => new Date(ms).toISOString().slice(0, 10);

export function isValidIsoDate(iso: string): boolean {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return false;
    const [y, m, d] = parts(iso);
    const t = new Date(Date.UTC(y, m, d));
    return t.getUTCFullYear() === y && t.getUTCMonth() === m && t.getUTCDate() === d;
}

/** start + `months` calendar months, clamped to the end of a shorter month. */
export function addMonthsIso(startIso: string, months: number): string {
    const [y, m, d] = parts(startIso);
    const targetMonth = m + months;
    const lastDay = new Date(Date.UTC(y, targetMonth + 1, 0)).getUTCDate();
    return toIso(Date.UTC(y, targetMonth, Math.min(d, lastDay)));
}

export function addDaysIso(iso: string, days: number): string {
    const [y, m, d] = parts(iso);
    return toIso(Date.UTC(y, m, d) + days * 86400000);
}

/**
 * End date of a contract that starts on `startIso` and runs `months` months:
 * one day before the same date `months` later (1 Jan + 12 months -> 31 Dec).
 */
export function endDateForMonths(startIso: string, months: number): string {
    return addDaysIso(addMonthsIso(startIso, months), -1);
}

/** Today's local calendar date as YYYY-MM-DD. */
export function todayIso(): string {
    const d = new Date();
    return toIso(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
}
