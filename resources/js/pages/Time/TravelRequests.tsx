import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Download, FileUp, Pencil, PlaneLanding, PlaneTakeoff, Search, Ticket, Trash2 } from 'lucide-react';
import { LeaveTravelForm, type TripData, type TripPerson } from '@/components/time/LeaveTravelForm';
import { LeaveTravelImportDialog } from '@/components/time/LeaveTravelImportDialog';
import { AttendanceHeader, TypeBadge, Modal, secondaryButton, smallInput } from '@/components/time/ui';
import { fmtDate } from '@/lib/format';
import { StatusBadge, VerifyButtons } from '@/components/Verification';
import { TiketBadges, type Airport } from '@/components/time/AirportPicker';
import { AttachmentButton } from '@/components/attachment/AttachmentPicker';
import type { LampiranLimits } from '@/lib/attachment';
import { REQUEST_TYPE, label } from '@/lib/labels';

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
    type: 'business_trip' | 'leave' | 'all';
    travel: '' | 'soon' | 'ongoing' | 'end_date';
    cash: string;
    status: '' | 'pending' | 'approved' | 'rejected';
    ticket: '' | 'not_yet';
    q: string;
}

interface Props {
    leave_travel_requests: Paginated<TripData>;
    filters: Filters;
    cash_advance_status: string[];
    people: TripPerson[];
    cities: string[];
    /** From the employee portal, waiting for HR, per jenis. */
    pending: Partial<Record<'business_trip' | 'leave', number>>;
    /** Approved trips with a ticket still to buy. */
    tickets_pending: number;
    can_edit: boolean;
    airport: Airport[];
    attachment_limits: LampiranLimits;
}

