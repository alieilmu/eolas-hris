import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, ChevronRight, Clock, Glasses, Palmtree, Plus, Stethoscope, UserPen, X } from 'lucide-react';
import { BenefitClaimForm } from '@/components/compensation/BenefitClaimForm';
import { LeaveTravelForm, type TripData } from '@/components/time/LeaveTravelForm';
import { FlowNote, type WorkflowBrief } from '@/components/ApprovalHistory';
import { Modal } from '@/components/time/ui';
import { rupiah, type DeklarasiRow, type PlafonInfo } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';
import { BESAR_STATUS, base_pay, type CutiInfo } from '@/lib/leave';
import { TiketBadges, type Airport } from '@/components/time/AirportPicker';
import { AttachmentButton } from '@/components/attachment/AttachmentPicker';
import type { LampiranFile, LampiranLimits } from '@/lib/attachment';
import { REQUEST_TYPE, label } from '@/lib/labels';

interface Props {
    employee: PlafonInfo['employee'];
    benefit_limit: Omit<PlafonInfo, 'employee' | 'accounting_month'>;
    accounting_month: { value: string; label: string; span: string };
    /** The server's date: a deklarasi from the portal is always dated today. */
    today: string;
    benefit_claims: (DeklarasiRow & { status: Status; verification_notes: string | null })[];
    leave_travel_requests: (TripData & { status: Status; verification_notes: string | null; workflow: WorkflowBrief | null })[];
    cities: string[];
    provider_options: string[];
    airport: Airport[];
    attachment_limits: LampiranLimits;
    /** A Pengajuan Update Data waiting for HR. */
    data_change_request: boolean;
    leave: CutiInfo;
    leave_policy: { long_service_days: number; long_service_taken_salary_multiplier: number; long_service_cashout_salary_multiplier: number };
}

type Status = 'pending' | 'approved' | 'rejected';

