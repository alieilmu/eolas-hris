/** Man Power Planning: shapes of the page. */

export type Kategori = 'STAFF' | 'NONOM' | 'OPERATOR' | 'MEKANIK';

export interface MppRow {
    jabatan_id: number;
    jabatan: string;
    kategori: Kategori;
    sub_group: string | null;
    sub_lokasi: string | null;
    departemen: string;
    plan: number;
    actual: number;
    promosi: number;
    rotasi: number;
    demosi: number;
    keluar: number;
    pensiun: number;
    pensiun_auto: number;
    /** Typed in the plan instead of counted. */
    pensiun_manual: boolean;
    pensiun_list: { nrp: string; name: string; tanggal: string; bulan: number }[];
    sakit: number;
    sakit_auto: number;
    sakit_manual: boolean;
    sakit_list: { nrp: string; name: string; mulai: string; selesai: string | null; keterangan: string | null }[];
    gap: number;
    bulan_pindah: number | null;
    bulan_penuhi: number | null;
    sumber: 'internal' | 'eksternal' | null;
    golongan_id: number | null;
    golongan: string | null;
    golongan_manual: boolean;
    upah: number | null;
    biaya: number;
    keterangan: string | null;
    karyawan: { nrp: string; name: string }[];
    lowongan: { form_id: number; title: string; is_open: boolean; pelamar: number; kandidat: number } | null;
}

export interface Totals {
    plan: number;
    actual: number;
    promosi: number;
    rotasi: number;
    demosi: number;
    keluar: number;
    pensiun: number;
    sakit: number;
    gap: number;
    kurang: number;
    lebih: number;
    biaya: number;
}

export interface Revisi {
    id: number;
    status: 'diajukan' | 'disetujui' | 'ditolak' | 'dibatalkan';
    langsung: boolean;
    catatan: string | null;
    catatan_verifikasi: string | null;
    pembuat: string | null;
    created_by: number | null;
    penyetuju: string | null;
    waktu: string | null;
    diputuskan: string | null;
    perubahan: { jabatan_id: number; jabatan: string; fields: { field: string; before: string | number; after: string | number }[] }[];
}

/** The fields an edit may change (pensiun / sakit: null = counted from the employees). */
export type Editable = Partial<
    Pick<MppRow, 'plan' | 'promosi' | 'rotasi' | 'demosi' | 'keluar' | 'bulan_pindah' | 'bulan_penuhi' | 'sumber' | 'golongan_id' | 'keterangan'> & {
        pensiun: number | null;
        sakit: number | null;
    }
>;

export const FIELD_LABEL: Record<string, string> = {
    plan: 'Plan',
    promosi: 'Promosi',
    rotasi: 'Rotasi/Mutasi',
    demosi: 'Demosi',
    keluar: 'Keluar',
    pensiun: 'Pensiun',
    sakit: 'Sakit berkepanjangan',
    bulan_pindah: 'Bulan pindah',
    bulan_penuhi: 'Bulan pemenuhan',
    sumber: 'Pemenuhan dari',
    golongan_id: 'Golongan',
    keterangan: 'Keterangan',
};

export const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

export const rupiah = (n: number) => n.toLocaleString('id-ID');

/** Rp 12,5 jt / Rp 850 rb, for tiles. */
export function rupiahShort(n: number): string {
    if (n >= 1_000_000_000) return `Rp ${(n / 1_000_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} M`;
    if (n >= 1_000_000) return `Rp ${(n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 1 })} jt`;
    if (n >= 1_000) return `Rp ${Math.round(n / 1_000)} rb`;
    return `Rp ${n}`;
}
