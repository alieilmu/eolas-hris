/** NRP = J LL YY NNN — mirrors App\Support\Karyawan\Nrp for instant feedback in forms (the server has the final say). */

export interface JenisCode {
    kode: string;
    nama: string;
}

export interface LokasiCode {
    kode: string;
    nama: string;
    work_location_id: number | null;
}

export interface NrpCodes {
    jenis: JenisCode[];
    lokasi: LokasiCode[];
}

export interface DecodedNrp {
    error: string | null;
    jenis?: JenisCode;
    lokasi?: LokasiCode;
    /** Four-digit year read from digits 4–5. */
    tahun?: number;
    urut?: string;
}

/** Reads digits 4–5 as a year: up to next year's two digits means 20YY, otherwise 19YY. */
export function yearFromDigits(yy: string): number {
    const n = Number(yy);
    const nextYear = (new Date().getFullYear() + 1) % 100;
    return n <= nextYear ? 2000 + n : 1900 + n;
}

export function decodeNrp(nrp: string, codes: NrpCodes): DecodedNrp {
    const value = nrp.trim();
    if (!/^\d{8}$/.test(value)) {
        return { error: 'NRP harus 8 digit angka: jenis (1) + lokasi penerimaan (2) + tahun masuk (2) + nomor urut (3).' };
    }

    const jenis = codes.jenis.find((j) => j.kode === value[0]);
    const lokasi = codes.lokasi.find((l) => l.kode === value.slice(1, 3));
    const decoded = { jenis, lokasi, tahun: yearFromDigits(value.slice(3, 5)), urut: value.slice(5) };

    if (!jenis) {
        return {
            ...decoded,
            error: `Digit ke-1 "${value[0]}" bukan kode jenis karyawan yang terdaftar (${codes.jenis.map((j) => j.kode).join(', ')}).`,
        };
    }
    if (!lokasi) {
        return {
            ...decoded,
            error: `Digit ke-2–3 "${value.slice(1, 3)}" bukan kode lokasi penerimaan yang terdaftar (${codes.lokasi.map((l) => l.kode).join(', ')}).`,
        };
    }
    return { ...decoded, error: null };
}

/** "Lokal" when hired where the employee works, "Kiriman" otherwise; null when unknown. */
export function statusAsal(lokasi: LokasiCode | undefined, workLocationId: number | null | undefined): 'Lokal' | 'Kiriman' | null {
    if (!lokasi?.work_location_id || !workLocationId) return null;
    return lokasi.work_location_id === workLocationId ? 'Lokal' : 'Kiriman';
}
