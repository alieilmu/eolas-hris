import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CircleSlash,
    Clock,
    Cpu,
    FileUp,
    Fingerprint,
    Loader2,
    LogIn,
    LogOut,
    Pencil,
    Play,
    Plus,
    Search,
    Timer,
    Trash2,
    Wifi,
    WifiOff,
    X,
} from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, smallInput } from '@/components/time/ui';
import { APPROVAL_STATUS, label } from '@/lib/labels';

type Tab = 'resume' | 'overtime' | 'log' | 'device';

interface Option {
    id: number;
    label: string;
}

interface ResumeRow {
    employee_id: number;
    employee_number: string;
    name: string;
    location: string | null;
    date: string;
    schedule: string | null;
    schedule_type: string | null;
    shift_window: string | null;
    ci: string | null;
    co: string | null;
    log_count: number;
    correction: boolean;
    status: string;
    late: number;
    early_leave: number;
    worked_minutes: number;
    day_type: 'workday' | 'rest_day' | 'public_holiday';
    public_holiday: boolean;
    is_overtime_eligible: boolean;
    overtime: { overtime_minutes: number; paid_hours: number; weighted_hours: number; breakdown: { hours: number; times: number }[] } | null;
    attendance: string | null;
    attendance_source: string | null;
}

interface LemburRow {
    id: number;
    date: string;
    employee_number: string;
    name: string;
    location: string | null;
    day_type: 'workday' | 'rest_day' | 'public_holiday';
    start_date: string | null;
    end_date: string | null;
    work_hours: number;
    paid_hours: number;
    weighted_hours: number;
    status: 'pending' | 'approved' | 'rejected';
    verification_notes: string | null;
    verifier: string | null;
    /** In a Workflow: the step it waits at, and whether this account decides it. */
    workflow: { id: number; step: string | null; can_decide: boolean } | null;
}

interface LogRow {
    id: number;
    punched_at: string;
    pin: string;
    employee_number: string | null;
    name: string | null;
    device: string | null;
    source: 'device' | 'import' | 'manual';
    notes: string | null;
}

interface MesinRow {
    id: number;
    name: string;
    serial_number: string;
    brand: string | null;
    model: string | null;
    work_location_id: number | null;
    location: string | null;
    status: 'active' | 'inactive' | 'new';
    online: boolean;
    last_seen_at: string | null;
    last_ip: string | null;
    logs_today: number;
    last_log: string | null;
    notes: string | null;
}

interface Props {
    tab: Tab;
    filters: { from: string; until: string; location: number | null; q: string; status: string };
    locations: { id: number; name: string }[];
    status_labels: Record<string, string>;
    can: { edit: boolean; approve: boolean };
    new_devices: number;
    // resume
    summary?: Record<string, number | null>;
    rows?: ResumeRow[];
    total_rows?: number;
    employee_options?: Option[];
    // lembur
    overtime_summary?: { key: string; label: string; n: number; hours: number; weighted: number }[];
    overtime_records?: LemburRow[];
    overtime_status?: string;
    // log
    logs?: LogRow[];
    device_options?: { id: number; name: string }[];
    // mesin
    devices?: MesinRow[];
    work_locations?: { id: number; name: string }[];
    server?: { host: string; path: string };
}

const URL = '/time/clock';
const WEEKDAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const STATUS_TONE: Record<string, string> = {
    complete: 'bg-brand-50 text-brand-700',
    missing_clock_out: 'bg-amber-100 text-amber-700',
    missing_clock_in: 'bg-amber-100 text-amber-700',
    no_show: 'bg-rose-100 text-rose-600',
    mismatch: 'bg-violet-100 text-violet-700',
    off: 'bg-slate-100 text-slate-500',
    without_schedule: 'bg-slate-100 text-slate-400',
    business_trip: 'bg-sky-100 text-sky-700',
    leave: 'bg-teal-100 text-teal-700',
    sick: 'bg-orange-100 text-orange-700',
    permission: 'bg-orange-100 text-orange-700',
};
const LEMBUR_TONE = { pending: 'bg-amber-100 text-amber-700', approved: 'bg-brand-100 text-brand-700', rejected: 'bg-rose-100 text-rose-600' };
const HARI_LABEL = { workday: 'Hari kerja', rest_day: 'Hari OFF', public_holiday: 'Libur nasional' };
const hours = (n: number) => `${String(Math.round(n * 100) / 100).replace('.', ',')} j`;
const menit = (m: number) => (m >= 60 ? `${Math.floor(m / 60)}j ${m % 60}m` : `${m}m`);
const tgl = (iso: string) => {
    const d = new Date(`${iso}T00:00:00`);
    return `${WEEKDAY[d.getDay()]}, ${d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}`;
};

