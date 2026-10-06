import { fmtDate } from '@/lib/format';

export type KategoriCuti = 'lapangan' | 'tahunan' | 'besar' | 'lainnya';

export const KATEGORI_LABEL: Record<KategoriCuti, string> = {
    lapangan: 'Cuti Lapangan',
    tahunan: 'Cuti Tahunan',
    besar: 'Cuti Besar',
    lainnya: 'Cuti Lainnya',
};

export interface Tahunan {
    ada: boolean;
    alasan?: string;
    masuk?: string;
    saldo?: number;
    hutang?: number;
    menunggu: number;
    tersedia?: number;
    jatah_tahun_ini?: number;
    diambil_tahun_ini?: number;
    diambil_total: number;
    hangus_akhir_tahun?: number;
    hak_berikutnya?: string;
    tahun?: { tahun: number; jatah: number; diambil: number; sisa: number; hutang: number }[];
}

export type BesarStatus = 'belum_dipilih' | 'menunggu' | 'diambil' | 'diuangkan' | 'otomatis_diuangkan';

export interface BesarPeriode {
    /** The choice record, when there is one. */
    id: number | null;
    periode_ke: number;
    masa_kerja: number;
    tanggal_hak: string;
    batas: string;
    status: BesarStatus;
    keputusan: 'diambil' | 'diuangkan' | null;
    keputusan_status: 'diajukan' | 'disetujui' | null;
    hari: number;
    terpakai: number;
    menunggu: number;
    sisa: number;
    gapok: number | null;
}

export interface Besar {
    periode: BesarPeriode[];
    berikutnya: string | null;
    berikutnya_masa_kerja?: number;
}

export interface CutiItem {
    id: number | null;
    trip_id?: number;
    sumber: 'import' | 'manual' | 'trip';
    jenis: KategoriCuti;
    tahun: number;
    tanggal_awal: string | null;
    tanggal_akhir: string | null;
    keterangan: string | null;
    hari: number;
    potong: number;
    potong_besar?: number;
    status: 'diajukan' | 'disetujui' | 'ditolak';
    catatan: string | null;
}

/** What the cuti form needs: balance, the location's field-leave allowance, the Cuti Besar to take. */
export interface CutiInfo {
    tahunan: Tahunan;
    lapangan: { cuti: number; berangkat: number; kembali: number; total: number } | null;
    besar: BesarPeriode[];
    kategori: Record<KategoriCuti, string>;
}

export const BESAR_STATUS: Record<BesarStatus, { label: string; className: string }> = {
    belum_dipilih: { label: 'Belum dipilih', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    menunggu: { label: 'Menunggu HR', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    diambil: { label: 'Diambil', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    diuangkan: { label: 'Diuangkan', className: 'bg-violet-50 text-violet-700 ring-violet-200' },
    otomatis_diuangkan: { label: 'Lewat batas → diuangkan', className: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

/** "07.06.2023 – 09.06.2023", the old free text, or the year. */
export function itemWhen(i: Pick<CutiItem, 'tanggal_awal' | 'tanggal_akhir' | 'keterangan' | 'tahun'>): string {
    if (!i.tanggal_awal) return i.keterangan ?? String(i.tahun);
    const a = fmtDate(i.tanggal_awal);
    const b = i.tanggal_akhir ? fmtDate(i.tanggal_akhir) : a;
    return a === b ? a : `${a} – ${b}`;
}

/** "1× gapok", "2× gapok". */
export const gapok = (n: number | null) => (n === null ? '—' : `${String(n).replace('.', ',').replace(/,0$/, '')}× gapok`);