const STATUS: Record<Status, { label: string; className: string }> = {
    pending: { label: 'Menunggu HR', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    approved: { label: 'Disetujui', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    rejected: { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

/** Portal Karyawan: own plafon, and pengajuan of deklarasi, cuti and dinas with their status. */
export default function PortalHome({
    employee,
    benefit_limit,
    accounting_month,
    today,
    benefit_claims,
    leave_travel_requests,
    cities,
    provider_options,
    airport,
    attachment_limits,
    data_change_request,
    leave,
    leave_policy,
}: Props) {
    const [form, setForm] = useState<'claim' | 'leave' | 'business_trip' | null>(null);
    const person = { id: employee.id, employee_number: employee.employee_number, name: employee.name, position: employee.position, work_location: employee.work_location };
    const p = benefit_limit.medical;
    const used = p.benefit_limit ? Math.min(100, ((p.used + (p.pending ?? 0)) / p.benefit_limit) * 100) : 0;

    const cancel = (type: 'claim' | 'leave-travel', id: number) => {
        if (confirm('Batalkan pengajuan ini?')) router.delete(`/self-service/submission/${type}/${id}`, { preserveScroll: true });
    };

    const actions = [
        { key: 'claim' as const, label: 'Deklarasi Pengobatan / Kacamata', icon: Stethoscope, tone: 'bg-emerald-600 hover:bg-emerald-700' },
        { key: 'leave' as const, label: 'Ajukan Cuti', icon: Palmtree, tone: 'bg-violet-600 hover:bg-violet-700' },
        { key: 'business_trip' as const, label: 'Ajukan Dinas', icon: Briefcase, tone: 'bg-sky-600 hover:bg-sky-700' },
    ];

    return (
        <>
            <Head title="Layanan Mandiri" />
            <div className="space-y-5">
                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <p className="text-xs font-bold text-slate-400">Selamat datang,</p>
                    <h1 className="text-xl font-black text-slate-800">{employee.name}</h1>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {employee.employee_number} · {employee.position ?? 'Belum ditempatkan'}
                        {employee.work_location ? ` · ${employee.work_location}` : ''} · Status keluarga <b>{benefit_limit.ptkp_status}</b>
                    </p>
                    <div className="mt-4 grid gap-2 sm:grid-cols-3">
                        {actions.map((a) => (
                            <button
                                key={a.key}
                                onClick={() => setForm(a.key)}
                                className={`flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-bold text-white shadow-sm ${a.tone}`}
                            >
                                <Plus size={16} />
                                <a.icon size={16} />
                                {a.label}
                            </button>
                        ))}
                    </div>
                </section>

                <Link
                    href="/self-service/profile"
                    className="flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-200 hover:bg-brand-50/40"
                >
                    <span className="rounded-2xl bg-brand-50 p-2.5 text-brand-600">
                        <UserPen size={20} />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold text-slate-700">Update Data Saya</span>
                        <span className="block text-xs text-slate-400">
                            {data_change_request
                                ? 'Ada pengajuan yang menunggu verifikasi HR.'
                                : 'Alamat, nomor HP, rekening, keluarga, pendidikan, dokumen — dikirim ke HR untuk diverifikasi.'}
                        </span>
                    </span>
                    {data_change_request && (
                        <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-amber-200">
                            Menunggu HR
                        </span>
                    )}
                    <ChevronRight size={18} className="text-slate-300" />
                </Link>

                {/* Plafon */}
                <section className="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
                    <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <Stethoscope size={16} className="text-emerald-600" />
                            Plafon Pengobatan {benefit_limit.year}
                        </h2>
                        {p.benefit_limit === null ? (
                            <p className="mt-3 text-sm text-amber-700">Plafon belum diatur untuk jabatan Anda. Hubungi HR.</p>
                        ) : (
                            <>
                                <p className="mt-3 text-3xl font-black text-emerald-700 tabular-nums">Rp {rupiah(p.remaining)}</p>
                                <p className="text-xs text-slate-400">
                                    sisa dari Rp {rupiah(p.benefit_limit)} ({benefit_limit.family ? 'Keluarga' : 'Lajang'})
                                </p>
                                <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className="h-full rounded-full bg-emerald-500" style={{ width: `${used}%` }} />
                                </div>
                                <div className="mt-2 flex justify-between text-xs text-slate-500">
                                    <span>Terpakai Rp {rupiah(p.used)}</span>
                                    {!!p.pending && <span className="font-bold text-amber-700">Menunggu HR Rp {rupiah(p.pending)}</span>}
                                </div>
                            </>
                        )}
                        <p className="mt-3 text-[11px] text-slate-400">
                            Bulan buku berjalan {accounting_month.label} ({accounting_month.span}). Plafon direset tiap awal tahun.
                        </p>
                    </div>
                    <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <Glasses size={16} className="text-sky-600" />
                            Plafon Kacamata
                        </h2>
                        <p className="text-[11px] text-slate-400">Per anggota keluarga · bingkai 2 tahun sekali · lensa 1 tahun sekali</p>
                        <table className="mt-3 w-full text-sm">
                            <thead className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                <tr>
                                    <th className="py-1 text-left">Anggota</th>
                                    <th className="py-1 text-right">Sisa bingkai</th>
                                    <th className="py-1 text-right">Sisa lensa</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {benefit_limit.optical.map((m) => (
                                    <tr key={m.key}>
                                        <td className="py-1.5">
                                            <p className="font-bold text-slate-700">{m.name}</p>
                                            <p className="text-[11px] text-slate-400">{m.relationship}</p>
                                        </td>
                                        <td className="py-1.5 text-right text-slate-700 tabular-nums">{rupiah(m.frame.remaining)}</td>
                                        <td className="py-1.5 text-right text-slate-700 tabular-nums">{rupiah(m.lens.remaining)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Cuti */}
                <CutiCard leave={leave} policy={leave_policy} />

                {/* Pengajuan */}
                <section className="grid gap-4 lg:grid-cols-2 [&>*]:min-w-0">
                    <History title="Deklarasi saya" empty="Belum ada deklarasi.">
                        {benefit_claims.map((d) => (
                            <Item
                                key={d.id}
                                status={d.status}
                                note={d.verification_notes}
                                title={`${d.claim_number} · Rp ${rupiah(d.total)}`}
                                subtitle={`${fmtDate(d.date)} · buku ${d.month_label}`}
                                attachment={d.attachment}
                                attachmentTitle={`Nota ${d.claim_number}`}
                                noun="nota"
                                onCancel={d.status === 'pending' ? () => cancel('claim', d.id) : undefined}
                            />
                        ))}
                    </History>
                    <History title="Cuti & dinas saya" empty="Belum ada pengajuan cuti atau dinas.">
                        {leave_travel_requests.map((t) => (
                            <Item
                                key={t.id}
                                status={t.status}
                                note={t.verification_notes}
                                title={`${t.type === 'business_trip' ? 'Dinas' : 'Cuti'} ke ${t.destination_city} · ${t.old} hari`}
                                subtitle={`${fmtDate(t.start_date)} – ${fmtDate(t.end_date)}`}
                                extra={
                                    <>
                                        <TiketBadges leave_travel={t} />
                                        {t.workflow && <FlowNote flow={t.workflow} />}
                                    </>
                                }
                                attachment={t.attachment}
                                attachmentTitle={`Form ${label(REQUEST_TYPE, t.type)}`}
                                onCancel={t.status === 'pending' ? () => cancel('leave-travel', t.id) : undefined}
                            />
                        ))}
                    </History>
                </section>
            </div>

            {form === 'claim' && (
                <BenefitClaimForm
                    people={[person]}
                    providerOptions={provider_options}
                    self={{ person, infoUrl: '/self-service/benefit-limit', submitUrl: '/self-service/claim', date: today }}
                    limits={attachment_limits}
                    onClose={() => setForm(null)}
                />
            )}
            {(form === 'leave' || form === 'business_trip') && (
                <Modal
                    title={form === 'leave' ? 'Ajukan Cuti' : 'Ajukan Perjalanan Dinas'}
                    subtitle="Dikirim ke HR; masuk Absensi setelah disetujui."
                    onClose={() => setForm(null)}
                    width="max-w-xl"
                >
                    <LeaveTravelForm
                        key={form}
                        people={[person]}
                        cities={cities}
                        airport={airport}
                        limits={attachment_limits}
                        layout="stack"
                        self={{ person, submitUrl: '/self-service/leave-travel' }}
                        initialType={form}
                        onDone={() => setForm(null)}
                    />
                </Modal>
            )}
        </>
    );
}

function History({ title, empty, children }: { title: string; empty: string; children: React.ReactNode[] }) {
    return (
        <div className="min-w-0 rounded-3xl border border-slate-200 bg-white shadow-sm">
            <h2 className="flex items-center gap-2 border-b border-slate-100 px-4 py-3.5 sm:px-5 text-sm font-bold text-slate-700">
                <Clock size={15} className="text-brand-500" />
                {title}
            </h2>
            {children.length ? (
                <ul className="divide-y divide-slate-100">{children}</ul>
            ) : (
                <p className="px-5 py-8 text-center text-sm text-slate-400">{empty}</p>
            )}
        </div>
    );
}

function Item({
    status,
    note,
    title,
    subtitle,
    extra,
    attachment,
    attachmentTitle,
    noun,
    onCancel,
}: {
    status: Status;
    note: string | null;
    title: string;
    subtitle: string;
    extra?: React.ReactNode;
    attachment: LampiranFile[];
    attachmentTitle: string;
    noun?: string;
    onCancel?: () => void;
}) {
    const s = STATUS[status];
    return (
        <li className="flex items-start gap-3 px-5 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-bold text-slate-700">{title}</p>
                <p className="text-[11px] text-slate-400">{subtitle}</p>
                {note && <p className="mt-0.5 text-[11px] text-rose-600">Catatan HR: {note}</p>}
                {extra}
            </div>
            <span className="shrink-0">
                <AttachmentButton files={attachment} title={attachmentTitle} noun={noun} />
            </span>
            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${s.className}`}>{s.label}</span>
            {onCancel && (
                <button onClick={onCancel} title="Batalkan" className="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                    <X size={14} />
                </button>
            )}
        </li>
    );
}

/** Own Cuti Tahunan balance, and each Cuti Besar reached with its choice (take it or cash it in). */
function CutiCard({ leave, policy }: { leave: CutiInfo; policy: Props['leave_policy'] }) {
    const t = leave.annual;
    const choose = (sequence_no: number, decision: 'taken' | 'cashed_out') => {
        const what =
            decision === 'taken'
                ? `diambil sebagai cuti ${policy.long_service_days} hari + ${base_pay(policy.long_service_taken_salary_multiplier)}`
                : `diuangkan ${base_pay(policy.long_service_cashout_salary_multiplier)}`;
        if (confirm(`Cuti Besar ${what}? Pilihan dikirim ke HR untuk disetujui.`))
            router.post('/self-service/long-service-leave', { sequence_no, decision }, { preserveScroll: true });
    };
    return (
        <section className="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
            <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <Palmtree size={16} className="text-violet-600" />
                    Cuti Tahunan
                </h2>
                {!t.present ? (
                    <p className="mt-3 text-sm text-amber-700">{t.reason} Hubungi HR.</p>
                ) : (
                    <>
                        <div className="mt-3 overflow-x-auto rounded-2xl border border-slate-200">
                            <table className="w-full text-left text-xs">
                                <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Periode aktif</th>
                                        <th className="px-2 py-2 text-center">Jatah</th>
                                        <th className="px-2 py-2 text-center">Terpakai</th>
                                        <th className="px-3 py-2 text-center">Sisa</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {(t.period ?? []).length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-3 py-3 text-center text-slate-400">
                                                Belum ada periode cuti tahunan yang aktif.
                                            </td>
                                        </tr>
                                    )}
                                    {(t.period ?? []).map((p) => (
                                        <tr key={p.year}>
                                            <td className="px-3 py-2">
                                                <b className="text-slate-700 tabular-nums">
                                                    {fmtDate(p.start_date)} – {fmtDate(p.effective_until)}
                                                </b>
                                                <span className="block text-[10px] text-slate-400">jatah {p.year}</span>
                                            </td>
                                            <td className="px-2 py-2 text-center text-slate-600 tabular-nums">{p.entitlement}</td>
                                            <td className="px-2 py-2 text-center text-slate-600 tabular-nums">{p.used}</td>
                                            <td
                                                className={`px-3 py-2 text-center font-bold tabular-nums ${p.remaining ? 'text-violet-700' : 'text-slate-400'}`}
                                            >
                                                {p.remaining}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-t border-slate-200 bg-slate-50">
                                    <tr>
                                        <td colSpan={3} className="px-3 py-2 font-bold text-slate-600">
                                            Total sisa
                                        </td>
                                        <td className="px-3 py-2 text-center text-sm font-black text-violet-700 tabular-nums">
                                            {(t.balance ?? 0) - (t.debt ?? 0)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div className="mt-3 space-y-0.5 text-xs text-slate-500">
                            {!!t.pending && <p className="text-sky-700">{t.pending} hari sedang menunggu persetujuan HR.</p>}
                            {!!t.debt && <p className="font-bold text-rose-600">Hutang cuti {t.debt} hari (dipotong dari jatah berikutnya).</p>}
                            <p>
                                Cuti tahunan berikutnya matang <b className="text-slate-700">{fmtDate(t.next_entitlement_date)}</b>
                                {t.next_entitlement ? ` (+${t.next_entitlement} hari)` : ''}.
                            </p>
                        </div>
                    </>
                )}
            </div>
            <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <Palmtree size={16} className="text-emerald-600" />
                    Cuti Besar
                </h2>
                {leave.long_service.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-400">Belum ada hak cuti besar yang perlu dipilih.</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {leave.long_service.map((b) => (
                            <li key={b.sequence_no} className="rounded-2xl border border-slate-200 p-3 text-xs">
                                <div className="flex items-center justify-between gap-2">
                                    <b className="text-slate-700">Masa kerja {b.years_of_service} tahun</b>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${BESAR_STATUS[b.status].className}`}
                                    >
                                        {BESAR_STATUS[b.status].label}
                                    </span>
                                </div>
                                <p className="mt-0.5 text-slate-500">
                                    Hak sejak {fmtDate(b.entitlement_date)} · pilih paling lambat {fmtDate(b.limit)}
                                </p>
                                {b.status === 'taken' && (
                                    <p className="text-emerald-700">
                                        Sisa {b.remaining} dari {b.days} hari · ajukan lewat "Ajukan Cuti" jenis Cuti Besar
                                    </p>
                                )}
                                {b.status === 'not_chosen' && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <button
                                            onClick={() => choose(b.sequence_no, 'taken')}
                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 font-bold text-white hover:bg-emerald-700"
                                        >
                                            Ambil cuti ({policy.long_service_days} hari + {base_pay(policy.long_service_taken_salary_multiplier)})
                                        </button>
                                        <button
                                            onClick={() => choose(b.sequence_no, 'cashed_out')}
                                            className="rounded-lg bg-violet-600 px-3 py-1.5 font-bold text-white hover:bg-violet-700"
                                        >
                                            Uangkan ({base_pay(policy.long_service_cashout_salary_multiplier)})
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
