import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Check, Download, Loader2, Plus, Search, Trash2, X } from 'lucide-react';
import { AttendanceHeader, Modal, ghostButton, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { DateInput } from '@/components/DateInput';
import { fmtDate } from '@/lib/format';
import { BESAR_STATUS, KATEGORI_LABEL, base_pay, itemWhen, type Besar, type BesarPeriode, type CutiItem, type Tahunan } from '@/lib/leave';

interface Row {
    id: number;
    employee_number: string;
    name: string;
    position: string | null;
    department: string | null;
    work_location: string | null;
    sub_location: string | null;
    hired: string | null;
    years_of_service: string | null;
    annual: Tahunan;
    long_service: Besar;
    transaction_count: number;
}

interface Props {
    tab: 'annual' | 'long_service';
    filters: { location: number | null; sub: string; q: string; status: string };
    rows: Row[];
    locations: { id: number; name: string }[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    policy: {
        annual_entitlement_days: number;
        annual_validity_years: number;
        long_service_interval_years: number;
        long_service_days: number;
        long_service_taken_salary_multiplier: number;
        long_service_cashout_salary_multiplier: number;
        long_service_decision_months: number;
    };
    long_service_pending: number;
    can_edit: boolean;
    today: string;
}

const visit = { preserveScroll: true, preserveState: true } as const;
const th = 'border-b border-slate-200 bg-slate-100 px-2 py-2 text-[10px] font-black tracking-wide text-slate-600 uppercase';
const td = 'border-b border-slate-100 px-2 py-1.5';

/**
 * Comben → Cuti Tahunan & Besar. Tahunan follows the old "SISA CUTI TAHUNAN" sheet:
 * one row per employee with the active balance and each leave as Hari | Tgl.
 */
export default function CutiPage({ tab, filters, rows, locations, sub_locations, policy, long_service_pending, can_edit, today }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [detail, setDetail] = useState<number | null>(null);
    const first = useRef(true);

    const go = (next: Partial<Props['filters'] & { tab: Props['tab'] }>) => {
        const m = { tab, ...filters, q: search, ...next };
        const query: Record<string, string> = { tab: m.tab };
        if (m.location) query.location = String(m.location);
        if (m.sub) query.sub = m.sub;
        if (m.q.trim()) query.q = m.q.trim();
        if (m.status && m.tab === 'long_service') query.status = m.status;
        router.get('/time/leave-balances', query, { ...visit, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => go({ q: search }), 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const exportUrl = () => {
        const q = new URLSearchParams();
        if (filters.location) q.set('location', String(filters.location));
        if (filters.sub) q.set('sub', filters.sub);
        if (filters.q) q.set('q', filters.q);
        if (tab === 'long_service' && filters.status) q.set('status', filters.status);
        return `/time/leave-balances/export?${q}`;
    };

    return (
        <>
            <Head title="Saldo Cuti" />
            <AttendanceHeader
                title="Cuti Tahunan & Cuti Besar"
                subtitle={`Tahunan ${policy.annual_entitlement_days} hari/thn, berlaku ${policy.annual_validity_years} thn (FIFO) · Besar tiap ${policy.long_service_interval_years} thn: ${policy.long_service_days} hari + ${base_pay(policy.long_service_taken_salary_multiplier)}, atau ${base_pay(policy.long_service_cashout_salary_multiplier)}`}
            >
                <a href={exportUrl()} className={secondaryButton} title="Sisa cuti tahunan, Cuti Besar dan rincian pengambilan">
                    <Download size={16} />
                    Export Excel
                </a>
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50 p-6">
                <div className="flex min-h-0 flex-1 flex-col rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <div className="flex rounded-xl bg-slate-100 p-1">
                            {(
                                [
                                    ['annual', 'Cuti Tahunan'],
                                    ['long_service', 'Cuti Besar'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    onClick={() => go({ tab: value })}
                                    className={`flex items-center gap-1.5 rounded-lg px-3.5 py-1 text-sm font-bold ${tab === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                                >
                                    {label}
                                    {value === 'long_service' && long_service_pending > 0 && (
                                        <span className="rounded-full bg-amber-500 px-1.5 text-[10px] text-white">{long_service_pending}</span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <select
                            value={filters.location ?? ''}
                            onChange={(e) => go({ location: e.target.value ? Number(e.target.value) : null, sub: '' })}
                            className={smallInput}
                            aria-label="Lokasi"
                        >
                            <option value="">Semua lokasi</option>
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                        <select value={filters.sub} onChange={(e) => go({ sub: e.target.value })} className={smallInput} aria-label="Sub lokasi">
                            <option value="">Semua sub lokasi</option>
                            <option value="none">Tanpa sub lokasi</option>
                            {sub_locations
                                .filter((s) => !filters.location || s.work_location_id === filters.location)
                                .map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                        </select>
                        {tab === 'long_service' && (
                            <select
                                value={filters.status}
                                onChange={(e) => go({ status: e.target.value })}
                                className={smallInput}
                                aria-label="Status cuti besar"
                            >
                                <option value="">Semua status</option>
                                {Object.entries(BESAR_STATUS).map(([v, s]) => (
                                    <option key={v} value={v}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama / NRP"
                                className={`${smallInput} w-56 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-auto">
                        {tab === 'annual' ? (
                            <TahunanTable rows={rows} onOpen={setDetail} />
                        ) : (
                            <BesarTable rows={rows} canEdit={can_edit} onOpen={setDetail} today={today} />
                        )}
                    </div>
                    <div className="border-t border-slate-100 px-5 py-2 text-xs text-slate-400">
                        {rows.length} karyawan aktif · klik nama untuk rincian per tahun
                    </div>
                </div>
            </div>

            {detail !== null && <Detail id={detail} canEdit={can_edit} onClose={() => setDetail(null)} />}
        </>
    );
}

// --- Cuti Tahunan, in the layout of the old sheet ------------------------------------------

function TahunanTable({ rows, onOpen }: { rows: Row[]; onOpen: (id: number) => void }) {
    const sticky = 'sticky z-10 bg-white';

    return (
        <table className="min-w-full border-separate border-spacing-0 text-xs">
            <thead className="sticky top-0 z-20">
                <tr>
                    <th className={`${th} ${sticky} left-0 w-10 !bg-slate-100`}>No</th>
                    <th className={`${th} ${sticky} left-10 min-w-48 !bg-slate-100 text-left`}>Nama Lengkap</th>
                    <th className={th}>NRP</th>
                    <th className={`${th} text-left`}>Departemen</th>
                    <th className={th}>Tgl. Masuk</th>
                    <th className={th}>Masa Kerja</th>
                    <th className={`${th} !bg-emerald-100 text-emerald-800`}>Sisa Cuti Tahunan Aktif</th>
                    <th className={th}>Total Cuti Diajukan</th>
                    <th className={th}>Hutang</th>
                    <th className={th}>Menunggu</th>
                    <th className={th}>Hangus 31.12</th>
                    <th className={th}>Jatah Berikutnya</th>
                    <th className={th}>Pengambilan Cuti</th>
                </tr>
            </thead>
            <tbody>
                {rows.length === 0 && (
                    <tr>
                        <td colSpan={13} className="px-5 py-12 text-center text-sm text-slate-400">
                            Tidak ada karyawan.
                        </td>
                    </tr>
                )}
                {rows.map((r, n) => {
                    const t = r.annual;
                    return (
                        <tr key={r.id} onClick={() => onOpen(r.id)} className="group cursor-pointer hover:bg-brand-50/40">
                            <td className={`${td} ${sticky} left-0 text-center text-slate-400 group-hover:bg-brand-50`}>{n + 1}</td>
                            <td className={`${td} ${sticky} left-10 group-hover:bg-brand-50`}>
                                <span className="font-bold text-slate-700 group-hover:text-brand-600">{r.name}</span>
                                <span className="block text-[10px] text-slate-400">
                                    {[r.work_location, r.sub_location].filter(Boolean).join(' · ')}
                                </span>
                            </td>
                            <td className={`${td} font-mono text-slate-600`}>{r.employee_number}</td>
                            <td className={`${td} text-slate-600`}>{r.department ?? '-'}</td>
                            <td className={`${td} text-center whitespace-nowrap tabular-nums`}>{fmtDate(r.hired) || '-'}</td>
                            <td className={`${td} whitespace-nowrap text-slate-500`}>{r.years_of_service ?? '-'}</td>
                            <td className={`${td} bg-emerald-50/50 text-center`}>
                                {t.present ? (
                                    <b className={`text-sm tabular-nums ${(t.balance ?? 0) === 0 ? 'text-rose-600' : 'text-emerald-700'}`}>{t.balance}</b>
                                ) : (
                                    <span className="text-[10px] text-amber-700" title={t.reason}>
                                        Tgl masuk kosong
                                    </span>
                                )}
                            </td>
                            <td className={`${td} text-center tabular-nums`}>{t.taken_total}</td>
                            <td className={`${td} text-center tabular-nums ${t.debt ? 'font-bold text-rose-600' : 'text-slate-300'}`}>
                                {t.debt || '-'}
                            </td>
                            <td className={`${td} text-center tabular-nums ${t.pending ? 'font-bold text-amber-700' : 'text-slate-300'}`}>
                                {t.pending || '-'}
                            </td>
                            <td className={`${td} text-center tabular-nums ${t.expires_end_of_year ? 'text-amber-700' : 'text-slate-300'}`}>
                                {t.expires_end_of_year || '-'}
                            </td>
                            <td className={`${td} text-center whitespace-nowrap text-slate-500 tabular-nums`}>
                                {t.present ? fmtDate(t.next_entitlement_date) : '-'}
                            </td>
                            <td className={`${td} text-center`}>
                                <span className="inline-flex items-center gap-1 rounded-lg bg-brand-50 px-2 py-1 text-[11px] font-bold whitespace-nowrap text-brand-700 group-hover:bg-brand-100">
                                    Lihat ({r.transaction_count})
                                </span>
                            </td>
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

// --- Cuti Besar ------------------------------------------------------------------------------

function BesarTable({ rows, canEdit, onOpen, today }: { rows: Row[]; canEdit: boolean; onOpen: (id: number) => void; today: string }) {
    return (
        <table className="min-w-full text-left text-sm">
            <thead className="sticky top-0 z-10 bg-slate-50">
                <tr>
                    <th className={`${th} pl-5 text-left`}>Karyawan</th>
                    <th className={th}>Tgl Masuk</th>
                    <th className={`${th} text-left`}>Hak Cuti Besar</th>
                    <th className={th}>Berikutnya</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
                {rows.length === 0 && (
                    <tr>
                        <td colSpan={4} className="px-5 py-12 text-center text-sm text-slate-400">
                            Tidak ada karyawan.
                        </td>
                    </tr>
                )}
                {rows.map((r) => (
                    <tr key={r.id} className="align-top">
                        <td className="min-w-56 py-3 pr-3 pl-5">
                            <button onClick={() => onOpen(r.id)} className="text-left font-bold text-slate-700 hover:text-brand-600 hover:underline">
                                {r.name}
                            </button>
                            <p className="text-[11px] text-slate-400">
                                {r.employee_number} · {r.years_of_service ?? '-'}
                            </p>
                        </td>
                        <td className="px-3 py-3 text-xs whitespace-nowrap tabular-nums">{fmtDate(r.hired) || '-'}</td>
                        <td className="px-3 py-3">
                            {r.long_service.period.length === 0 ? (
                                <span className="text-xs text-slate-400">Belum mencapai</span>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {r.long_service.period.map((p) => (
                                        <PeriodeCard key={p.sequence_no} employeeId={r.id} p={p} canEdit={canEdit} today={today} />
                                    ))}
                                </div>
                            )}
                        </td>
                        <td className="px-3 py-3 text-xs whitespace-nowrap text-slate-500">
                            {r.long_service.next ? (
                                <>
                                    {fmtDate(r.long_service.next)}
                                    <span className="block text-[10px] text-slate-400">{r.long_service.next_years_of_service} thn</span>
                                </>
                            ) : (
                                '-'
                            )}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function PeriodeCard({ employeeId, p, canEdit, today }: { employeeId: number; p: BesarPeriode; canEdit: boolean; today: string }) {
    const s = BESAR_STATUS[p.status];
    const decide = (decision: 'taken' | 'cashed_out' | null) => {
        const what = decision
            ? decision === 'taken'
                ? `Diambil (${p.sequence_no}) — cuti + 1× gapok`
                : 'Diuangkan — 2× gapok'
            : 'kosongkan pilihan';
        if (confirm(`Cuti Besar ${p.years_of_service} thn: ${what}?`))
            router.post(`/time/leave-balances/employee/${employeeId}/long-service`, { sequence_no: p.sequence_no, decision }, visit);
    };
    const nearDeadline = p.status === 'not_chosen' && p.limit <= addDays(today, 60);
    return (
        <div className="w-60 rounded-2xl border border-slate-200 p-2.5 text-xs">
            <div className="flex items-center justify-between gap-2">
                <b className="text-slate-700">
                    Ke-{p.sequence_no} · {p.years_of_service} thn
                </b>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${s.className}`}>{s.label}</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
                Hak {fmtDate(p.entitlement_date)} · pilih s/d <span className={nearDeadline ? 'font-bold text-rose-600' : ''}>{fmtDate(p.limit)}</span>
            </p>
            {p.status === 'taken' && (
                <p className="text-[11px] text-emerald-700">
                    Sisa <b>{p.remaining}</b> / {p.days} hari{p.pending ? ` · ${p.pending} menunggu` : ''} · {base_pay(p.base_pay)}
                </p>
            )}
            {(p.status === 'cashed_out' || p.status === 'auto_cashed_out') && (
                <p className="text-[11px] text-violet-700">Kompensasi {base_pay(p.base_pay)}</p>
            )}
            {p.status === 'pending' && (
                <p className="text-[11px] text-sky-700">Karyawan memilih: {p.decision === 'taken' ? 'Diambil' : 'Diuangkan'}</p>
            )}
            {canEdit && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {p.status === 'pending' && p.id ? (
                        <>
                            <VerifyBesar id={p.id} />
                        </>
                    ) : (
                        <>
                            {p.status !== 'taken' && (
                                <SmallButton tone="emerald" onClick={() => decide('taken')}>
                                    Ambil
                                </SmallButton>
                            )}
                            {p.status !== 'cashed_out' && (
                                <SmallButton tone="violet" onClick={() => decide('cashed_out')}>
                                    Uangkan
                                </SmallButton>
                            )}
                            {(p.status === 'taken' || p.status === 'cashed_out') && (
                                <SmallButton tone="slate" onClick={() => decide(null)}>
                                    Kosongkan
                                </SmallButton>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

/** Confirms or rejects a choice sent from the portal. */
function VerifyBesar({ id }: { id: number }) {
    const send = (decision: 'approve' | 'reject') => {
        let notes: string | null = null;
        if (decision === 'reject') {
            notes = prompt('Alasan menolak pilihan Cuti Besar ini:')?.trim() || null;
            if (!notes) return;
        } else if (!confirm('Setujui pilihan Cuti Besar karyawan ini?')) return;
        router.post(`/time/leave-balances/long-service/${id}/verification`, { decision, notes }, visit);
    };
    return (
        <>
            <SmallButton tone="emerald" onClick={() => send('approve')}>
                <Check size={12} /> Setujui
            </SmallButton>
            <SmallButton tone="rose" onClick={() => send('reject')}>
                <X size={12} /> Tolak
            </SmallButton>
        </>
    );
}

function SmallButton({
    tone,
    onClick,
    children,
    disabled,
}: {
    tone: 'emerald' | 'violet' | 'slate' | 'rose';
    onClick: () => void;
    children: React.ReactNode;
    disabled?: boolean;
}) {
    const tones = {
        emerald: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
        violet: 'bg-violet-50 text-violet-700 hover:bg-violet-100',
        slate: 'bg-slate-100 text-slate-600 hover:bg-slate-200',
        rose: 'bg-rose-50 text-rose-700 hover:bg-rose-100',
    };
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-bold disabled:opacity-50 ${tones[tone]}`}
        >
            {children}
        </button>
    );
}

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

// --- one employee ------------------------------------------------------------------------------

interface DetailData {
    employee: { id: number; employee_number: string; name: string; position: string | null };
    annual: Tahunan;
    long_service: Besar;
    transaction: CutiItem[];
    field: { leave: number; departure: number; back: number; total: number } | null;
}

function Detail({ id, canEdit, onClose }: { id: number; canEdit: boolean; onClose: () => void }) {
    const [data, setData] = useState<DetailData | null>(null);
    const [version, setVersion] = useState(0);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ start_date: '', end_date: '', days: '', notes: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        fetch(`/time/leave-balances/employee/${id}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then(setData);
    }, [id, version]);

    const reload = () => setVersion((v) => v + 1);
    const days =
        form.start_date && form.end_date ? Math.round((Date.parse(form.end_date) - Date.parse(form.start_date)) / 86400000) + 1 : null;

    const save = () =>
        router.post(
            `/time/leave-balances/employee/${id}/transaction`,
            { type: 'annual', ...form, end_date: form.end_date || null, days: form.days || days || '' },
            {
                ...visit,
                onError: setErrors,
                onSuccess: () => {
                    setAdding(false);
                    setForm({ start_date: '', end_date: '', days: '', notes: '' });
                    setErrors({});
                    reload();
                },
            },
        );

    const remove = (item: CutiItem) => {
        if (item.id && confirm(`Hapus catatan cuti ${item.days} hari (${itemWhen(item)})?`))
            router.delete(`/time/leave-balances/transaction/${item.id}`, { ...visit, onSuccess: reload });
    };

    const t = data?.annual;

    return (
        <Modal
            title={data ? `Cuti — ${data.employee.name}` : 'Memuat…'}
            subtitle={data ? `${data.employee.employee_number} · ${data.employee.position ?? ''}` : undefined}
            onClose={onClose}
            width="max-w-4xl"
        >
            {!data ? (
                <div className="flex justify-center py-10">
                    <Loader2 className="animate-spin text-slate-400" />
                </div>
            ) : (
                <div className="space-y-5">
                    {t && !t.present && (
                        <p className="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <AlertTriangle size={15} /> {t.reason}
                        </p>
                    )}
                    {t?.present && (
                        <div className="grid gap-3 sm:grid-cols-4">
                            <Stat label="Sisa cuti aktif" value={t.balance} tone="text-emerald-700" />
                            <Stat label="Hutang" value={t.debt} tone={t.debt ? 'text-rose-600' : 'text-slate-400'} />
                            <Stat label="Menunggu persetujuan" value={t.pending} tone={t.pending ? 'text-amber-700' : 'text-slate-400'} />
                            <Stat label="Jatah berikutnya masuk" value={fmtDate(t.next_entitlement_date)} tone="text-slate-500 !text-lg" />
                        </div>
                    )}
                    {data.field && (
                        <p className="text-xs text-slate-500">
                            Cuti lapangan lokasi ini: {data.field.leave} hari + {data.field.departure} berangkat + {data.field.back}{' '}
                            kembali = <b>{data.field.total} hari</b>; lebihnya memotong cuti tahunan.
                        </p>
                    )}

                    {t?.year && t.year.length > 0 && (
                        <div className="overflow-x-auto rounded-2xl border border-slate-200">
                            <table className="w-full text-center text-xs">
                                <thead className="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-2 py-2 text-left">Tahun</th>
                                        {t.year.map((y) => (
                                            <th key={y.year} className="px-2 py-2">
                                                {y.year}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {(
                                        [
                                            ['entitlement', 'Jatah'],
                                            ['taken', 'Diambil'],
                                            ['remaining', 'Sisa aktif'],
                                            ['debt', 'Hutang'],
                                        ] as const
                                    ).map(([k, label]) => (
                                        <tr key={k} className="border-t border-slate-100">
                                            <td className="px-2 py-1.5 text-left font-bold text-slate-600">{label}</td>
                                            {t.year!.map((y) => (
                                                <td
                                                    key={y.year}
                                                    className={`px-2 py-1.5 tabular-nums ${k === 'debt' && y.debt ? 'font-bold text-rose-600' : k === 'remaining' ? 'font-bold text-emerald-700' : 'text-slate-600'}`}
                                                >
                                                    {y[k]}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-700">Riwayat pengambilan ({data.transaction.length})</h3>
                            {canEdit && !adding && (
                                <button onClick={() => setAdding(true)} className={`${ghostButton} flex items-center gap-1.5 text-brand-600`}>
                                    <Plus size={14} /> Catat cuti manual
                                </button>
                            )}
                        </div>
                        {adding && (
                            <div className="mb-3 grid gap-2 rounded-2xl border border-brand-100 bg-brand-50/40 p-3 sm:grid-cols-[150px_150px_90px_1fr_auto]">
                                <DateInput
                                    value={form.start_date}
                                    onChange={(v) => setForm((f) => ({ ...f, start_date: v }))}
                                    className={smallInput}
                                    aria-label="Tanggal awal"
                                />
                                <DateInput
                                    value={form.end_date}
                                    onChange={(v) => setForm((f) => ({ ...f, end_date: v }))}
                                    min={form.start_date || undefined}
                                    className={smallInput}
                                    aria-label="Tanggal akhir"
                                />
                                <input
                                    value={form.days}
                                    onChange={(e) => setForm((f) => ({ ...f, days: e.target.value.replace(/\D/g, '') }))}
                                    placeholder={days ? `${days} hari` : 'Hari'}
                                    className={smallInput}
                                    aria-label="Jumlah hari"
                                />
                                <input
                                    value={form.notes}
                                    onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
                                    placeholder="Catatan (opsional)"
                                    className={smallInput}
                                />
                                <div className="flex gap-1">
                                    <button onClick={save} className={primaryButton}>
                                        Simpan
                                    </button>
                                    <button onClick={() => setAdding(false)} className={ghostButton}>
                                        Batal
                                    </button>
                                </div>
                                {Object.values(errors).length > 0 && (
                                    <p className="text-xs text-rose-600 sm:col-span-5">{Object.values(errors).join(' ')}</p>
                                )}
                            </div>
                        )}
                        <div className="max-h-72 overflow-y-auto rounded-2xl border border-slate-200">
                            <table className="w-full text-left text-xs">
                                <thead className="sticky top-0 bg-slate-50 text-[10px] font-bold text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Tahun</th>
                                        <th className="px-3 py-2">Tanggal</th>
                                        <th className="px-3 py-2">Jenis</th>
                                        <th className="px-3 py-2 text-right">Hari</th>
                                        <th className="px-3 py-2 text-right">Potong tahunan</th>
                                        <th className="px-3 py-2">Dipotong dari jatah</th>
                                        <th className="px-3 py-2">Sumber</th>
                                        <th className="w-8" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.transaction.length === 0 && (
                                        <tr>
                                            <td colSpan={8} className="px-3 py-6 text-center text-slate-400">
                                                Belum ada pengambilan cuti.
                                            </td>
                                        </tr>
                                    )}
                                    {[...data.transaction].reverse().map((i, n) => (
                                        <tr key={n} className={i.status === 'pending' ? 'bg-amber-50/50' : ''}>
                                            <td className="px-3 py-1.5 tabular-nums">{i.year}</td>
                                            <td className="px-3 py-1.5">{itemWhen(i)}</td>
                                            <td className="px-3 py-1.5">
                                                {KATEGORI_LABEL[i.type] ?? i.type}
                                                {i.status === 'pending' && <span className="ml-1 text-amber-700">(menunggu)</span>}
                                            </td>
                                            <td className="px-3 py-1.5 text-right tabular-nums">{i.days}</td>
                                            <td className="px-3 py-1.5 text-right font-bold tabular-nums">{i.deduct}</td>
                                            <td className="px-3 py-1.5">
                                                <PotongDari item={i} />
                                            </td>
                                            <td className="px-3 py-1.5 text-slate-500">
                                                {{ import: 'Excel lama', manual: 'Manual HR', leave_travel: 'Cuti & Dinas' }[i.source]}
                                            </td>
                                            <td className="px-1 py-1.5">
                                                {canEdit && i.id && (
                                                    <button
                                                        onClick={() => remove(i)}
                                                        title="Hapus"
                                                        className="rounded p-1 text-slate-300 hover:bg-rose-50 hover:text-rose-600"
                                                    >
                                                        <Trash2 size={13} />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </Modal>
    );
}

function Stat({ label, value, tone }: { label: string; value: number | string | undefined; tone: string }) {
    return (
        <div className="rounded-2xl border border-slate-200 px-4 py-3">
            <p className="text-[11px] font-bold text-slate-400">{label}</p>
            <p className={`text-2xl font-black tabular-nums ${tone}`}>{value ?? '-'}</p>
        </div>
    );
}

/** "Cuti 2024 · 2 hr", "Hutang → cuti 2026 · 1 hr": which year's quota a leave was taken from. */
function PotongDari({ item }: { item: CutiItem }) {
    if (item.status === 'pending') return <span className="text-amber-700">menunggu persetujuan</span>;
    if (!item.deduct) return <span className="text-slate-400">tidak memotong</span>;
    if (!item.deducted_from.length) return <span className="text-slate-400">—</span>;
    return (
        <span className="flex flex-wrap gap-1">
            {item.deducted_from.map((p, n) => (
                <span
                    key={n}
                    title={
                        p.debt
                            ? p.entitlement
                                ? `Melebihi saldo saat diambil; dibayar dari jatah ${p.entitlement}`
                                : 'Melebihi saldo; belum terbayar'
                            : `Dari jatah cuti tahun ${p.entitlement}`
                    }
                    className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold whitespace-nowrap ring-1 ring-inset ${
                        p.debt ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-emerald-50 text-emerald-700 ring-emerald-100'
                    }`}
                >
                    {p.debt ? (p.entitlement ? `Hutang → cuti ${p.entitlement}` : 'Hutang') : `Cuti ${p.entitlement}`} · {p.days} hr
                </span>
            ))}
        </span>
    );
}
