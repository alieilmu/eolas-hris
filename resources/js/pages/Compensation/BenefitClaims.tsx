import { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { AttendanceHeader, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { BenefitClaimForm, type DeklarasiPerson } from '@/components/compensation/BenefitClaimForm';
import { rupiah, type DeklarasiRow } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';
import { StatusBadge, VerifyButtons } from '@/components/Verification';
import { FlowNote } from '@/components/ApprovalHistory';
import { AttachmentButton } from '@/components/attachment/AttachmentPicker';
import type { LampiranLimits } from '@/lib/attachment';

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
    month: string;
    type: '' | 'medical' | 'optical';
    status: '' | 'pending' | 'approved' | 'rejected';
    q: string;
}

interface Props {
    benefit_claims: Paginated<DeklarasiRow>;
    filters: Filters;
    accounting_month: { current: string; label: string; span: string; options: { value: string; label: string }[] };
    people: DeklarasiPerson[];
    provider_options: string[];
    /** From the employee portal, waiting for HR. */
    pending: number;
    can_edit: boolean;
    attachment_limits: LampiranLimits;
}

const PENERIMA = { employee: 'Karyawan', spouse: 'Istri/Suami', children: 'Anak' } as const;
const JENIS = { treatment: 'Perawatan', medical: 'Pengobatan', frame: 'Bingkai', lens: 'Lensa' } as const;

export default function BenefitClaim({ benefit_claims, filters, accounting_month, people, provider_options, pending, can_edit, attachment_limits }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [form, setForm] = useState<DeklarasiRow | 'new' | null>(null);
    const first = useRef(true);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (merged.month) query.month = merged.month;
        if (merged.type) query.type = merged.type;
        if (merged.status) query.status = merged.status;
        if (merged.q.trim()) query.q = merged.q.trim();
        router.get('/compensation/claims', query, { preserveState: true, preserveScroll: true, replace: true });
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
        if (confirm(`Hapus deklarasi ${d.claim_number} (${d.name}, Rp ${rupiah(d.total)})? Plafonnya kembali.`)) {
            router.delete(`/compensation/claims/${d.id}`, { preserveScroll: true, preserveState: true });
        }
    };

    const th = 'px-3 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
    const exportUrl = () => {
        const q = new URLSearchParams();
        for (const [k, v] of Object.entries({ month: filters.month, type: filters.type, status: filters.status, q: filters.q.trim() }))
            if (v) q.set(k, v);
        return `/compensation/claims/export?${q}`;
    };

    return (
        <>
            <Head title="Deklarasi Benefit" />
            <AttendanceHeader
                title="Deklarasi Pengobatan / Kacamata"
                subtitle={`Bulan buku berjalan: ${accounting_month.label} (${accounting_month.span}) · tutup buku tiap tanggal 8`}
            >
                <a href={exportUrl()} className={secondaryButton} title="Unduh daftar sesuai filter (rekap + rincian)">
                    <Download size={16} />
                    Export Excel
                </a>
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
                                    ['medical', 'Pengobatan'],
                                    ['optical', 'Kacamata'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    onClick={() => apply({ type: value })}
                                    className={`rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                        filters.type === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <select
                            value={filters.month}
                            onChange={(e) => apply({ month: e.target.value })}
                            className={smallInput}
                            aria-label="Bulan buku"
                        >
                            <option value="">Semua bulan buku</option>
                            {accounting_month.options.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                    {o.value === accounting_month.current ? ' (berjalan)' : ''}
                                </option>
                            ))}
                        </select>
                        <button
                            onClick={() => apply({ status: filters.status === 'pending' ? '' : 'pending' })}
                            className={`rounded-xl px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${
                                filters.status === 'pending'
                                    ? 'bg-amber-500 text-white ring-amber-500'
                                    : pending
                                      ? 'bg-amber-50 text-amber-700 ring-amber-200'
                                      : 'text-slate-400 ring-slate-200'
                            }`}
                        >
                            Perlu verifikasi ({pending})
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
                                {benefit_claims.data.length === 0 && (
                                    <tr>
                                        <td colSpan={9} className="px-5 py-12 text-center text-sm text-slate-400">
                                            Belum ada deklarasi. Tekan "Deklarasi baru" untuk mengisi formulir.
                                        </td>
                                    </tr>
                                )}
                                {benefit_claims.data.map((d) => (
                                    <tr key={d.id} className="align-top hover:bg-slate-50/60">
                                        <td className="px-3 py-3 font-mono text-xs whitespace-nowrap text-slate-600">{d.claim_number}</td>
                                        <td className="px-3 py-3">
                                            <StatusBadge status={d.status} source={d.source} notes={d.verification_notes} />
                                            {d.workflow && <FlowNote flow={d.workflow} />}
                                        </td>
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <p className="font-bold text-slate-700 tabular-nums">{fmtDate(d.date)}</p>
                                            <p className="text-[11px] text-slate-400">
                                                Buku {d.month_label}
                                                {d.provider_name ? ` · ${d.provider_name}` : ''}
                                            </p>
                                        </td>
                                        <td className="min-w-52 px-3 py-3">
                                            <p className="font-bold text-slate-700">{d.name}</p>
                                            <p className="text-[11px] text-slate-400">
                                                {d.employee_number} · {d.position ?? 'Belum ditempatkan'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-3 text-[11px] text-slate-500">
                                            {d.items.map((i, n) => (
                                                <p key={n} className="whitespace-nowrap">
                                                    {JENIS[i.type]} · {i.beneficiary === 'children' ? (i.children ?? 'Anak') : PENERIMA[i.beneficiary]}:{' '}
                                                    <b className="text-slate-700 tabular-nums">{rupiah(i.amount)}</b>
                                                </p>
                                            ))}
                                            <div className="mt-1">
                                                <AttachmentButton files={d.attachment} title={`Nota ${d.claim_number} · ${d.name}`} noun="nota" />
                                            </div>
                                        </td>
                                        <td className="px-3 py-3 text-right text-slate-700 tabular-nums">
                                            {d.medical ? rupiah(d.medical) : '-'}
                                        </td>
                                        <td className="px-3 py-3 text-right text-slate-700 tabular-nums">{d.optical ? rupiah(d.optical) : '-'}</td>
                                        <td className="px-3 py-3 text-right font-black text-slate-800 tabular-nums">{rupiah(d.total)}</td>
                                        <td className="py-3 pr-5">
                                            <div className="flex justify-end gap-1">
                                                {can_edit && d.status === 'pending' && (!d.workflow || d.workflow.can_decide) && (
                                                    <VerifyButtons
                                                        url={`/compensation/claims/${d.id}/verification`}
                                                        what={`deklarasi ${d.claim_number} (${d.name})`}
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
                        <span>{benefit_claims.total ? `Menampilkan ${benefit_claims.from}–${benefit_claims.to} dari ${benefit_claims.total}` : '0 data'}</span>
                        <div className="flex items-center gap-1">
                            <PageLink url={benefit_claims.prev_page_url} label="Sebelumnya">
                                <ChevronLeft size={15} />
                            </PageLink>
                            <span className="px-2 font-bold text-slate-600 tabular-nums">
                                {benefit_claims.current_page} / {Math.max(benefit_claims.last_page, 1)}
                            </span>
                            <PageLink url={benefit_claims.next_page_url} label="Berikutnya">
                                <ChevronRight size={15} />
                            </PageLink>
                        </div>
                    </div>
                </div>
            </div>

            {form && (
                <BenefitClaimForm
                    people={people}
                    providerOptions={provider_options}
                    claim={form === 'new' ? undefined : form}
                    limits={attachment_limits}
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
