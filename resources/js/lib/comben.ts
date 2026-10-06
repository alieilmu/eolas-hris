import type { LampiranFile } from '@/lib/lampiran';

/** Shared types and helpers of the Comben module (Deklarasi, Monitoring, Master Data). */

export type JenisItem = 'perawatan' | 'pengobatan' | 'bingkai' | 'lensa';
export type Penerima = 'karyawan' | 'pasangan' | 'anak';

export const JENIS_ROWS: { value: JenisItem; label: string; plafon: 'pengobatan' | 'kacamata' }[] = [
    { value: 'perawatan', label: 'Perawatan', plafon: 'pengobatan' },
    { value: 'pengobatan', label: 'Pengobatan', plafon: 'pengobatan' },
    { value: 'bingkai', label: 'Kacamata – Bingkai', plafon: 'kacamata' },
    { value: 'lensa', label: 'Kacamata – Lensa', plafon: 'kacamata' },
];

export interface Member {
    key: string;
    penerima: Penerima;
    anak_id: number | null;
    nama: string;
    hubungan: string;
}

export interface Allowance {
    plafon: number | null;
    terpakai: number;
    /** From the portal, waiting for HR: already held from what is left. */
    menunggu?: number;
    sisa: number | null;
    terakhir?: string | null;
}

/** GET /comben/karyawan/{id}/plafon */
export interface PlafonInfo {
    karyawan: {
        id: number;
        nrp: string;
        name: string;
        jabatan: string | null;
        sub_group: string | null;
        departemen: string | null;
        work_location: string | null;
        golongan: string | null;
        status_ptkp: string;
    };
    bulan_buku: { value: string; label: string; span: string };
    tahun: number;
    status_ptkp: string;
    keluarga: boolean;
    warning: string | null;
    pengobatan: Allowance & { layer: string | null };
    kacamata: (Member & { bingkai: Allowance; lensa: Allowance })[];
}

export interface DeklarasiItemData {
    jenis: JenisItem;
    penerima: Penerima;
    karyawan_anak_id: number | null;
    anak?: string | null;
    nominal: number;
}

export interface DeklarasiRow {
    id: number;
    nomor: string;
    tanggal: string;
    tempat: string | null;
    bulan_buku: string;
    bulan_label: string;
    jumlah_kwitansi: number;
    keterangan: string | null;
    karyawan_id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    pengobatan: number;
    kacamata: number;
    total: number;
    status: 'diajukan' | 'disetujui' | 'ditolak';
    sumber: 'hr' | 'portal';
    catatan_verifikasi: string | null;
    lampiran: LampiranFile[];
    items: DeklarasiItemData[];
}

const number = new Intl.NumberFormat('id-ID');

/** 2250000 -> "2.250.000"; '-' for empty. */
export function rupiah(value: number | null | undefined): string {
    return value === null || value === undefined ? '-' : number.format(value);
}

/** The month part of a book month: "2026-10" -> "Okt". */
export function monthShort(bulan: string): string {
    return ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][Number(bulan.slice(5, 7)) - 1];
}
