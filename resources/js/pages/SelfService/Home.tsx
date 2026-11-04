import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Briefcase, ChevronRight, Clock, Fingerprint, Glasses, LogIn, LogOut, Palmtree, ReceiptText, ShieldCheck, Stethoscope, Timer, UserPen, X } from 'lucide-react';
import { InstallCard } from '@/components/InstallCard';
import { MobileClockSheet, type MobileClockState } from '@/components/time/MobileClockSheet';
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
import { forgetClock, saveClock, useClockQueue } from '@/lib/clockQueue';
import { OvertimeForm, type OvertimeFormInfo, type OvertimeRow } from '@/components/time/OvertimeForm';

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
    /** Today's punches (HH:mm) and the planned shift. */
    attendance_today: { in: string | null; out: string | null; shift: string | null };
    latest_payslip: { id: number; label: string; take_home_pay: number; pay_date: string | null } | null;
    mobile_clock: MobileClockState;
    /** What is kept and why, until the employee agrees (UU PDP). */
    mobile_consent: { version: string; points: string[] } | null;
    /** Own overtime of the last 60 days, from the machines or asked for here. */
    overtime_records: OvertimeRow[];
    overtime_form: OvertimeFormInfo;
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
    attendance_today,
    latest_payslip,
    mobile_clock,
    mobile_consent,
    overtime_records,
    overtime_form,
}: Props) {
    const [clock, setClock] = useState<'in' | 'out' | null>(null);
    const { app, user } = usePage().props.auth;
    const [form, setForm] = useState<'claim' | 'leave' | 'business_trip' | 'overtime' | null>(null);
    const { waiting } = useClockQueue(user?.id);

    // Kept on the phone for the offline page, so a punch can be taken when the app opens with no signal.
    useEffect(() => {
        if (!user) return;
        if (mobile_clock.enabled && mobile_clock.consented) void saveClock({ user: user.id, name: employee.name, state: mobile_clock, saved_at: Date.now() });
        else void forgetClock();
    }, [user, employee.name, mobile_clock]);

    // A clock-in still waiting on the phone counts: the next one is the way home.
    const today_ = new Date().toDateString();
    const next = mobile_clock.next === 'in' && waiting.some((p) => p.type === 'in' && new Date(p.taken_at).toDateString() === today_) ? 'out' : mobile_clock.next;
    const person = { id: employee.id, employee_number: employee.employee_number, name: employee.name, position: employee.position, work_location: employee.work_location };
    const p = benefit_limit.medical;
    const used = p.benefit_limit ? Math.min(100, ((p.used + (p.pending ?? 0)) / p.benefit_limit) * 100) : 0;

    const cancel = (type: 'claim' | 'leave-travel' | 'overtime', id: number) => {
        if (confirm('Batalkan pengajuan ini?')) router.delete(`/self-service/submission/${type}/${id}`, { preserveScroll: true });
    };

    const actions = [
        { key: 'claim' as const, label: 'Deklarasi Pengobatan / Kacamata', short: 'Klaim', icon: Stethoscope, tone: 'bg-emerald-600 hover:bg-emerald-700' },
        { key: 'leave' as const, label: 'Ajukan Cuti', short: 'Cuti', icon: Palmtree, tone: 'bg-violet-600 hover:bg-violet-700' },
        { key: 'business_trip' as const, label: 'Ajukan Dinas', short: 'Dinas', icon: Briefcase, tone: 'bg-sky-600 hover:bg-sky-700' },
        ...(overtime_form.eligible ? [{ key: 'overtime' as const, label: 'Ajukan Lembur', short: 'Lembur', icon: Timer, tone: 'bg-amber-600 hover:bg-amber-700' }] : []),
    ];

    // The three newest pengajuan of any kind, for the top of the page.
    const recent = [
        ...benefit_claims.map((d) => ({
            key: `c${d.id}`, at: d.date, icon: Stethoscope, status: d.status,
            title: `Deklarasi · Rp ${rupiah(d.total)}`, subtitle: `${fmtDate(d.date)} · ${d.claim_number}`,
        })),
        ...leave_travel_requests.map((t) => ({
            key: `t${t.id}`, at: t.start_date, icon: t.type === 'business_trip' ? Briefcase : Palmtree, status: t.status,
            title: `${t.type === 'business_trip' ? 'Dinas' : 'Cuti'} ke ${t.destination_city} · ${t.old} hari`,
            subtitle: `${fmtDate(t.start_date)} – ${fmtDate(t.end_date)}`,
        })),
        ...overtime_records
            .filter((o) => o.source === 'portal')
            .map((o) => ({ key: `o${o.id}`, at: o.date, icon: Timer, status: o.status, title: `Lembur · ${o.summary}`, subtitle: o.reason ?? '' })),
    ]
        .sort((a, b) => (b.at ?? '').localeCompare(a.at ?? ''))
        .slice(0, 3);

    return (
        <>
            <Head title="Layanan Mandiri" />
            <div className="space-y-5">
                <InstallCard />

                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <p className="text-xs font-bold text-slate-400">{greeting()},</p>
                    <h1 className="text-xl font-black text-slate-800">{employee.name}</h1>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {employee.employee_number} · {employee.position ?? 'Belum ditempatkan'}
                        {employee.work_location ? ` · ${employee.work_location}` : ''}
                        <span className="hidden sm:inline"> · Status keluarga <b>{benefit_limit.ptkp_status}</b></span>
                    </p>

                    {/* Kehadiran hari ini: from the machine or entered by HR. */}
                    <div className="mt-4 rounded-2xl bg-brand-50/70 p-3 ring-1 ring-brand-100">
                        <div className="flex items-center justify-between gap-2">
                            <p className="flex items-center gap-1.5 text-xs font-bold text-brand-700">
                                <Fingerprint size={14} /> Kehadiran hari ini
                            </p>
                            <p className="text-[11px] font-semibold text-slate-500">{fmtDate(today)}</p>
                        </div>
                        <div className="mt-2 grid grid-cols-3 gap-2 text-center">
                            <Punch label="Masuk" value={attendance_today.in} />
                            <Punch label="Pulang" value={attendance_today.out} />
                            <div className="rounded-xl bg-white px-2 py-2">
                                <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">Jadwal</p>
                                <p className="truncate text-sm font-black text-slate-700">{attendance_today.shift ?? '-'}</p>
                            </div>
                        </div>
                        {/* Absen dari HP, where the work location uses it. */}
                        {mobile_clock.enabled && (
                            <div className="mt-2 flex items-center gap-2">
                                <button
                                    onClick={() => setClock(next)}
                                    className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-brand-600 px-3 py-3 text-sm font-black text-white shadow-sm hover:bg-brand-700"
                                >
                                    {next === 'in' ? <LogIn size={17} /> : <LogOut size={17} />}
                                    {next === 'in' ? 'Absen Masuk' : 'Absen Pulang'}
                                </button>
                                {next === 'out' && (
                                    <button
                                        onClick={() => setClock('in')}
                                        className="rounded-xl bg-white px-3 py-3 text-xs font-bold text-slate-500 ring-1 ring-slate-200 hover:text-brand-700"
                                    >
                                        Masuk
                                    </button>
                                )}
                            </div>
                        )}
                        {mobile_clock.today.some((t) => t.source === 'mobile' && t.outside) && (
                            <p className="mt-2 text-[11px] font-semibold text-amber-700">Ada absen hari ini yang ditandai di luar area kerja.</p>
                        )}
                    </div>

                    <div className={`mt-3 grid gap-2 ${actions.length > 3 ? 'grid-cols-4' : 'grid-cols-3'}`}>
                        {actions.map((a) => (
                            <button
                                key={a.key}
                                onClick={() => setForm(a.key)}
                                className={`flex flex-col items-center justify-center gap-1 rounded-2xl px-2 py-3 text-xs font-bold text-white shadow-sm sm:flex-row sm:gap-2 sm:px-4 sm:text-sm ${a.tone}`}
                            >
                                <a.icon size={18} />
                                <span className="sm:hidden">{a.short}</span>
                                <span className="hidden sm:inline">{a.label}</span>
                            </button>
                        ))}
                    </div>
                </section>

                {app && !app.pin && !app.passkey && (
                    <Link href="/account/app" className="flex items-center gap-3 rounded-3xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                        <ShieldCheck size={20} className="shrink-0" />
                        <span className="min-w-0 flex-1 text-sm">
                            <b className="block">Buat PIN aplikasi</b>
                            <span className="text-xs">Agar tetap masuk di HP ini{app.days ? ` sampai ${app.days} hari` : ''}, dan aplikasi dibuka dengan PIN atau sidik jari.</span>
                        </span>
                        <ChevronRight size={18} />
                    </Link>
                )}

                {/* At a glance: leave left, the newest slip, the medical plafon. */}
                <section className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <Tile
                        href="#cuti"
                        icon={Palmtree}
                        tone="text-violet-600"
                        label="Sisa cuti tahunan"
                        value={leave.annual.present ? `${(leave.annual.balance ?? 0) - (leave.annual.debt ?? 0)} hari` : '-'}
                        note={leave.annual.pending ? `${leave.annual.pending} hari menunggu` : undefined}
                    />
                    <Tile
                        href={latest_payslip ? `/self-service/payslips?slip=${latest_payslip.id}` : '/self-service/payslips'}
                        icon={ReceiptText}
                        tone="text-brand-600"
                        label={latest_payslip ? `Slip ${latest_payslip.label}` : 'Slip gaji'}
                        value={latest_payslip ? `Rp ${rupiah(latest_payslip.take_home_pay)}` : 'Belum ada'}
                        note={latest_payslip?.pay_date ? `dibayar ${fmtDate(latest_payslip.pay_date)}` : undefined}
                    />
                    <Tile
                        href="#plafon"
                        icon={Stethoscope}
                        tone="text-emerald-600"
                        label="Sisa plafon pengobatan"
                        value={p.benefit_limit === null ? '-' : `Rp ${rupiah(p.remaining)}`}
                        className="col-span-2 sm:col-span-1"
                    />
                </section>

                {recent.length > 0 && (
                    <section className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <h2 className="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-sm font-bold text-slate-700 sm:px-5">
                            <Clock size={15} className="text-brand-500" /> Pengajuan terakhir
                        </h2>
                        <ul className="divide-y divide-slate-100">
                            {recent.map((r) => (
                                <li key={r.key} className="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                                    <r.icon size={16} className="shrink-0 text-slate-400" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-bold text-slate-700">{r.title}</p>
                                        <p className="truncate text-[11px] text-slate-400">{r.subtitle}</p>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${STATUS[r.status].className}`}>
                                        {STATUS[r.status].label}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

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
                <section id="plafon" className="grid scroll-mt-20 gap-4 md:grid-cols-2 [&>*]:min-w-0">
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
                                extra={d.workflow ? <FlowNote flow={d.workflow} /> : undefined}
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

                {(overtime_form.eligible || overtime_records.length > 0) && (
                    <History title="Lembur saya (60 hari)" empty="Belum ada lembur.">
                        {overtime_records.map((o) => (
                            <Item
                                key={o.id}
                                status={o.status}
                                note={o.verification_notes}
                                title={`${o.summary}${o.start && o.end ? ` · ${o.start}–${o.end}` : ''}`}
                                subtitle={o.source === 'portal' ? `Diajukan dari HP${o.reason ? ` · ${o.reason}` : ''}` : 'Terhitung dari absen'}
                                extra={o.workflow ? <FlowNote flow={o.workflow} /> : undefined}
                                attachmentTitle="Lembur"
                                onCancel={o.status === 'pending' && o.source === 'portal' ? () => cancel('overtime', o.id) : undefined}
                            />
                        ))}
                    </History>
                )}
            </div>

            {clock && user && <MobileClockSheet state={mobile_clock} consent={mobile_consent} type={clock} user={user.id} onClose={() => setClock(null)} />}
            {form === 'overtime' && <OvertimeForm info={overtime_form} today={today} onClose={() => setForm(null)} />}
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

function greeting(): string {
    const h = new Date().getHours();
    return h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 19 ? 'Selamat sore' : 'Selamat malam';
}

function Punch({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="rounded-xl bg-white px-2 py-2">
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">{label}</p>
            <p className={`text-lg leading-tight font-black tabular-nums ${value ? 'text-brand-700' : 'text-slate-300'}`}>{value ?? '—'}</p>
        </div>
    );
}

function Tile({
    href,
    icon: Icon,
    tone,
    label,
    value,
    note,
    className = '',
}: {
    href: string;
    icon: typeof Palmtree;
    tone: string;
    label: string;
    value: string;
    note?: string;
    className?: string;
}) {
    const body = (
        <>
            <Icon size={18} className={tone} />
            <p className="mt-2 truncate text-[11px] font-bold text-slate-400">{label}</p>
            <p className="truncate text-base font-black text-slate-800 tabular-nums">{value}</p>
            {note && <p className="truncate text-[11px] text-slate-400">{note}</p>}
        </>
    );
    const cls = `block min-w-0 rounded-3xl border border-slate-200 bg-white p-3.5 shadow-sm transition hover:border-brand-200 ${className}`;
    return href.startsWith('#') ? (
        <a href={href} className={cls}>
            {body}
        </a>
    ) : (
        <Link href={href} className={cls}>
            {body}
        </Link>
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
    attachment?: LampiranFile[];
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
            {attachment && (
                <span className="shrink-0">
                    <AttachmentButton files={attachment} title={attachmentTitle} noun={noun} />
                </span>
            )}
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
        <section id="cuti" className="grid scroll-mt-20 gap-4 md:grid-cols-2 [&>*]:min-w-0">
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
