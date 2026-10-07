import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Download, Pencil, Search, Trash2 } from 'lucide-react';
import { TripForm, type TripData, type TripPerson } from '@/components/attendance/TripForm';
import { AttendanceHeader, JenisBadge, Modal, secondaryButton, smallInput } from '@/components/attendance/ui';
import { fmtDate } from '@/lib/format';
import { StatusBadge, VerifyButtons } from '@/components/Verification';
import { TiketBadges, type Bandara } from '@/components/attendance/BandaraPicker';
import { LampiranButton } from '@/components/lampiran/LampiranPicker';
import type { LampiranLimits } from '@/lib/lampiran';

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    jenis: 'dinas' | 'cuti' | 'semua';
    perjalanan: '' | 'akan' | 'berjalan' | 'selesai';
    uang: string;
    status: '' | 'diajukan' | 'disetujui' | 'ditolak';
    q: string;
}

interface Props {
    trips: Paginated<TripData>;
    filters: Filters;
    status_uang: string[];
    people: TripPerson[];
    cities: string[];
    /** From the employee portal, waiting for HR, per jenis. */
    menunggu: Partial<Record<'dinas' | 'cuti', number>>;
    can_edit: boolean;
    bandara: Bandara[];
    lampiran_limits: LampiranLimits;
}

