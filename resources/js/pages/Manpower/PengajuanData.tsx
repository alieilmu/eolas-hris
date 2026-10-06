import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, ExternalLink, Eye, Search, UserPen, X } from 'lucide-react';
import { AttendanceHeader, Modal, ghostButton, smallInput } from '@/components/attendance/ui';
import { PerubahanTable } from '@/components/profile/PerubahanTable';
import { LampiranButton } from '@/components/lampiran/LampiranPicker';
import { fmtDateTime, type FieldInfo, type PengajuanRow, type Status } from '@/lib/dataUpdate';

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

interface Props {
    items: Paginated<PengajuanRow>;
    filters: { status: Status; q: string };
    counts: Partial<Record<Status, number>>;
    fields: FieldInfo;
    can_edit: boolean;
}

const TABS: { value: Status; label: string }[] = [
    { value: 'diajukan', label: 'Perlu verifikasi' },
    { value: 'disetujui', label: 'Disetujui' },
    { value: 'ditolak', label: 'Ditolak' },
];

/** Pengajuan Update Data from Portal Karyawan: check each change, then approve (the profile changes) or reject with a reason. */
export default function PengajuanData({ items, filters, counts, fields, can_edit }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [open, setOpen] = useState<PengajuanRow | null>(null);
    const first = useRef(true);

    const visit = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, q: search, ...next };
        const query: Record<string, string> = { status: merged.status };
        if (merged.q.trim()) query.q = merged.q.trim();
        router.get('/manpower/pengajuan-data', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const decide = (row: PengajuanRow, keputusan: 'setujui' | 'tolak') => {
        let catatan: string | null = null;
        if (keputusan === 'setujui') {
            if (!confirm(`Setujui ${row.jumlah} perubahan data ${row.name}? Profilnya langsung diperbarui.`)) return;
        } else {
            catatan = prompt(`Alasan menolak pengajuan ${row.name}:`)?.trim() || null;
            if (!catatan) return;
        }
        router.post(
            `/manpower/pengajuan-data/${row.id}/verifikasi`,
            { keputusan, catatan },
            { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(null) },
        );
    };

    const summary = (row: PengajuanRow) => {
        const labels = Object.keys(row.perubahan).map((k) => fields[k]?.label ?? k);
        if (row.anak.length) labels.push(`Data anak (${row.anak.length})`);
        return labels.slice(0, 4).join(', ') + (labels.length > 4 ? `, +${labels.length - 4} lainnya` : '');
    };

    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <>
            <Head title="Pengajuan Update Data" />
            <AttendanceHeader title="Pengajuan Update Data" subtitle="Perubahan data dari Portal Karyawan · berlaku setelah disetujui" />

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-6">
                <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <div className="flex rounded-xl bg-slate-100 p-1">
                            {TABS.map((t) => (
                                <button
                                    key={t.value}
                                    onClick={() => visit({ status: t.value })}
                                    className={`flex items-center gap-1.5 rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                        filters.status === t.value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                    }`}
                                >
                                    {t.label}
                                    {!!counts[t.value] && (
                                        <span
                                            className={`rounded-full px-1.5 text-[10px] ${
                                                filters.status === t.value
                                                    ? 'bg-white/25'
                                                    : t.value === 'diajukan'
                                                      ? 'bg-amber-500 text-white'
                                                      : 'bg-slate-200'
                                            }`}
                                        >
                                            {counts[t.value]}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama / NRP"
                                className={`${smallInput} w-60 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className={`${th} pl-5`}>Karyawan</th>
                                    <th className={th}>Dikirim</th>
                                    <th className={th}>Perubahan</th>
                                    <th className={th}>Lampiran</th>
                                    {filters.status !== 'diajukan' && <th className={th}>Diputuskan</th>}
                                    <th className={`${th} pr-5 text-right`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {items.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-5 py-12 text-center text-sm text-slate-400">
                                            {filters.status === 'diajukan' ? 'Tidak ada pengajuan yang menunggu verifikasi.' : 'Belum ada data.'}
                                        </td>
                                    </tr>
                                )}
                                {items.data.map((row) => (
                                    <tr key={row.id} className="align-top hover:bg-slate-50/60">
                                        <td className="min-w-52 py-3 pr-3 pl-5">
                                            <p className="font-bold text-slate-700">{row.name}</p>
                                            <p className="text-[11px] text-slate-400">
                                                {row.nrp} · {row.jabatan ?? 'Belum ditempatkan'}
                                                {row.work_location ? ` · ${row.work_location}` : ''}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 text-xs whitespace-nowrap text-slate-600 tabular-nums">
                                            {fmtDateTime(row.created_at)}
                                        </td>
                                        <td className="max-w-md px-3 py-3">
                                            <p className="text-xs font-bold text-slate-700">{row.jumlah} perubahan</p>
                                            <p className="text-[11px] text-slate-500">{summary(row)}</p>
                                            {row.catatan && <p className="mt-0.5 text-[11px] text-slate-400 italic">“{row.catatan}”</p>}
                                            {row.sekarang && Object.keys(row.sekarang).length > 0 && (
                                                <p className="mt-0.5 text-[11px] font-bold text-amber-700">
                                                    Sebagian data sudah diubah HR setelah diajukan
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-3 py-3">
                                            <LampiranButton files={row.lampiran} title={`Dokumen ${row.name}`} />
                                        </td>
                                        {filters.status !== 'diajukan' && (
                                            <td className="px-3 py-3 text-[11px] text-slate-500">
                                                {fmtDateTime(row.verified_at)}
                                                {row.verifier && <span className="block">oleh {row.verifier}</span>}
                                                {row.catatan_verifikasi && <span className="block text-rose-600">{row.catatan_verifikasi}</span>}
                                            </td>
                                        )}
                                        <td className="py-3 pr-5">
                                            <div className="flex justify-end gap-1">
                                                <button
                                                    onClick={() => setOpen(row)}
                                                    className="flex items-center gap-1.5 rounded-lg bg-brand-50 px-2.5 py-1.5 text-xs font-bold text-brand-700 hover:bg-brand-100"
                                                >
                                                    <Eye size={14} />
                                                    {filters.status === 'diajukan' && can_edit ? 'Periksa' : 'Lihat'}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                        <span>{items.total ? `Menampilkan ${items.from}–${items.to} dari ${items.total}` : '0 data'}</span>
                        <div className="flex items-center gap-1">
                            <PageLink url={items.prev_page_url} label="Sebelumnya">
                                <ChevronLeft size={15} />
                            </PageLink>
                            <span className="px-2 font-bold text-slate-600 tabular-nums">
                                {items.current_page} / {Math.max(items.last_page, 1)}
                            </span>
                            <PageLink url={items.next_page_url} label="Berikutnya">
                                <ChevronRight size={15} />
                            </PageLink>
                        </div>
                    </div>
                </div>
            </div>

            {open && (
                <Modal
                    title={`Update Data — ${open.name}`}
                    subtitle={`${open.nrp} · dikirim ${fmtDateTime(open.created_at)} · ${open.jumlah} perubahan`}
                    onClose={() => setOpen(null)}
                    width="max-w-3xl"
                    footer={
                        <>
                            <Link
                                href={`/manpower/karyawan/${open.karyawan_id}/profil`}
                                className={`${ghostButton} mr-auto flex items-center gap-1.5`}
                            >
                                <ExternalLink size={14} /> Buka profil
                            </Link>
                            {open.lampiran.length > 0 && <LampiranButton files={open.lampiran} title={`Dokumen ${open.name}`} />}
                            {open.status === 'diajukan' && can_edit && (
                                <>
                                    <button
                                        onClick={() => decide(open, 'tolak')}
                                        className="flex items-center gap-1.5 rounded-xl border border-rose-200 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50"
                                    >
                                        <X size={15} /> Tolak
                                    </button>
                                    <button
                                        onClick={() => decide(open, 'setujui')}
                                        className="flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-700"
                                    >
                                        <Check size={15} /> Setujui & perbarui profil
                                    </button>
                                </>
                            )}
                        </>
                    }
                >
                    <div className="space-y-4">
                        {open.catatan && (
                            <p className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                <UserPen size={15} className="mt-0.5 shrink-0 text-brand-500" />
                                {open.catatan}
                            </p>
                        )}
                        <PerubahanTable row={open} fields={fields} />
                    </div>
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
