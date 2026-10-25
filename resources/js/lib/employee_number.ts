/**
 * The Nomor Induk (NRP) as the company formatted it — mirrors App\Support\Employees\EmployeeNumber
 * for instant feedback in forms (the server has the final say).
 */

export interface JenisCode {
    code: string;
    name: string;
}

export interface LokasiCode {
    code: string;
    name: string;
    work_location_id: number | null;
}

export interface NrpCodes {
    type: JenisCode[];
    location: LokasiCode[];
}

export type SegmentType = 'text' | 'category' | 'point_of_hire' | 'year' | 'sequence';

export interface Segment {
    type: SegmentType;
    length?: number;
    value?: string;
}

export interface NrpFormat {
    mode: 'pattern' | 'free';
    segments: Segment[];
    max_length: number;
}

/** The J LL YY NNN pattern every workspace starts with. */
export const DEFAULT_FORMAT: NrpFormat = {
    mode: 'pattern',
    segments: [
        { type: 'category', length: 1 },
        { type: 'point_of_hire', length: 2 },
        { type: 'year', length: 2 },
        { type: 'sequence', length: 3 },
    ],
    max_length: 20,
};

export const SEGMENT_LABEL: Record<SegmentType, string> = {
    text: 'Teks tetap',
    category: 'Jenis karyawan',
    point_of_hire: 'Lokasi penerimaan',
    year: 'Tahun masuk',
    sequence: 'Nomor urut',
};

export interface DecodedNrp {
    error: string | null;
    type?: JenisCode;
    location?: LokasiCode;
    /** Four-digit year read from the year segment. */
    year?: number;
    sequence?: string;
}

export const segmentLength = (s: Segment) => (s.type === 'text' ? (s.value ?? '').length : (s.length ?? 0));

export function formatLength(format: NrpFormat): number | null {
    return format.mode === 'free' ? null : format.segments.reduce((n, s) => n + segmentLength(s), 0);
}

/** Character positions of each segment ("1", "2–3"), for the structure chips. */
export function segmentPositions(format: NrpFormat): { segment: Segment; from: number; to: number; where: string }[] {
    let at = 1;
    return format.segments.map((segment) => {
        const len = segmentLength(segment);
        const from = at;
        const to = at + len - 1;
        at += len;
        return { segment, from, to, where: len <= 1 ? String(from) : `${from}–${to}` };
    });
}

export function describeFormat(format: NrpFormat): string {
    if (format.mode === 'free') return `bebas, unik, maksimal ${format.max_length} karakter`;
    return format.segments.map((s) => (s.type === 'text' ? `"${s.value}"` : `${SEGMENT_LABEL[s.type].toLowerCase()} (${s.length})`)).join(' + ');
}

/** Reads a 2-digit year: up to next year's two digits means 20YY, otherwise 19YY. */
export function yearFromDigits(yy: string): number {
    if (yy.length === 4) return Number(yy);
    const n = Number(yy);
    const nextYear = (new Date().getFullYear() + 1) % 100;
    return n <= nextYear ? 2000 + n : 1900 + n;
}

export function decodeNrp(employeeNumber: string, codes: NrpCodes, format: NrpFormat = DEFAULT_FORMAT): DecodedNrp {
    const value = format.mode === 'free' ? employeeNumber.trim() : employeeNumber.trim().toUpperCase();
    if (format.mode === 'free') {
        if (value.length > format.max_length) return { error: `NRP maksimal ${format.max_length} karakter.` };
        if (!/^[A-Za-z0-9./_-]+$/.test(value)) return { error: 'NRP hanya boleh huruf, angka, dan . / _ - tanpa spasi.' };
        return { error: null };
    }

    const length = formatLength(format)!;
    if (value.length !== length) return { error: `NRP harus ${length} karakter: ${describeFormat(format)}.` };

    const decoded: DecodedNrp = { error: null };
    for (const { segment, from, to, where } of segmentPositions(format)) {
        const part = value.slice(from - 1, to);
        const at = `Karakter ke-${where}`;
        if (segment.type === 'text' && part !== (segment.value ?? '').toUpperCase()) {
            return { ...decoded, error: `${at} harus "${segment.value}".` };
        }
        if (segment.type === 'category') {
            decoded.type = codes.type.find((j) => j.code === part);
            if (!decoded.type) return { ...decoded, error: `${at} "${part}" bukan kode jenis karyawan yang terdaftar (${codes.type.map((j) => j.code).join(', ')}).` };
        }
        if (segment.type === 'point_of_hire') {
            decoded.location = codes.location.find((l) => l.code === part);
            if (!decoded.location) {
                return { ...decoded, error: `${at} "${part}" bukan kode lokasi penerimaan yang terdaftar (${codes.location.map((l) => l.code).join(', ')}).` };
            }
        }
        if (segment.type === 'year') {
            if (!/^\d+$/.test(part)) return { ...decoded, error: `${at} (tahun masuk) harus angka.` };
            decoded.year = yearFromDigits(part);
        }
        if (segment.type === 'sequence') {
            if (!/^\d+$/.test(part)) return { ...decoded, error: `${at} (nomor urut) harus angka.` };
            decoded.sequence = part;
        }
    }
    return decoded;
}

/** The next NRP for these codes and year (null when something it needs is missing, or the format is free). */
export function suggestNrp(
    format: NrpFormat,
    pick: { type?: string; location?: string; year?: string },
    maxSequence: Record<string, number>,
): string | null {
    if (format.mode === 'free') return null;
    let prefix = '';
    let sequenceLength = 0;
    for (const s of format.segments) {
        if (s.type === 'text') prefix += (s.value ?? '').toUpperCase();
        if (s.type === 'category') {
            if (!pick.type) return null;
            prefix += pick.type;
        }
        if (s.type === 'point_of_hire') {
            if (!pick.location) return null;
            prefix += pick.location;
        }
        if (s.type === 'year') {
            if (!/^\d{4}$/.test(pick.year ?? '')) return null;
            prefix += pick.year!.slice(-(s.length ?? 2));
        }
        if (s.type === 'sequence') sequenceLength = s.length ?? 3;
    }
    const next = (maxSequence[prefix] ?? 0) + 1;
    return String(next).length > sequenceLength ? null : `${prefix}${String(next).padStart(sequenceLength, '0')}`;
}

export const hasSegment = (format: NrpFormat, type: SegmentType) => format.mode === 'pattern' && format.segments.some((s) => s.type === type);

/** "Lokal" when hired where the employee works, "Kiriman" otherwise; null when unknown. */
export function originStatus(location: LokasiCode | undefined, workLocationId: number | null | undefined): 'Lokal' | 'Kiriman' | null {
    if (!location?.work_location_id || !workLocationId) return null;
    return location.work_location_id === workLocationId ? 'Lokal' : 'Kiriman';
}
