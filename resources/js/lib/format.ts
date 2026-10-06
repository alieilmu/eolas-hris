const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** "2026-12-31" -> "31.12.2026", the app's one date format. */
export function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '-';
    const m = ISO_DATE.exec(iso.slice(0, 10));
    return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

/** For a date field: "2026-12-31" -> "31.12.2026", '' when empty. */
export function isoToDots(iso: string | null | undefined): string {
    return iso ? fmtDate(iso) : '';
}

/** "31.12.2026" (or "1.2.2026") -> "2026-12-31"; null when it is not a real date. */
export function dotsToIso(text: string): string | null {
    const m = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(text.trim());
    if (!m) return null;
    const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])];
    const t = new Date(Date.UTC(y, mo - 1, d));
    if (t.getUTCFullYear() !== y || t.getUTCMonth() !== mo - 1 || t.getUTCDate() !== d || y < 1900 || y > 2200) return null;
    return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

const numberFormat = new Intl.NumberFormat('id-ID');

/** Table cell text: "-" for empty, "Ya"/"Tidak" for booleans, dd.mm.yyyy for dates, grouped digits for plain numbers. */
export function displayValue(value: string | number | boolean | null | undefined): string {
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
    if (typeof value === 'number' && Math.abs(value) >= 10000) return numberFormat.format(value);
    if (typeof value === 'string' && ISO_DATE.test(value)) return fmtDate(value);
    return String(value);
}