const PERJALANAN = {
    akan: { label: 'Akan berangkat', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    berjalan: { label: 'Dalam perjalanan', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    selesai: { label: 'Selesai', className: 'bg-slate-100 text-slate-600 ring-slate-200' },
} as const;

/** Colour of a money status, read from its wording (the list itself is edited in Master Data). */
export function uangTone(status: string | null): string {
    const s = (status ?? '').toLowerCase();
    if (s.includes('tolak') || s.includes('batal')) return 'bg-rose-50 text-rose-700 border-rose-200';
    if (s.includes('selesai') || s.includes('lunas')) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if (s.includes('cair')) return 'bg-teal-50 text-teal-700 border-teal-200';
    if (s.includes('setuju')) return 'bg-sky-50 text-sky-700 border-sky-200';
    if (s.includes('belum')) return 'bg-slate-50 text-slate-600 border-slate-200';
    if (s.includes('aju')) return 'bg-amber-50 text-amber-700 border-amber-200';
    return 'bg-brand-50 text-brand-700 border-brand-200';
}

function perjalananNote(t: TripData): string {
    if (t.perjalanan === 'akan') return t.hari_ke === 1 ? 'Berangkat besok' : `H-${t.hari_ke}`;
    if (t.perjalanan === 'berjalan') return `Hari ke-${t.hari_ke} dari ${t.lama}`;
    return t.hari_ke <= 1 ? 'Kembali kemarin' : `Kembali ${t.hari_ke} hari lalu`;
}

export default function Pengajuan({ trips, filters, status_uang, people, cities, menunggu, can_edit, bandara, lampiran_limits }: Props) {
    const waiting = (menunggu.dinas ?? 0) + (menunggu.cuti ?? 0);
    const [search, setSearch] = useState(filters.q);
    const [editing, setEditing] = useState<TripData | null>(null);
    const first = useRef(true);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (merged.jenis !== 'dinas') query.jenis = merged.jenis;
        if (merged.perjalanan) query.perjalanan = merged.perjalanan;
        if (merged.uang) query.uang = merged.uang;
        if (merged.status) query.status = merged.status;
        if (merged.q.trim()) query.q = merged.q.trim();
        router.get('/attendance/pengajuan-dinas', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Search as you type, after a short pause.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ q: search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const setUang = (t: TripData, status: string) =>
        router.put(`/attendance/trip-leave/${t.id}/status-uang`, { status_uang: status }, { preserveScroll: true, preserveState: true });

    const remove = (t: TripData) => {
        if (
            confirm(
                `Hapus ${t.jenis === 'dinas' ? 'dinas' : 'cuti'} ${t.name} ke ${t.kota_tujuan} (${fmtDate(t.tanggal_awal)} – ${fmtDate(t.tanggal_akhir)})? Hari-harinya di Absensi ikut dikosongkan.`,
            )
        ) {
            router.delete(`/attendance/trip-leave/${t.id}`, { preserveScroll: true, preserveState: true });
        }
    };

    const jenisTabs = [
        { value: 'dinas', label: 'Dinas' },
        { value: 'cuti', label: 'Cuti' },
        { value: 'semua', label: 'Semua' },
    ] as const;
    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const exportUrl = () => {
        const q = new URLSearchParams();
        for (const [k, v] of Object.entries({ ...filters, q: filters.q.trim() })) if (v) q.set(k, v);
        return `/attendance/trip-leave/export?${q}`;
    };

    return (
        <>
            <Head title="Pengajuan Dinas" />
            <AttendanceHeader title="Pengajuan Dinas" subtitle="Status perjalanan dihitung dari tanggal hari ini dan tanggal berangkat">
                <Link
                    href="/attendance/trip-leave"
                    className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-brand-600 hover:bg-brand-50"
                >
                    <ArrowLeft size={15} />
                    Trip &amp; Leave
                </Link>
                <a href={exportUrl()} className={secondaryButton} title="Unduh daftar sesuai filter">
                    <Download size={16} />
                    Export Excel
                </a>
            </AttendanceHeader>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-6">
                <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <div className="flex rounded-xl bg-slate-100 p-1">
                            {jenisTabs.map((t) => (
                                <button
                                    key={t.value}
                                    onClick={() => apply({ jenis: t.value })}
                                    className={`rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                        filters.jenis === t.value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>
                        <button
                            onClick={() => apply(filters.status === 'diajukan' ? { status: '' } : { status: 'diajukan', jenis: 'semua' })}
                            className={`rounded-xl px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${
                                filters.status === 'diajukan'
                                    ? 'bg-amber-500 text-white ring-amber-500'
                                    : waiting
                                      ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                      : 'text-slate-400 ring-slate-200'
                            }`}
                        >
                            Perlu persetujuan ({waiting})
                        </button>
                        <select
                            value={filters.perjalanan}
                            onChange={(e) => apply({ perjalanan: e.target.value as Filters['perjalanan'] })}
                            className={smallInput}
                            aria-label="Status perjalanan"
                        >
                            <option value="">Semua status perjalanan</option>
                            {Object.entries(PERJALANAN).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v.label}
                                </option>
                            ))}
                        </select>
                        <select
                            value={filters.uang}
                            onChange={(e) => apply({ uang: e.target.value })}
                            className={smallInput}
                            aria-label="Status pengajuan uang"
                        >
                            <option value="">Semua status uang</option>
                            {status_uang.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama / NRP / kota"
                                className={`${smallInput} w-60 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className={`${th} w-10 pl-5`}>No</th>
                                    <th className={th}>Karyawan</th>
                                    {filters.jenis === 'semua' && <th className={th}>Jenis</th>}
                                    <th className={th}>Berangkat</th>
                                    <th className={th}>Kembali</th>
                                    <th className={th}>Tujuan</th>
                                    <th className={`${th} text-center`}>Lama</th>
                                    <th className={th}>Persetujuan</th>
                                    <th className={th}>Status Perjalanan</th>
                                    <th className={th}>Status Pengajuan Uang</th>
                                    <th className={`${th} pr-5 text-right`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {trips.data.length === 0 && (
                                    <tr>
                                        <td colSpan={11} className="px-5 py-12 text-center text-sm text-slate-400">
                                            Belum ada data. Input perjalanan dinas / cuti di halaman Trip &amp; Leave.
                                        </td>
                                    </tr>
                                )}
                                {trips.data.map((t, i) => {
                                    const status = PERJALANAN[t.perjalanan];
                                    return (
                                        <tr key={t.id} className="hover:bg-slate-50/60">
                                            <td className="py-3 pl-5 text-xs text-slate-400 tabular-nums">{(trips.from ?? 1) + i}</td>
                                            <td className="min-w-56 px-3 py-3">
                                                <p className="font-bold text-slate-700">{t.name}</p>
                                                <p className="text-[11px] text-slate-400">
                                                    {t.nrp} · {t.jabatan ?? 'Belum ditempatkan'}
                                                </p>
                                            </td>
                                            {filters.jenis === 'semua' && (
                                                <td className="px-3 py-3">
                                                    <JenisBadge jenis={t.jenis} />
                                                </td>
                                            )}
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                <p className="font-bold text-slate-700 tabular-nums">{fmtDate(t.tanggal_awal)}</p>
                                                <p className="text-[11px] text-slate-400">dari {t.kota_asal}</p>
                                            </td>
                                            <td className="px-3 py-3 font-bold whitespace-nowrap text-slate-700 tabular-nums">
                                                {fmtDate(t.tanggal_akhir)}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">
                                                {t.kota_tujuan}
                                                <TiketBadges trip={t} />
                                                <div className="mt-1">
                                                    <LampiranButton files={t.lampiran} title={`Form ${t.jenis} · ${t.name}`} />
                                                </div>
                                                {t.keterangan && (
                                                    <p className="max-w-48 truncate text-[11px] text-slate-400" title={t.keterangan}>
                                                        {t.keterangan}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-center whitespace-nowrap text-slate-600 tabular-nums">{t.lama} hari</td>
                                            <td className="px-3 py-3">
                                                <StatusBadge status={t.status} sumber={t.sumber} catatan={t.catatan_verifikasi} />
                                            </td>
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                <span
                                                    className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-inset ${status.className}`}
                                                >
                                                    {status.label}
                                                </span>
                                                <p className="mt-0.5 text-[11px] text-slate-400">{perjalananNote(t)}</p>
                                            </td>
                                            <td className="px-3 py-3">
                                                <select
                                                    value={t.status_uang ?? ''}
                                                    onChange={(e) => setUang(t, e.target.value)}
                                                    disabled={!can_edit}
                                                    aria-label={`Status pengajuan uang ${t.name}`}
                                                    className={`cursor-pointer rounded-lg border px-2 py-1 text-xs font-bold outline-none focus:ring-2 focus:ring-brand-500 ${uangTone(t.status_uang)}`}
                                                >
                                                    {!t.status_uang && <option value="">-</option>}
                                                    {t.status_uang && !status_uang.includes(t.status_uang) && (
                                                        <option value={t.status_uang}>{t.status_uang}</option>
                                                    )}
                                                    {status_uang.map((s) => (
                                                        <option key={s} value={s}>
                                                            {s}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="py-3 pr-5">
                                                <div className="flex justify-end gap-1">
                                                    {can_edit && t.status === 'diajukan' && (
                                                        <VerifyButtons
                                                            url={`/attendance/trip-leave/${t.id}/verifikasi`}
                                                            what={`${t.jenis} ${t.name} ke ${t.kota_tujuan}`}
                                                        />
                                                    )}
                                                    {can_edit && (
                                                        <>
                                                            <button
                                                                onClick={() => setEditing(t)}
                                                                title="Ubah"
                                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                            >
                                                                <Pencil size={15} />
                                                            </button>
                                                            <button
                                                                onClick={() => remove(t)}
                                                                title="Hapus"
                                                                className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                                            >
                                                                <Trash2 size={15} />
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                        <span>{trips.total ? `Menampilkan ${trips.from}–${trips.to} dari ${trips.total}` : '0 data'}</span>
                        <div className="flex items-center gap-1">
                            <PageLink url={trips.prev_page_url} label="Sebelumnya">
                                <ChevronLeft size={15} />
                            </PageLink>
                            <span className="px-2 font-bold text-slate-600 tabular-nums">
                                {trips.current_page} / {Math.max(trips.last_page, 1)}
                            </span>
                            <PageLink url={trips.next_page_url} label="Berikutnya">
                                <ChevronRight size={15} />
                            </PageLink>
                        </div>
                    </div>
                </div>
            </div>

            {editing && (
                <Modal
                    title={`Ubah ${editing.jenis === 'dinas' ? 'Dinas' : 'Cuti'} — ${editing.name}`}
                    subtitle="Hari-hari di Absensi ikut menyesuaikan."
                    onClose={() => setEditing(null)}
                    width="max-w-xl"
                >
                    <TripForm
                        people={people}
                        cities={cities}
                        bandara={bandara}
                        limits={lampiran_limits}
                        trip={editing}
                        layout="stack"
                        onDone={() => setEditing(null)}
                    />
                </Modal>
            )}
        </>
    );
}

function PageLink({ url, label, children }: { url: string | null; label: string; children: React.ReactNode }) {
    const className = 'rounded-lg border border-slate-200 p-1.5';
    return url ? (
        <Link href={url} preserveScroll preserveState title={label} aria-label={label} className={`${className} hover:bg-slate-50`}>
            {children}
        </Link>
    ) : (
        <span aria-disabled="true" className={`${className} opacity-40`}>
            {children}
        </span>
    );
}