export default function TimeClock(props: Props) {
    const { tab, filters, locations, can } = props;
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState(false);

    const go = (patch: Record<string, string | number | null>) => {
        const params = { tab, from: filters.from, until: filters.until, location: filters.location ?? '', q: filters.q, status: filters.status, ...patch };
        const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null)) as Record<string, string>;
        router.get(URL, clean, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };
    const process = () =>
        router.post(
            `${URL}/process`,
            { from: filters.from, until: filters.until, location: filters.location ?? '' },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    const tabs: { id: Tab; label: string; icon: ReactNode; badge?: number }[] = [
        { id: 'resume', label: 'Resume Absensi', icon: <Fingerprint size={15} /> },
        { id: 'overtime', label: 'Lembur', icon: <Timer size={15} /> },
        { id: 'log', label: 'Log Absen', icon: <Clock size={15} /> },
        { id: 'device', label: 'Mesin Absen', icon: <Cpu size={15} />, badge: props.new_devices },
    ];

    return (
        <>
            <Head title="Kehadiran" />
            <AttendanceHeader title="Kehadiran" subtitle="Clock in / clock out dari mesin absen, dibandingkan dengan roster">
                {busy && <Loader2 size={18} className="animate-spin text-brand-600" />}
                {can.edit && tab !== 'device' && (
                    <button onClick={process} className={primaryButton} title="Hitung ulang Absensi & Lembur dari CI/CO untuk rentang ini">
                        <Play size={15} /> Proses ke Absensi
                    </button>
                )}
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-6 py-2.5">
                    <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
                        {tabs.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => go({ tab: t.id, status: t.id === 'overtime' ? 'pending' : 'issues' })}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold transition ${
                                    tab === t.id ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                {t.icon}
                                {t.label}
                                {!!t.badge && <span className="rounded-full bg-amber-400 px-1.5 text-[10px] text-white">{t.badge}</span>}
                            </button>
                        ))}
                    </div>
                    {tab !== 'device' && (
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <input type="date" value={filters.from} onChange={(e) => e.target.value && go({ from: e.target.value })} className={smallInput} />
                            <span className="text-xs text-slate-400">s/d</span>
                            <input type="date" value={filters.until} onChange={(e) => e.target.value && go({ until: e.target.value })} className={smallInput} />
                            {locations.length > 1 && (
                                <select value={filters.location ?? ''} onChange={(e) => go({ location: e.target.value || null })} className={smallInput}>
                                    <option value="">Semua lokasi</option>
                                    {locations.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    go({ q });
                                }}
                                className="relative"
                            >
                                <Search size={14} className="absolute top-2.5 left-2.5 text-slate-400" />
                                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama / NRP" className={`${smallInput} w-44 pl-8`} />
                            </form>
                        </div>
                    )}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-6">
                    {tab === 'resume' && <ResumeTab {...props} go={go} />}
                    {tab === 'overtime' && <LemburTab {...props} go={go} />}
                    {tab === 'log' && <LogTab {...props} />}
                    {tab === 'device' && <MesinTab {...props} />}
                </div>
            </div>
        </>
    );
}

// --- Resume -----------------------------------------------------------------------------------

