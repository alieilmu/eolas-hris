/** NRP = J LL YY NNN — mirrors App\Support\Karyawan\Nrp for instant feedback in forms (the server has the final say). */

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

export interface DecodedNrp {
    error: string | null;
    type?: JenisCode;
    location?: LokasiCode;
    /** Four-digit year read from digits 4–5. */
    year?: number;
    sequence?: string;
}

/** Reads digits 4–5 as a year: up to next year's two digits means 20YY, otherwise 19YY. */
export function yearFromDigits(yy: string): number {
    const n = Number(yy);
    const nextYear = (new Date().getFullYear() + 1) % 100;
    return n <= nextYear ? 2000 + n : 1900 + n;
}

export function decodeNrp(employee_number: string, codes: NrpCodes): DecodedNrp {
    const value = employee_number.trim();
    if (!/^\d{8}$/.test(value)) {
        return { error: 'NRP harus 8 digit angka: jenis (1) + lokasi penerimaan (2) + tahun masuk (2) + nomor urut (3).' };
    }

    const type = codes.type.find((j) => j.code === value[0]);
    const location = codes.location.find((l) => l.code === value.slice(1, 3));
    const decoded = { type, location, year: yearFromDigits(value.slice(3, 5)), sequence: value.slice(5) };

    if (!type) {
        return {
            ...decoded,
            error: `Digit ke-1 "${value[0]}" bukan kode jenis karyawan yang terdaftar (${codes.type.map((j) => j.code).join(', ')}).`,
        };
    }
    if (!location) {
        return {
            ...decoded,
            error: `Digit ke-2–3 "${value.slice(1, 3)}" bukan kode lokasi penerimaan yang terdaftar (${codes.location.map((l) => l.code).join(', ')}).`,
        };
    }
    return { ...decoded, error: null };
}

/** "Lokal" when hired where the employee works, "Kiriman" otherwise; null when unknown. */
export function originStatus(location: LokasiCode | undefined, workLocationId: number | null | undefined): 'Lokal' | 'Kiriman' | null {
    if (!location?.work_location_id || !workLocationId) return null;
    return location.work_location_id === workLocationId ? 'Lokal' : 'Kiriman';
}
