import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { AttendanceHeader, primaryButton, smallInput } from '@/components/attendance/ui';
import { DeklarasiForm, type DeklarasiPerson } from '@/components/comben/DeklarasiForm';
import { rupiah, type DeklarasiRow } from '@/lib/comben';
import { fmtDate } from '@/lib/format';
import { StatusBadge, VerifyButtons } from '@/components/Verification';
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
    bulan: string;
    jenis: '' | 'pengobatan' | 'kacamata';
    status: '' | 'diajukan' | 'disetujui' | 'ditolak';
    q: string;
}

interface Props {
    deklarasis: Paginated<DeklarasiRow>;
    filters: Filters;
    bulan_buku: { current: string; label: string; span: string; options: { value: string; label: string }[] };
    people: DeklarasiPerson[];
    tempat_options: string[];
    /** From the employee portal, waiting for HR. */
    menunggu: number;
    can_edit: boolean;
    lampiran_limits: LampiranLimits;
}

const PENERIMA = { karyawan: 'Karyawan', pasangan: 'Istri/Suami', anak: 'Anak' } as const;
const JENIS = { perawatan: 'Perawatan', pengobatan: 'Pengobatan', bingkai: 'Bingkai', lensa: 'Lensa' } as const;

export default function Deklarasi({ deklarasis, filters, bulan_buku, people, tempat_options, menunggu, can_edit, lampiran_limits }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [form, setForm] = useState<DeklarasiRow | 'new' | null>(null);
    const first = useRef(true);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (merged.bulan) query.bulan = merged.bulan;
        if (merged.jenis) query.jenis = merged.jenis;
        if (merged.status) query.status = merged.status;
        if (merged.q.trim()) query.q = merged.q.trim();
        router.get('/comben/deklarasi', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => apply({ q: search }), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const remove = (d: DeklarasiRow) => {
        if (confirm(`Hapus deklarasi ${d.nomor} (${d.name}, Rp ${rupiah(d.total)})? Plafonnya kembali.`)) {
            router.delete(`/comben/deklarasi/${d.id}`, { preserveScroll: true, preserveState: true });
        }
    };

    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <>
            <Head title="Deklarasi" />
            <AttendanceHeader
                title="Deklarasi Pengobatan / Kacamata"
                subtitle={`Bulan buku berjalan: ${bulan_buku.label} (${bulan_buku.span}) · tutup buku tiap tanggal 8`}
            >
                {can_edit && (
                    <button onClick={() => setForm('new')} className={primaryButton}>
                        <Plus size={16} />
                        Deklarasi baru
                    </button>
                )}
            </AttendanceHeader>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-6">
                <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <div className="flex rounded-xl bg-slate-100 p-1">
                            {(
                                [
                                    ['', 'Semua'],
                                    ['pengobatan', 'Pengobatan'],
                                    ['kacamata', 'Kacamata'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    onClick={() => apply({ jenis: value })}
                                    className={`rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                        filters.jenis === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <select
                            value={filters.bulan}
                            onChange={(e) => apply({ bulan: e.target.value })}
                            className={smallInput}
                            aria-label="Bulan buku"
                        >
                            <option value="">Semua bulan buku</option>
                            {bulan_buku.options.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                    {o.value === bulan_buku.current ? ' (berjalan)' : ''}
                                </option>
                            ))}
                        </select>
                        <button
                            onClick={() => apply({ status: filters.status === 'diajukan' ? '' : 'diajukan' })}
                            className={`rounded-xl px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${
                                filters.status === 'diajukan'
                                    ? 'bg-amber-500 text-white ring-amber-500'
                                    : menunggu
                                      ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                      : 'text-slate-400 ring-slate-200'
                            }`}
                        >
                            Perlu verifikasi ({menunggu})
                        </button>
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nomor / nama / NRP"
                                className={`${smallInput} w-60 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className={th}>Nomor</th>
                                    <th className={th}>Status</th>
                                    <th className={th}>Tanggal</th>
                                    <th className={th}>Karyawan</th>
                                    <th className={th}>Rincian</th>
                                    <th className={`${th} text-right`}>Pengobatan</th>
                                    <th className={`${th} text-right`}>Kacamata</th>
                                    <th className={`${th} text-right`}>Total</th>
                                    <th className={`${th} pr-5 text-right`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {deklarasis.data.length === 0 && (
                                    <tr>
                                        <td colSpan={9} className="px-5 py-12 text-center text-sm text-slate-400">
                                            Belum ada deklarasi. Tekan "Deklarasi baru" untuk mengisi formulir.
                                        </td>
                                    </tr>
                                )}
                                {deklarasis.data.map((d) => (
                                    <tr key={d.id} className="align-top hover:bg-slate-50/60">
                                        <td className="px-3 py-3 font-mono text-xs whitespace-nowrap text-slate-600">{d.nomor}</td>
                                        <td className="px-3 py-3">
                                            <StatusBadge status={d.status} sumber={d.sumber} catatan={d.catatan_verifikasi} />
                                        </td>
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <p className="font-bold text-slate-700 tabular-nums">{fmtDate(d.tanggal)}</p>
                                            <p className="text-[11px] text-slate-400">
                                                Buku {d.bulan_label}
                                                {d.tempat ? ` · ${d.tempat}` : ''}
                                            </p>
                                        </td>
                                        <td className="min-w-52 px-3 py-3">
                                            <p className="font-bold text-slate-700">{d.name}</p>
                                            <p className="text-[11px] text-slate-400">
                                                {d.nrp} · {d.jabatan ?? 'Belum ditempatkan'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 text-[11px] text-slate-500">
                                            {d.items.map((i, n) => (
                                                <p key={n} className="whitespace-nowrap">
                                                    {JENIS[i.jenis]} · {i.penerima === 'anak' ? (i.anak ?? 'Anak') : PENERIMA[i.penerima]}:{' '}
                                                    <b className="text-slate-700 tabular-nums">{rupiah(i.nominal)}</b>
                                                </p>
                                            ))}
                                            <div className="mt-1">
                                                <LampiranButton files={d.lampiran} title={`Nota ${d.nomor} · ${d.name}`} noun="nota" />
                                            </div>
                                        </td>
                                        <td className="px-3 py-3 text-right text-slate-700 tabular-nums">
                                            {d.pengobatan ? rupiah(d.pengobatan) : '-'}
                                        </td>
                                        <td className="px-3 py-3 text-right text-slate-700 tabular-nums">{d.kacamata ? rupiah(d.kacamata) : '-'}</td>
                                        <td className="px-3 py-3 text-right font-black text-slate-800 tabular-nums">{rupiah(d.total)}</td>
                                        <td className="py-3 pr-5">
                                            <div className="flex justify-end gap-1">
                                                {can_edit && d.status === 'diajukan' && (
                                                    <VerifyButtons
                                                        url={`/comben/deklarasi/${d.id}/verifikasi`}
                                                        what={`deklarasi ${d.nomor} (${d.name})`}
                                                    />
                                                )}
                                                {can_edit && (
                                                    <>
                                                        <button
                                                            onClick={() => setForm(d)}
                                                            title="Ubah"
                                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                        >
                                                            <Pencil size={15} />
                                                        </button>
                                                        <button
                                                            onClick={() => remove(d)}
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
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                        <span>{deklarasis.total ? `Menampilkan ${deklarasis.from}–${deklarasis.to} dari ${deklarasis.total}` : '0 data'}</span>
                        <div className="flex items-center gap-1">
                            <PageLink url={deklarasis.prev_page_url} label="Sebelumnya">
                                <ChevronLeft size={15} />
                            </PageLink>
                            <span className="px-2 font-bold text-slate-600 tabular-nums">
                                {deklarasis.current_page} / {Math.max(deklarasis.last_page, 1)}
                            </span>
                            <PageLink url={deklarasis.next_page_url} label="Berikutnya">
                                <ChevronRight size={15} />
                            </PageLink>
                        </div>
                    </div>
                </div>
            </div>

            {form && (
                <DeklarasiForm
                    people={people}
                    tempatOptions={tempat_options}
                    deklarasi={form === 'new' ? undefined : form}
                    limits={lampiran_limits}
                    onClose={() => setForm(null)}
                />
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