function ResumeTab({ summary = {}, rows = [], total_rows = 0, filters, status_labels, can, employee_options = [], go }: Props & { go: (p: Record<string, string | number | null>) => void }) {
    const [correction, setKoreksi] = useState<{ employee_id: number; punched_at: string } | null>(null);
    const cards: { key: string; label: string; value: number | string | null | undefined; tone: string; icon: ReactNode }[] = [
        { key: 'all', label: 'Kepatuhan CI/CO', value: summary.compliance !== null && summary.compliance !== undefined ? `${String(summary.compliance).replace('.', ',')}%` : '–', tone: 'text-brand-700', icon: <Check size={15} /> },
        { key: 'complete', label: 'Lengkap', value: summary.complete, tone: 'text-brand-700', icon: <Fingerprint size={15} /> },
        { key: 'missing_clock_in', label: 'Tidak ada CI', value: summary.missing_clock_in, tone: 'text-amber-600', icon: <LogIn size={15} /> },
        { key: 'missing_clock_out', label: 'Tidak ada CO', value: summary.missing_clock_out, tone: 'text-amber-600', icon: <LogOut size={15} /> },
        { key: 'no_show', label: 'Tidak hadir', value: summary.no_show, tone: 'text-rose-600', icon: <CircleSlash size={15} /> },
        { key: 'mismatch', label: 'Tidak sesuai roster', value: summary.mismatch, tone: 'text-violet-700', icon: <AlertTriangle size={15} /> },
        { key: 'late', label: 'Terlambat', value: summary.late, tone: 'text-orange-600', icon: <Clock size={15} /> },
        { key: 'overtime', label: 'Jam lembur', value: summary.overtime_hours !== undefined && summary.overtime_hours !== null ? hours(Number(summary.overtime_hours)) : '–', tone: 'text-teal-700', icon: <Timer size={15} /> },
    ];
    const filterOptions = [
        ['issues', 'Perlu perhatian'],
        ['all', 'Semua hari'],
        ...Object.entries(status_labels),
        ['late', 'Terlambat'],
        ['early_leave', 'Pulang cepat'],
        ['overtime', 'Ada lembur'],
    ];

    return (
        <div className="space-y-5">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                {cards.map((c) => (
                    <button
                        key={c.key}
                        onClick={() => go({ status: c.key })}
                        className={`rounded-2xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-200 ${filters.status === c.key ? 'border-brand-400 ring-2 ring-brand-100' : 'border-slate-100'}`}
                    >
                        <span className={`flex items-center gap-1.5 text-[11px] font-bold ${c.tone}`}>
                            {c.icon}
                            {c.label}
                        </span>
                        <span className="mt-1 block text-2xl font-black text-slate-800">{c.value ?? 0}</span>
                    </button>
                ))}
            </div>

            <div className="rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                    <span className="text-sm font-bold text-slate-700">
                        {total_rows.toLocaleString('id-ID')} hari
                        {total_rows > rows.length && <span className="ml-1 text-xs font-normal text-slate-400">(ditampilkan {rows.length})</span>}
                    </span>
                    <select value={filters.status} onChange={(e) => go({ status: e.target.value })} className={`${smallInput} ml-auto`}>
                        {filterOptions.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                    {can.edit && (
                        <button onClick={() => setKoreksi({ employee_id: 0, punched_at: '' })} className={ghostButton}>
                            <span className="flex items-center gap-1.5">
                                <Plus size={14} /> Koreksi log
                            </span>
                        </button>
                    )}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className="px-3 py-2">Tanggal</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2">Roster</th>
                                <th className="px-3 py-2">CI</th>
                                <th className="px-3 py-2">CO</th>
                                <th className="px-3 py-2">Jam kerja</th>
                                <th className="px-3 py-2">Terlambat / cepat</th>
                                <th className="px-3 py-2">Lembur</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Absensi</th>
                                {can.edit && <th className="px-3 py-2" />}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={`${r.employee_id}-${r.date}`} className="border-t border-slate-100 align-top hover:bg-slate-50/60">
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">
                                        {tgl(r.date)}
                                        {r.public_holiday && <span className="ml-1 rounded bg-rose-50 px-1 text-[10px] font-bold text-rose-500">libur</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="block font-semibold text-slate-700">{r.name}</span>
                                        <span className="text-[11px] text-slate-400">
                                            {r.employee_number} · {r.location}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <span className="font-bold text-slate-700">{r.schedule ?? '–'}</span>
                                        {r.shift_window && <span className="block text-[11px] text-slate-400">{r.shift_window}</span>}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">{r.ci ?? <span className="text-rose-400">—</span>}</td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                        {r.co ?? <span className="text-rose-400">—</span>}
                                        {r.correction && <span className="ml-1 rounded bg-sky-50 px-1 font-sans text-[10px] font-bold text-sky-600">koreksi</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap text-slate-600">{r.worked_minutes ? menit(r.worked_minutes) : '–'}</td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap">
                                        {r.late > 0 && <span className="block text-orange-600">+{menit(r.late)} telat</span>}
                                        {r.early_leave > 0 && <span className="block text-orange-600">−{menit(r.early_leave)} cepat</span>}
                                        {!r.late && !r.early_leave && <span className="text-slate-300">–</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap">
                                        {r.overtime && r.overtime.overtime_minutes > 0 ? (
                                            r.is_overtime_eligible ? (
                                                <span title={r.overtime.breakdown.map((x) => `${x.hours} j × ${x.times}`).join(' + ')}>
                                                    <b className="text-teal-700">{hours(r.overtime.paid_hours)}</b>
                                                    <span className="block text-[10px] text-slate-400">
                                                        {HARI_LABEL[r.day_type]} · terhitung {hours(r.overtime.weighted_hours)}
                                                    </span>
                                                </span>
                                            ) : (
                                                <span className="text-slate-400" title="SubGroup ini tidak berhak upah lembur di lokasi ini">
                                                    tidak berhak
                                                </span>
                                            )
                                        ) : (
                                            <span className="text-slate-300">–</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap ${STATUS_TONE[r.status] ?? 'bg-slate-100'}`}>
                                            {status_labels[r.status] ?? r.status}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-500">
                                        {r.attendance ?? '–'}
                                        {r.attendance_source && <span className="block text-[10px] text-slate-400">{r.attendance_source}</span>}
                                    </td>
                                    {can.edit && (
                                        <td className="px-3 py-2">
                                            {['missing_clock_in', 'missing_clock_out', 'no_show', 'mismatch'].includes(r.status) && (
                                                <button
                                                    onClick={() => setKoreksi({ employee_id: r.employee_id, punched_at: `${r.date}T${r.status === 'missing_clock_in' ? '07:00' : '17:00'}` })}
                                                    title="Tambah log koreksi"
                                                    className="rounded-lg p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <Pencil size={14} />
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={11} className="px-4 py-10 text-center text-sm text-slate-400">
                                        Tidak ada hari dengan status ini pada rentang yang dipilih.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            {correction && <KoreksiModal initial={correction} options={employee_options} onClose={() => setKoreksi(null)} />}
        </div>
    );
}

function KoreksiModal({ initial, options, onClose }: { initial: { employee_id: number; punched_at: string }; options: Option[]; onClose: () => void }) {
    const form = useForm({ employee_id: initial.employee_id || '', punched_at: initial.punched_at, notes: '' });
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.post(`${URL}/log`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal
            title="Koreksi log absen"
            subtitle="Tambahkan CI/CO yang terlewat (mis. lupa absen). Tercatat sebagai koreksi HR dengan alasannya."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => submit()} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label="Karyawan">
                    <select value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)} className={inputClass}>
                        <option value="">Pilih karyawan…</option>
                        {options.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    {form.errors.employee_id && <p className="mt-1 text-xs text-rose-600">{form.errors.employee_id}</p>}
                </Field>
                <Field label="Waktu (jam mesin)">
                    <input type="datetime-local" value={form.data.punched_at} onChange={(e) => form.setData('punched_at', e.target.value)} className={inputClass} />
                    {form.errors.punched_at && <p className="mt-1 text-xs text-rose-600">{form.errors.punched_at}</p>}
                </Field>
                <Field label="Alasan koreksi">
                    <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="mis. Lupa CO, mesin mati" className={inputClass} />
                    {form.errors.notes && <p className="mt-1 text-xs text-rose-600">{form.errors.notes}</p>}
                </Field>
            </form>
        </Modal>
    );
}

// --- Lembur -----------------------------------------------------------------------------------

function LemburTab({ overtime_summary = [], overtime_records = [], overtime_status = 'pending', can, go }: Props & { go: (p: Record<string, string | number | null>) => void }) {
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const decidable = (l: LemburRow) => l.status === 'pending' && (!l.workflow || l.workflow.can_decide);
    const selectable = overtime_records.filter(decidable).map((l) => l.id);
    const all = selectable.length > 0 && selectable.every((id) => selected.includes(id));
    const total = useMemo(() => overtime_records.reduce((s, l) => ({ paid: s.paid + l.paid_hours, weighted: s.weighted + l.weighted_hours }), { paid: 0, weighted: 0 }), [overtime_records]);

    const decide = (ids: number[], decision: 'approved' | 'rejected' | 'pending') => {
        let notes: string | null = null;
        if (decision === 'rejected') {
            notes = prompt('Alasan penolakan:');
            if (notes === null) return;
        }
        router.post(`${URL}/overtime/verification`, { ids, decision, notes } as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setSelected([]),
        });
    };

    return (
        <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-3">
                {overtime_summary.map((s) => (
                    <button
                        key={s.key}
                        onClick={() => go({ status: s.key })}
                        className={`rounded-2xl border bg-white p-4 text-left shadow-sm ${overtime_status === s.key ? 'border-brand-400 ring-2 ring-brand-100' : 'border-slate-100'}`}
                    >
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${LEMBUR_TONE[s.key as keyof typeof LEMBUR_TONE]}`}>{s.label}</span>
                        <span className="mt-2 block text-2xl font-black text-slate-800">{s.n.toLocaleString('id-ID')} hari</span>
                        <span className="text-xs text-slate-500">
                            {hours(s.hours)} dibayar · {hours(s.weighted)} terhitung
                        </span>
                    </button>
                ))}
            </div>
            <p className="rounded-xl bg-brand-50/60 px-4 py-2.5 text-xs text-slate-600">
                <b>Jam terhitung</b> = jam lembur × pengali PP 35/2021 (hari kerja: jam ke-1 × 1,5, berikutnya × 2; hari OFF/libur: × 2 / × 3 / × 4). Upah lembur = jam
                terhitung × 1/173 × (gaji pokok + tunjangan tetap), dihitung di Payroll. Hanya lembur <b>disetujui</b> yang dibayar.
            </p>

            <div className="rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                    <span className="text-sm font-bold text-slate-700">
                        {overtime_records.length.toLocaleString('id-ID')} hari · {hours(total.paid)} · terhitung {hours(total.weighted)}
                    </span>
                    <select value={overtime_status} onChange={(e) => go({ status: e.target.value })} className={`${smallInput} ml-auto`}>
                        <option value="pending">Menunggu</option>
                        <option value="approved">Disetujui</option>
                        <option value="rejected">Ditolak</option>
                        <option value="all">Semua</option>
                    </select>
                    {can.approve && selected.length > 0 && (
                        <>
                            <button onClick={() => decide(selected, 'approved')} disabled={busy} className={primaryButton}>
                                <Check size={15} /> Setujui {selected.length}
                            </button>
                            <button onClick={() => decide(selected, 'rejected')} disabled={busy} className="flex items-center gap-1.5 rounded-xl bg-rose-50 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-100">
                                <X size={15} /> Tolak
                            </button>
                        </>
                    )}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                {can.approve && (
                                    <th className="w-8 px-3 py-2">
                                        <input type="checkbox" checked={all} onChange={(e) => setSelected(e.target.checked ? selectable : [])} disabled={!selectable.length} />
                                    </th>
                                )}
                                <th className="px-3 py-2">Tanggal</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2">Jenis hari</th>
                                <th className="px-3 py-2">CI – CO</th>
                                <th className="px-3 py-2">Jam kerja</th>
                                <th className="px-3 py-2">Lembur</th>
                                <th className="px-3 py-2">Terhitung</th>
                                <th className="px-3 py-2">Status</th>
                                {can.approve && <th className="px-3 py-2" />}
                            </tr>
                        </thead>
                        <tbody>
                            {overtime_records.map((l) => (
                                <tr key={l.id} className="border-t border-slate-100 hover:bg-slate-50/60">
                                    {can.approve && (
                                        <td className="px-3 py-2">
                                            {decidable(l) && (
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(l.id)}
                                                    onChange={(e) => setSelected((prev) => (e.target.checked ? [...prev, l.id] : prev.filter((id) => id !== l.id)))}
                                                />
                                            )}
                                        </td>
                                    )}
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{tgl(l.date)}</td>
                                    <td className="px-3 py-2">
                                        <span className="block font-semibold text-slate-700">{l.name}</span>
                                        <span className="text-[11px] text-slate-400">
                                            {l.employee_number} · {l.location}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-600">{HARI_LABEL[l.day_type]}</td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                        {l.start_date ?? '–'} – {l.end_date ?? '–'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{hours(l.work_hours)}</td>
                                    <td className="px-3 py-2 font-bold text-teal-700">
                                        {hours(l.paid_hours)}
                                        {l.day_type === 'workday' && l.paid_hours > 4 && (
                                            <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] font-bold text-amber-700" title="Melebihi batas wajar 4 jam/hari (PP 35/2021)">
                                                &gt;4j
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-600">{hours(l.weighted_hours)}</td>
                                    <td className="px-3 py-2">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${LEMBUR_TONE[l.status]}`}>{label(APPROVAL_STATUS, l.status)}</span>
                                        {l.verifier && <span className="block text-[10px] text-slate-400">oleh {l.verifier}</span>}
                                        {l.verification_notes && <span className="block text-[10px] text-rose-500">{l.verification_notes}</span>}
                                        {l.workflow && l.status === 'pending' && (
                                            <Link href={`/approvals?type=overtime`} className="block text-[10px] font-semibold text-amber-700 hover:underline">
                                                {l.workflow.step}
                                            </Link>
                                        )}
                                    </td>
                                    {can.approve && (
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {l.status === 'pending' && !decidable(l) ? null : l.status === 'pending' ? (
                                                <>
                                                    <button onClick={() => decide([l.id], 'approved')} title="Setujui" className="rounded-lg p-1 text-brand-600 hover:bg-brand-50">
                                                        <Check size={15} />
                                                    </button>
                                                    <button onClick={() => decide([l.id], 'rejected')} title="Tolak" className="rounded-lg p-1 text-rose-500 hover:bg-rose-50">
                                                        <X size={15} />
                                                    </button>
                                                </>
                                            ) : (
                                                <button onClick={() => decide([l.id], 'pending')} className="text-[11px] font-bold text-slate-400 hover:text-brand-600">
                                                    batalkan
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {overtime_records.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-slate-400">
                                        Tidak ada lembur pada rentang ini.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

// --- Log ----------------------------------------------------------------------------------------

function LogTab({ logs = [], can, employee_options = [], device_options = [] }: Props) {
    const [correction, setKoreksi] = useState(false);
    const form = useForm<{ file: File | null; attendance_device_id: string }>({ file: null, attendance_device_id: '' });
    const SUMBER = { device: 'bg-slate-100 text-slate-600', import: 'bg-violet-100 text-violet-700', manual: 'bg-sky-100 text-sky-700' };

    return (
        <div className="space-y-5">
            {can.edit && (
                <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <div>
                        <p className="text-sm font-bold text-slate-700">Import log dari file</p>
                        <p className="text-[11px] text-slate-400">attlog.dat / txt / csv / xlsx — kolom: PIN (atau NRP), tanggal-jam (atau tanggal + jam)</p>
                    </div>
                    <input type="file" accept=".dat,.txt,.csv,.xlsx,.xls" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="text-xs" />
                    <select value={form.data.attendance_device_id} onChange={(e) => form.setData('attendance_device_id', e.target.value)} className={smallInput}>
                        <option value="">Mesin (opsional)</option>
                        {device_options.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                    <button
                        onClick={() => form.post(`${URL}/import`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() })}
                        disabled={!form.data.file || form.processing}
                        className={primaryButton}
                    >
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <FileUp size={15} />} Import
                    </button>
                    {form.errors.file && <p className="w-full text-xs text-rose-600">{form.errors.file}</p>}
                    <button onClick={() => setKoreksi(true)} className={`${ghostButton} ml-auto`}>
                        <span className="flex items-center gap-1.5">
                            <Plus size={14} /> Koreksi log
                        </span>
                    </button>
                </div>
            )}
            <div className="overflow-x-auto rounded-2xl border border-slate-100 bg-white shadow-sm">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th className="px-3 py-2">Waktu</th>
                            <th className="px-3 py-2">PIN</th>
                            <th className="px-3 py-2">Karyawan</th>
                            <th className="px-3 py-2">Mesin</th>
                            <th className="px-3 py-2">Sumber</th>
                            <th className="px-3 py-2">Catatan</th>
                            {can.edit && <th className="px-3 py-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {logs.map((l) => (
                            <tr key={l.id} className="border-t border-slate-100">
                                <td className="px-3 py-2 font-mono text-xs">{l.punched_at}</td>
                                <td className="px-3 py-2 font-mono text-xs text-slate-500">{l.pin}</td>
                                <td className="px-3 py-2">{l.name ? `${l.employee_number} · ${l.name}` : <span className="text-xs text-rose-500">PIN tidak dikenal</span>}</td>
                                <td className="px-3 py-2 text-xs text-slate-500">{l.device ?? '–'}</td>
                                <td className="px-3 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${SUMBER[l.source]}`}>{l.source === 'manual' ? 'correction' : l.source}</span>
                                </td>
                                <td className="px-3 py-2 text-xs text-slate-500">{l.notes ?? ''}</td>
                                {can.edit && (
                                    <td className="px-3 py-2">
                                        {l.source === 'manual' && (
                                            <button
                                                onClick={() => confirm('Hapus log koreksi ini?') && router.delete(`${URL}/log/${l.id}`, { preserveScroll: true })}
                                                className="rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                                title="Hapus koreksi"
                                            >
                                                <Trash2 size={14} />
                                            </button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                        {logs.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-400">
                                    Belum ada log absen pada rentang ini.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {correction && <KoreksiModal initial={{ employee_id: 0, punched_at: '' }} options={employee_options} onClose={() => setKoreksi(false)} />}
        </div>
    );
}

// --- Mesin --------------------------------------------------------------------------------------

function MesinTab({ devices = [], work_locations = [], server, can }: Props) {
    const [editing, setEditing] = useState<MesinRow | 'new' | null>(null);
    const STATUS = { active: 'bg-brand-100 text-brand-700', inactive: 'bg-slate-100 text-slate-500', new: 'bg-amber-100 text-amber-700' };

    return (
        <div className="space-y-5">
            <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 font-bold text-slate-800">
                            <Cpu size={17} className="text-brand-600" /> Mesin absen ({devices.length})
                        </h2>
                        {can.edit && (
                            <button onClick={() => setEditing('new')} className={primaryButton}>
                                <Plus size={15} /> Tambah mesin
                            </button>
                        )}
                    </div>
                    <div className="space-y-2.5">
                        {devices.map((m) => (
                            <div key={m.id} className={`flex flex-wrap items-center gap-4 rounded-2xl border p-4 ${m.status === 'new' ? 'border-amber-200 bg-amber-50/40' : 'border-slate-100'}`}>
                                <span className={`flex h-10 w-10 items-center justify-center rounded-xl ${m.online ? 'bg-brand-50 text-brand-600' : 'bg-slate-100 text-slate-400'}`}>
                                    {m.online ? <Wifi size={18} /> : <WifiOff size={18} />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold text-slate-800">
                                        {m.name}{' '}
                                        <span className={`ml-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${STATUS[m.status]}`}>
                                            {m.status === 'new' ? 'baru terdeteksi' : m.status}
                                        </span>
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        SN <span className="font-mono">{m.serial_number}</span>
                                        {(m.brand || m.model) && ` · ${[m.brand, m.model].filter(Boolean).join(' ')}`}
                                        {m.location && ` · ${m.location}`}
                                    </p>
                                    <p className="text-[11px] text-slate-400">
                                        {m.online ? 'Online' : 'Offline'}
                                        {m.last_seen_at && ` · terakhir terhubung ${m.last_seen_at}`}
                                        {m.last_ip && ` dari ${m.last_ip}`} · {m.logs_today} log hari ini
                                    </p>
                                </div>
                                {can.edit && (
                                    <div className="flex gap-1">
                                        <button
                                            onClick={() => setEditing(m.status === 'new' ? { ...m, status: 'active', name: '' } : m)}
                                            className={m.status === 'new' ? primaryButton : 'rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600'}
                                            title="Ubah"
                                        >
                                            {m.status === 'new' ? (
                                                <>
                                                    <Check size={15} /> Terima
                                                </>
                                            ) : (
                                                <Pencil size={15} />
                                            )}
                                        </button>
                                        <button
                                            onClick={() =>
                                                confirm(`Keluarkan mesin ${m.name} (${m.serial_number})? Log yang sudah masuk tetap tersimpan.`) &&
                                                router.delete(`${URL}/device/${m.id}`, { preserveScroll: true })
                                            }
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            title="Keluarkan mesin"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    </div>
                                )}
                            </div>
                        ))}
                        {devices.length === 0 && <p className="py-6 text-center text-sm text-slate-400">Belum ada mesin absen.</p>}
                    </div>
                </div>

                <div className="h-fit rounded-2xl border border-brand-100 bg-white p-5 shadow-sm">
                    <h3 className="flex items-center gap-2 font-bold text-slate-800">
                        <Fingerprint size={17} className="text-brand-600" /> Cara menghubungkan mesin
                    </h3>
                    <p className="mt-1 text-xs text-slate-500">Untuk mesin dengan fitur ADMS / Cloud / Push (ZKTeco, Solution, Fingerspot, dan yang kompatibel).</p>
                    <ol className="mt-3 list-decimal space-y-2 pl-4 text-xs text-slate-600">
                        <li>Tambahkan mesin di sini dengan <b>serial number</b> persis seperti di mesin (Menu → Info Sistem).</li>
                        <li>
                            Di mesin: Menu → Komunikasi → <b>Cloud Server / ADMS</b>. Alamat server: <code className="rounded bg-slate-100 px-1">{server?.host}</code>, port{' '}
                            <code className="rounded bg-slate-100 px-1">80</code> (atau 443 bila mesin mendukung HTTPS), domain name ON.
                        </li>
                        <li>Pastikan jam & tanggal mesin benar. Setiap karyawan memakai PIN = NRP-nya (atau PIN Absen di data karyawan).</li>
                        <li>Status berubah <b>Online</b> dalam ±1 menit, dan CI/CO langsung masuk ke Resume Absensi.</li>
                    </ol>
                    <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-[11px] text-amber-700">
                        Mesin yang mengirim data sebelum didaftarkan muncul sebagai <b>baru terdeteksi</b>; datanya baru diterima setelah Anda klik Terima.
                    </p>
                    <p className="mt-2 text-[11px] text-slate-400">Mesin tanpa ADMS: unduh log (USB / aplikasi bawaan) lalu import di tab Log Absen.</p>
                </div>
            </div>
            {editing && <MesinModal device={editing === 'new' ? null : editing} locations={work_locations} onClose={() => setEditing(null)} />}
        </div>
    );
}

function MesinModal({ device, locations, onClose }: { device: MesinRow | null; locations: { id: number; name: string }[]; onClose: () => void }) {
    const form = useForm({
        name: device?.name ?? '',
        serial_number: device?.serial_number ?? '',
        brand: device?.brand ?? '',
        model: device?.model ?? '',
        work_location_id: device?.work_location_id ? String(device.work_location_id) : '',
        status: device && device.status !== 'new' ? device.status : 'active',
        notes: device?.notes ?? '',
    });
    const save = () => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (device) form.put(`${URL}/device/${device.id}`, opts);
        else form.post(`${URL}/device`, opts);
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="mt-1 text-xs text-rose-600">{form.errors[k]}</p>;

    return (
        <Modal
            title={device ? 'Ubah mesin absen' : 'Tambah mesin absen'}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Field label="Nama mesin">
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="mis. Fingerprint Pos Satpam Site" className={inputClass} />
                        {err('name')}
                    </Field>
                </div>
                <Field label="Serial number">
                    <input value={form.data.serial_number} onChange={(e) => form.setData('serial_number', e.target.value)} className={`${inputClass} font-mono`} />
                    {err('serial_number')}
                </Field>
                <Field label="Lokasi kerja">
                    <select value={form.data.work_location_id} onChange={(e) => form.setData('work_location_id', e.target.value)} className={inputClass}>
                        <option value="">—</option>
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Merek">
                    <input value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} placeholder="ZKTeco / Solution / Fingerspot" className={inputClass} />
                </Field>
                <Field label="Model">
                    <input value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={inputClass} />
                </Field>
                <Field label="Status">
                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'active' | 'inactive')} className={inputClass}>
                        <option value="active">Aktif — data diterima</option>
                        <option value="inactive">Nonaktif — data ditahan</option>
                    </select>
                </Field>
                <Field label="Catatan">
                    <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={inputClass} />
                </Field>
            </div>
        </Modal>
    );
}