const PERJALANAN = {
    soon: { label: 'Akan berangkat', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    ongoing: { label: 'Dalam perjalanan', className: 'bg-sky-50 text-sky-700 ring-sky-200' },
    end_date: { label: 'Selesai', className: 'bg-slate-100 text-slate-600 ring-slate-200' },
} as const;

/** Colour of a money status, read from its wording (the list itself is edited in Master Data). */
export function uangTone(status: string | null): string {
    const s = (status ?? '').toLowerCase();
    if (s.includes('reject') || s.includes('cancel')) return 'bg-rose-50 text-rose-700 border-rose-200';
    if (s.includes('end_date') || s.includes('paid_off')) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    if (s.includes('disbursed')) return 'bg-teal-50 text-teal-700 border-teal-200';
    if (s.includes('agree')) return 'bg-sky-50 text-sky-700 border-sky-200';
    if (s.includes('not_yet')) return 'bg-slate-50 text-slate-600 border-slate-200';
    if (s.includes('submit')) return 'bg-amber-50 text-amber-700 border-amber-200';
    return 'bg-brand-50 text-brand-700 border-brand-200';
}

function perjalananNote(t: TripData): string {
    if (t.travel === 'soon') return t.day_number === 1 ? 'Berangkat besok' : `H-${t.day_number}`;
    if (t.travel === 'ongoing') return `Hari ke-${t.day_number} dari ${t.old}`;
    return t.day_number <= 1 ? 'Kembali kemarin' : `Kembali ${t.day_number} hari lalu`;
}

export default function RequestNotifier({ leave_travel_requests, filters, cash_advance_status, people, cities, pending, tickets_pending, can_edit, airport, attachment_limits }: Props) {
    const waiting = (pending.business_trip ?? 0) + (pending.leave ?? 0);
    const [search, setSearch] = useState(filters.q);
    const [editing, setEditing] = useState<TripData | null>(null);
    const [importing, setImporting] = useState(false);
    const first = useRef(true);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (merged.type !== 'business_trip') query.type = merged.type;
        if (merged.travel) query.travel = merged.travel;
        if (merged.cash) query.cash = merged.cash;
        if (merged.status) query.status = merged.status;
        if (merged.ticket) query.ticket = merged.ticket;
        if (merged.q.trim()) query.q = merged.q.trim();
        router.get('/time/travel-requests', query, { preserveState: true, preserveScroll: true, replace: true });
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
        router.put(`/time/leave-travel/${t.id}/cash-status`, { cash_advance_status: status }, { preserveScroll: true, preserveState: true });

    const remove = (t: TripData) => {
        if (
            confirm(
                `Hapus ${t.type === 'business_trip' ? 'dinas' : 'cuti'} ${t.name} ke ${t.destination_city} (${fmtDate(t.start_date)} – ${fmtDate(t.end_date)})? Hari-harinya di Absensi ikut dikosongkan.`,
            )
        ) {
            router.delete(`/time/leave-travel/${t.id}`, { preserveScroll: true, preserveState: true });
        }
    };

    const jenisTabs = [
        { value: 'business_trip', label: 'Dinas' },
        { value: 'leave', label: 'Cuti' },
        { value: 'all', label: 'Semua' },
    ] as const;
    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const exportUrl = () => {
        const q = new URLSearchParams();
        for (const [k, v] of Object.entries({ ...filters, q: filters.q.trim() })) if (v) q.set(k, v);
        return `/time/leave-travel/export?${q}`;
    };

    return (
        <>
            <Head title="Pengajuan Dinas" />
            <AttendanceHeader title="Pengajuan Dinas" subtitle="Status perjalanan dihitung dari tanggal hari ini dan tanggal berangkat">
                <Link
                    href="/time/leave-travel"
                    className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-brand-600 hover:bg-brand-50"
                >
                    <ArrowLeft size={15} />
                    Cuti &amp; Dinas
                </Link>
                {can_edit && (
                    <button onClick={() => setImporting(true)} className={secondaryButton} title="Import banyak pengajuan dari Excel">
                        <FileUp size={16} />
                        Import Excel
                    </button>
                )}
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
                                    onClick={() => apply({ type: t.value })}
                                    className={`rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                        filters.type === t.value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>
                        <button
                            onClick={() => apply(filters.status === 'pending' ? { status: '' } : { status: 'pending', type: 'all' })}
                            className={`rounded-xl px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${
                                filters.status === 'pending'
                                    ? 'bg-amber-500 text-white ring-amber-500'
                                    : waiting
                                      ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                      : 'text-slate-400 ring-slate-200'
                            }`}
                        >
                            Perlu persetujuan ({waiting})
                        </button>
                        <button
                            onClick={() => apply(filters.ticket ? { ticket: '' } : { ticket: 'not_yet', type: 'all' })}
                            className={`inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${
                                filters.ticket
                                    ? 'bg-sky-600 text-white ring-sky-600'
                                    : tickets_pending
                                      ? 'bg-sky-50 text-sky-700 ring-sky-200'
                                      : 'text-slate-400 ring-slate-200'
                            }`}
                            title="Pengajuan yang tiket pergi / pulangnya belum dibelikan"
                        >
                            <Ticket size={14} />
                            Tiket belum dibeli ({tickets_pending})
                        </button>
                        <select
                            value={filters.travel}
                            onChange={(e) => apply({ travel: e.target.value as Filters['travel'] })}
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
                            value={filters.cash}
                            onChange={(e) => apply({ cash: e.target.value })}
                            className={smallInput}
                            aria-label="Status pengajuan uang"
                        >
                            <option value="">Semua status uang</option>
                            {cash_advance_status.map((s) => (
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
                                placeholder="Cari nama / NRP / NIK / kota"
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
                                    {filters.type === 'all' && <th className={th}>Jenis</th>}
                                    <th className={th}>Berangkat</th>
                                    <th className={th}>Kembali</th>
                                    <th className={th}>Tujuan</th>
                                    <th className={`${th} text-center`}>Lama</th>
                                    <th className={th}>Tiket</th>
                                    <th className={th}>Persetujuan</th>
                                    <th className={th}>Status Perjalanan</th>
                                    <th className={th}>Status Pengajuan Uang</th>
                                    <th className={`${th} pr-5 text-right`}>Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {leave_travel_requests.data.length === 0 && (
                                    <tr>
                                        <td colSpan={12} className="px-5 py-12 text-center text-sm text-slate-400">
                                            Belum ada data. Input perjalanan dinas / cuti di halaman Cuti &amp; Dinas.
                                        </td>
                                    </tr>
                                )}
                                {leave_travel_requests.data.map((t, i) => {
                                    const status = PERJALANAN[t.travel];
                                    return (
                                        <tr key={t.id} className="hover:bg-slate-50/60">
                                            <td className="py-3 pl-5 text-xs text-slate-400 tabular-nums">{(leave_travel_requests.from ?? 1) + i}</td>
                                            <td className="min-w-56 px-3 py-3">
                                                <p className="font-bold text-slate-700">{t.name}</p>
                                                <p className="text-[11px] text-slate-400">
                                                    {t.employee_number} · {t.position ?? 'Belum ditempatkan'}
                                                </p>
                                                <p className="text-[11px] text-slate-400">
                                                    NIK <span className="text-slate-600 tabular-nums">{t.national_id_number ?? '—'}</span>
                                                </p>
                                                <p className="text-[11px] text-slate-400">
                                                    POH <span className="font-bold text-slate-600">{t.point_of_hire ?? '—'}</span>
                                                    {t.employee_class && ` · ${t.employee_class}`}
                                                </p>
                                            </td>
                                            {filters.type === 'all' && (
                                                <td className="px-3 py-3">
                                                    <TypeBadge type={t.type} />
                                                </td>
                                            )}
                                            <td className="px-3 py-3 whitespace-nowrap">
                                                <p className="font-bold text-slate-700 tabular-nums">{fmtDate(t.start_date)}</p>
                                                <p className="text-[11px] text-slate-400">dari {t.origin_city}</p>
                                            </td>
                                            <td className="px-3 py-3 font-bold whitespace-nowrap text-slate-700 tabular-nums">
                                                {fmtDate(t.end_date)}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">
                                                {t.destination_city}
                                                <TiketBadges leave_travel={t} />
                                                <div className="mt-1">
                                                    <AttachmentButton files={t.attachment} title={`Form ${label(REQUEST_TYPE, t.type)} · ${t.name}`} />
                                                </div>
                                                {t.description && (
                                                    <p className="max-w-48 truncate text-[11px] text-slate-400" title={t.description}>
                                                        {t.description}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-center whitespace-nowrap text-slate-600 tabular-nums">{t.old} hari</td>
                                            <td className="px-3 py-3">
                                                <TiketStatus leave_travel={t} canEdit={can_edit} />
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusBadge status={t.status} source={t.source} notes={t.verification_notes} />
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
                                                    value={t.cash_advance_status ?? ''}
                                                    onChange={(e) => setUang(t, e.target.value)}
                                                    disabled={!can_edit}
                                                    aria-label={`Status pengajuan uang ${t.name}`}
                                                    className={`cursor-pointer rounded-lg border px-2 py-1 text-xs font-bold outline-none focus:ring-2 focus:ring-brand-500 ${uangTone(t.cash_advance_status)}`}
                                                >
                                                    {!t.cash_advance_status && <option value="">-</option>}
                                                    {t.cash_advance_status && !cash_advance_status.includes(t.cash_advance_status) && (
                                                        <option value={t.cash_advance_status}>{t.cash_advance_status}</option>
                                                    )}
                                                    {cash_advance_status.map((s) => (
                                                        <option key={s} value={s}>
                                                            {s}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="py-3 pr-5">
                                                <div className="flex justify-end gap-1">
                                                    {can_edit && t.status === 'pending' && (
                                                        <VerifyButtons
                                                            url={`/time/leave-travel/${t.id}/verification`}
                                                            what={`${label(REQUEST_TYPE, t.type)} ${t.name} ke ${t.destination_city}`}
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
                        <span>{leave_travel_requests.total ? `Menampilkan ${leave_travel_requests.from}–${leave_travel_requests.to} dari ${leave_travel_requests.total}` : '0 data'}</span>
                        <div className="flex items-center gap-1">
                            <PageLink url={leave_travel_requests.prev_page_url} label="Sebelumnya">
                                <ChevronLeft size={15} />
                            </PageLink>
                            <span className="px-2 font-bold text-slate-600 tabular-nums">
                                {leave_travel_requests.current_page} / {Math.max(leave_travel_requests.last_page, 1)}
                            </span>
                            <PageLink url={leave_travel_requests.next_page_url} label="Berikutnya">
                                <ChevronRight size={15} />
                            </PageLink>
                        </div>
                    </div>
                </div>
            </div>

            {importing && <LeaveTravelImportDialog onClose={() => setImporting(false)} />}
            {editing && (
                <Modal
                    title={`Ubah ${editing.type === 'business_trip' ? 'Dinas' : 'Cuti'} — ${editing.name}`}
                    subtitle="Hari-hari di Absensi ikut menyesuaikan."
                    onClose={() => setEditing(null)}
                    width="max-w-xl"
                >
                    <LeaveTravelForm
                        people={people}
                        cities={cities}
                        airport={airport}
                        limits={attachment_limits}
                        leave_travel={editing}
                        layout="stack"
                        onDone={() => setEditing(null)}
                    />
                </Modal>
            )}
        </>
    );
}

/** Whether the ticket going and the one coming back are bought; HR ticks it when they buy it. */
function TiketStatus({ leave_travel, canEdit }: { leave_travel: TripData; canEdit: boolean }) {
    const direction = [
        { key: 'outbound' as const, label: 'Pergi', icon: PlaneTakeoff, present: leave_travel.needs_flight || leave_travel.needs_train },
        { key: 'return' as const, label: 'Pulang', icon: PlaneLanding, present: leave_travel.needs_flight && leave_travel.is_round_trip },
    ].filter((a) => a.present);
    if (direction.length === 0) return <span className="text-xs text-slate-300">—</span>;
    const toggle = (key: 'outbound' | 'return', issued: boolean) =>
        router.put(`/time/leave-travel/${leave_travel.id}/ticket`, { direction: key, issued }, { preserveScroll: true, preserveState: true });
    return (
        <div className="flex flex-col gap-1">
            {direction.map(({ key, label, icon: Icon }) => {
                const d = leave_travel.tickets_issued[key];
                const title = d
                    ? `Tiket ${key} dibeli ${new Date(d.at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}${d.by ? ` oleh ${d.by}` : ''}`
                    : `Tiket ${key} belum dibeli`;
                return (
                    <button
                        key={key}
                        onClick={() => canEdit && toggle(key, !d)}
                        disabled={!canEdit}
                        title={canEdit ? `${title} — klik untuk ${d ? 'cancelling' : 'menandai sudah dibeli'}` : title}
                        className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold whitespace-nowrap ring-1 ring-inset ${
                            d ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'
                        } ${canEdit ? 'cursor-pointer hover:brightness-95' : 'cursor-default'}`}
                    >
                        <Icon size={11} />
                        {label}: {d ? 'Sudah dibeli' : 'Belum'}
                    </button>
                );
            })}
        </div>
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
