import { useMemo, useState, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import {
    ArrowUpRight,
    BookOpen,
    Briefcase,
    Cake,
    CalendarClock,
    ChevronDown,
    ClipboardCheck,
    Database,
    FlaskConical,
    HandCoins,
    Loader2,
    MapPin,
    Palmtree,
    Plane,
    RefreshCw,
    TrendingUp,
    UserMinus,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { CountUp, Donut, Gauge, HBars, OnsiteChart, VBars, compactRupiah, num, rupiah, type OnsiteDay, type Slice } from '@/components/dashboard/Charts';
import { GUIDE_MODULES, type GuideFeature } from '@/constants/guide';

interface Props {
    today: string;
    location: number | null;
    locations: { id: number; name: string }[];
    people: {
        active: number;
        terminated_this_year: number;
        new_90_days: number;
        contracts_30_days: number;
        per_location: Slice[];
        gender: Slice[];
        employment_status: Slice[];
        employee_class: Slice[];
        age: Slice[];
        years_of_service: Slice[];
    };
    onsite: OnsiteDay[];
    time_clock: { per_type: (Slice & { key: string })[]; rate: number | null };
    submission: { key: string; label: string; value: number; href: string; menu: string }[];
    travel: {
        business_trips_today: number;
        leaves_today: number;
        upcoming: { name: string; type: string; leave_category: string | null; start_date: string; end_date: string; destination_city: string | null }[] | null;
    };
    compensation: { total: number; quantity: number; per_month: Slice[]; per_type: Slice[] };
    recruitment: { stages: (Slice & { color: string })[]; new_applicants: number; applicants_30_days: number; open_postings: number };
    workforce_planning: { label: string; plan: number; actual: number; gap: number }[];
    birthdays: { name: string; position: string | null; date: string; age: number; days: number }[] | null;
}

const KOMPOSISI = [
    { key: 'per_location', label: 'Lokasi' },
    { key: 'employment_status', label: 'Status' },
    { key: 'employee_class', label: 'Klasifikasi' },
    { key: 'gender', label: 'Gender' },
    { key: 'age', label: 'Usia' },
    { key: 'years_of_service', label: 'Masa kerja' },
] as const;

const KEHADIRAN_COLOR: Record<string, string> = {
    work: '#15803d',
    business_trip: '#0d9488',
    leave: '#86efac',
    off: '#d9f99d',
    sick: '#f59e0b',
    permission: '#fbbf24',
    absent: '#ef4444',
};

const dmy = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });

function Card({ title, icon, action, children, className = '' }: { title: string; icon?: ReactNode; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <motion.section
            initial={{ opacity: 0, y: 14 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-40px' }}
            transition={{ duration: 0.35 }}
            className={`rounded-3xl border border-slate-100 bg-white p-5 shadow-sm ${className}`}
        >
            <div className="mb-4 flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-black text-slate-800">
                    {icon && <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-brand-600">{icon}</span>}
                    {title}
                </h2>
                {action}
            </div>
            {children}
        </motion.section>
    );
}

function OpenLink({ href, show = true }: { href: string; show?: boolean }) {
    if (!show) return null;
    return (
        <Link href={href} className="flex items-center gap-0.5 text-xs font-bold text-brand-600 hover:text-brand-800">
            Buka <ArrowUpRight size={14} />
        </Link>
    );
}

export default function Dashboard(props: Props) {
    const { auth, workspace } = usePage().props;
    const can = (menu?: string) => !menu || (menu === 'settings' ? auth.can.manageUsers : auth.menus?.[menu] !== undefined);
    const [komposisi, setKomposisi] = useState<(typeof KOMPOSISI)[number]['key']>('per_location');
    const [openModule, setOpenModule] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [switchTo, setSwitchTo] = useState<'demo' | 'blank' | null>(null);

    const hour = new Date().getHours();
    const sapa = hour < 11 ? 'Selamat pagi' : hour < 15 ? 'Selamat siang' : hour < 18 ? 'Selamat sore' : 'Selamat malam';
    const todayRow = props.onsite.find((d) => d.date === props.today);
    const onsitePct = todayRow && todayRow.total ? Math.round((todayRow.onsite / todayRow.total) * 100) : null;
    const pengajuanTotal = props.submission.reduce((s, p) => s + p.value, 0);
    const pilihLokasi = (id: number | null) =>
        router.get('/dashboard', id ? { location: id } : {}, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    const kpis = [
        { label: 'Karyawan aktif', value: props.people.active, icon: Users, href: '/core-hr/employees', menu: 'employees', note: `${num(props.people.per_location.length)} lokasi` },
        { label: 'Onsite hari ini', value: todayRow?.onsite ?? 0, icon: MapPin, href: '/time/attendance', menu: 'attendance-records', note: onsitePct !== null ? `${onsitePct}% dari roll` : '–' },
        { label: 'Pengajuan menunggu', value: pengajuanTotal, icon: ClipboardCheck, href: '#submission', note: 'dari portal karyawan' },
        { label: 'Karyawan baru', value: props.people.new_90_days, icon: UserPlus, href: '/core-hr/employees', menu: 'employees', note: '90 hari terakhir' },
        { label: 'PKWT berakhir', value: props.people.contracts_30_days, icon: CalendarClock, href: '/core-hr/employees', menu: 'employees', note: '30 hari ke depan' },
        { label: 'Keluar tahun ini', value: props.people.terminated_this_year, icon: UserMinus, href: '/core-hr/employees/termination', menu: 'employees', note: String(new Date(props.today).getFullYear()) },
    ];

    const komposisiData = props.people[komposisi];
    const time_clock = useMemo(
        () => props.time_clock.per_type.map((k) => ({ ...k, color: KEHADIRAN_COLOR[k.key] ?? '#94a3b8' })).sort((a, b) => b.value - a.value),
        [props.time_clock],
    );

    return (
        <>
            <Head title="Dashboard" />
            <header className="flex h-16 shrink-0 items-center justify-between gap-3 border-b-[3px] border-accent-400 bg-white px-6 lg:px-8">
                <h1 className="text-xl font-bold tracking-tight text-slate-800">Dashboard</h1>
                {loading && <Loader2 size={18} className="animate-spin text-brand-600" />}
            </header>

            <div className="flex-1 overflow-y-auto bg-gradient-to-b from-brand-50/60 via-slate-50 to-slate-50">
                <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
                    {/* Hero */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-6 text-white shadow-xl shadow-brand-900/15 sm:p-8"
                    >
                        <div className="pointer-events-none absolute -top-20 -right-16 h-64 w-64 rounded-full bg-accent-400/20 blur-2xl" />
                        <div className="pointer-events-none absolute -bottom-24 left-1/3 h-56 w-56 rounded-full bg-white/10 blur-2xl" />
                        <div className="relative flex flex-wrap items-end justify-between gap-6">
                            <div>
                                <p className="text-sm font-semibold text-brand-100">
                                    {new Date(`${props.today}T00:00:00`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
                                </p>
                                <h2 className="mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                                    {sapa}, {auth.user?.name.split(' ')[0]} 👋
                                </h2>
                                <p className="mt-2 max-w-xl text-sm text-brand-100">
                                    Ringkasan seluruh modul Eolas HRIS{props.location ? ` untuk ${props.locations.find((l) => l.id === props.location)?.name}` : ''}. Arahkan
                                    kursor ke grafik untuk detail, klik kartu untuk membuka modulnya.
                                </p>
                            </div>
                            <div className="flex gap-3">
                                <div className="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur">
                                    <p className="text-[11px] font-semibold text-brand-100">Dinas hari ini</p>
                                    <p className="flex items-center gap-1.5 text-xl font-black">
                                        <Plane size={16} /> {props.travel.business_trips_today}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur">
                                    <p className="text-[11px] font-semibold text-brand-100">Cuti hari ini</p>
                                    <p className="flex items-center gap-1.5 text-xl font-black">
                                        <Palmtree size={16} /> {props.travel.leaves_today}
                                    </p>
                                </div>
                            </div>
                        </div>
                        {props.locations.length > 1 && (
                            <div className="relative mt-6 flex gap-2 overflow-x-auto pb-1">
                                {[{ id: null as number | null, name: 'Semua lokasi' }, ...props.locations].map((l) => (
                                    <button
                                        key={l.id ?? 'all'}
                                        onClick={() => pilihLokasi(l.id)}
                                        className={`shrink-0 rounded-full px-4 py-1.5 text-xs font-bold transition ${
                                            props.location === l.id ? 'bg-white text-brand-800 shadow' : 'bg-white/10 text-white hover:bg-white/20'
                                        }`}
                                    >
                                        {l.name}
                                    </button>
                                ))}
                            </div>
                        )}
                    </motion.div>

                    {/* Workspace */}
                    {workspace && (
                        <div id="workspace" className="flex flex-wrap items-center gap-4 rounded-3xl border border-brand-100 bg-white p-5 shadow-sm">
                            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-600 text-white">
                                <FlaskConical size={20} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-black text-slate-800">
                                    Workspace demo {workspace.company_name}{' '}
                                    <span className="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-bold text-brand-700">{workspace.source_label}</span>
                                </p>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Kode workspace <b className="font-mono text-brand-700">{workspace.code}</b> (untuk login karyawan dengan NRP)
                                    {workspace.days_left !== null && (
                                        <>
                                            {' · '}masa demo tersisa <b className="text-slate-700">{workspace.days_left} hari</b>
                                        </>
                                    )}
                                </p>
                            </div>
                            {auth.can.manageUsers && (
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        onClick={() => setSwitchTo('demo')}
                                        className="flex items-center gap-1.5 rounded-xl border border-brand-200 px-3 py-2 text-xs font-bold text-brand-700 hover:bg-brand-50"
                                    >
                                        <RefreshCw size={14} /> {workspace.data_source === 'demo' ? 'Reset data demo' : 'Pakai data demo sistem'}
                                    </button>
                                    <button
                                        onClick={() => setSwitchTo('blank')}
                                        className="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50"
                                    >
                                        <Database size={14} /> {workspace.data_source === 'blank' ? 'Kosongkan lagi' : 'Pakai data sendiri'}
                                    </button>
                                </div>
                            )}
                        </div>
                    )}

                    {workspace?.data_source === 'blank' && props.people.active === 0 && (
                        <div className="rounded-3xl border-2 border-dashed border-brand-200 bg-white p-6">
                            <h3 className="font-black text-slate-800">Mulai dengan data Anda sendiri</h3>
                            <ol className="mt-3 grid gap-3 text-sm text-slate-600 md:grid-cols-4">
                                {[
                                    ['Pengaturan Core HR', 'Buat lokasi kerja, divisi, departemen, section, dan SubGroup.', '/core-hr/settings'],
                                    ['Struktur Organisasi', 'Susun plan jabatan per lokasi kerja.', '/core-hr/org-chart/plan'],
                                    ['Man Power', 'Tambah karyawan satu per satu atau import dari template Excel.', '/core-hr/employees'],
                                    ['Master Comben & Attendance', 'Atur plafon, golongan, pola roster, dan hari libur.', '/compensation/settings'],
                                ].map(([t, d, href], i) => (
                                    <li key={t}>
                                        <Link href={href} className="block h-full rounded-2xl bg-brand-50/60 p-4 hover:bg-brand-50">
                                            <span className="text-xs font-black text-brand-600">LANGKAH {i + 1}</span>
                                            <span className="mt-1 block font-bold text-slate-800">{t}</span>
                                            <span className="mt-1 block text-xs text-slate-500">{d}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}

                    {/* KPIs */}
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                        {kpis.map((k, i) => {
                            const Icon = k.icon;
                            const body = (
                                <>
                                    <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                                        <Icon size={18} />
                                    </span>
                                    <CountUp value={k.value} className="mt-3 block text-3xl font-black tracking-tight text-slate-800" />
                                    <p className="text-xs font-bold text-slate-600">{k.label}</p>
                                    <p className="mt-0.5 text-[11px] text-slate-400">{k.note}</p>
                                </>
                            );
                            const cls = 'group block rounded-3xl border border-slate-100 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md';
                            return (
                                <motion.div key={k.label} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.05 * i }}>
                                    {k.href.startsWith('#') ? (
                                        <a href={k.href} className={cls}>
                                            {body}
                                        </a>
                                    ) : can(k.menu) ? (
                                        <Link href={k.href} className={cls}>
                                            {body}
                                        </Link>
                                    ) : (
                                        <div className={cls}>{body}</div>
                                    )}
                                </motion.div>
                            );
                        })}
                    </div>

                    {/* Onsite + kehadiran */}
                    <div className="grid gap-6 xl:grid-cols-3">
                        <Card title="Man Onsite (20 hari lalu – 9 hari ke depan)" icon={<MapPin size={15} />} className="xl:col-span-2" action={<OpenLink href="/time/shift-schedule" show={can('schedule')} />}>
                            {props.onsite.length ? <OnsiteChart days={props.onsite} today={props.today} /> : <p className="text-sm text-slate-400">Belum ada data roster / absensi.</p>}
                        </Card>
                        <Card title="Kehadiran 30 hari" icon={<TrendingUp size={15} />} action={<OpenLink href="/time/attendance" show={can('attendance')} />}>
                            <div className="flex flex-col items-center gap-4">
                                <Gauge value={props.time_clock.rate} label="tingkat hadir" />
                                <div className="w-full">
                                    <HBars data={time_clock} />
                                </div>
                            </div>
                        </Card>
                    </div>

                    {/* Komposisi + pengajuan */}
                    <div className="grid gap-6 xl:grid-cols-3">
                        <Card title="Komposisi karyawan aktif" icon={<Users size={15} />} className="xl:col-span-2">
                            <div className="mb-5 flex flex-wrap gap-1.5">
                                {KOMPOSISI.map((k) => (
                                    <button
                                        key={k.key}
                                        onClick={() => setKomposisi(k.key)}
                                        className={`rounded-full px-3 py-1 text-xs font-bold transition ${
                                            komposisi === k.key ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500 hover:bg-brand-50 hover:text-brand-700'
                                        }`}
                                    >
                                        {k.label}
                                    </button>
                                ))}
                            </div>
                            <AnimatePresence mode="wait">
                                <motion.div key={komposisi} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }}>
                                    {komposisi === 'age' || komposisi === 'years_of_service' ? (
                                        <VBars data={komposisiData} height={180} />
                                    ) : (
                                        <Donut data={komposisiData} center="Karyawan" />
                                    )}
                                </motion.div>
                            </AnimatePresence>
                        </Card>
                        <div id="submission" className="scroll-mt-6">
                            <Card title="Pengajuan menunggu verifikasi" icon={<ClipboardCheck size={15} />} className="h-full">
                                <ul className="space-y-2">
                                    {props.submission.map((p) => {
                                        const row = (
                                            <>
                                                <span className="flex-1 text-sm font-semibold text-slate-600">{p.label}</span>
                                                <span
                                                    className={`min-w-9 rounded-full px-2.5 py-0.5 text-center text-sm font-black ${
                                                        p.value > 0 ? 'bg-accent-400 text-brand-950' : 'bg-slate-100 text-slate-400'
                                                    }`}
                                                >
                                                    {p.value}
                                                </span>
                                                {can(p.menu) && <ArrowUpRight size={15} className="text-slate-300 group-hover:text-brand-600" />}
                                            </>
                                        );
                                        return (
                                            <li key={p.key}>
                                                {can(p.menu) ? (
                                                    <Link href={p.href} className="group flex items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 hover:border-brand-200 hover:bg-brand-50/50">
                                                        {row}
                                                    </Link>
                                                ) : (
                                                    <div className="flex items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5">{row}</div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                                <p className="mt-4 rounded-2xl bg-brand-50/60 p-3 text-xs text-slate-500">
                                    Pengajuan dikirim karyawan dari <b>Portal Karyawan</b> dan juga muncul di lonceng notifikasi.
                                </p>
                            </Card>
                        </div>
                    </div>

                    {/* Comben + recruitment */}
                    <div className="grid gap-6 xl:grid-cols-2">
                        <Card title={`Deklarasi Comben ${new Date(props.today).getFullYear()}`} icon={<HandCoins size={15} />} action={<OpenLink href="/compensation/benefit-balances" show={can('benefit-balances')} />}>
                            <div className="mb-4 flex flex-wrap gap-6">
                                <div>
                                    <p className="text-[11px] font-semibold text-slate-400">Total disetujui</p>
                                    <CountUp value={props.compensation.total} format={compactRupiah} className="text-2xl font-black text-brand-700" />
                                </div>
                                <div>
                                    <p className="text-[11px] font-semibold text-slate-400">Jumlah deklarasi</p>
                                    <p className="text-2xl font-black text-slate-800">{num(props.compensation.quantity)}</p>
                                </div>
                            </div>
                            <VBars data={props.compensation.per_month} format={rupiah} highlight={new Date(props.today).getMonth()} />
                            <div className="mt-5 border-t border-slate-100 pt-4">
                                <HBars data={props.compensation.per_type} format={compactRupiah} />
                            </div>
                        </Card>
                        <Card title="Pipeline recruitment" icon={<Briefcase size={15} />} action={<OpenLink href="/recruitment/pipeline" show={can('recruitment')} />}>
                            <div className="mb-5 grid grid-cols-3 gap-3">
                                {[
                                    ['Lowongan dibuka', props.recruitment.open_postings],
                                    ['Pelamar baru', props.recruitment.new_applicants],
                                    ['Pelamar 30 hari', props.recruitment.applicants_30_days],
                                ].map(([l, v]) => (
                                    <div key={l} className="rounded-2xl bg-slate-50 p-3">
                                        <p className="text-[11px] font-semibold text-slate-400">{l}</p>
                                        <p className="text-xl font-black text-slate-800">{num(v as number)}</p>
                                    </div>
                                ))}
                            </div>
                            <HBars data={props.recruitment.stages} />
                        </Card>
                    </div>

                    {/* MPP + agenda */}
                    <div className="grid gap-6 xl:grid-cols-3">
                        <Card title="Man Power Planning: plan vs actual" icon={<TrendingUp size={15} />} className="xl:col-span-2" action={<OpenLink href="/core-hr/workforce-planning" show={can('workforce_planning')} />}>
                            {props.workforce_planning.length === 0 ? (
                                <p className="text-sm text-slate-400">Belum ada rencana MPP.</p>
                            ) : (
                                <div className="space-y-4">
                                    {props.workforce_planning.map((m, i) => {
                                        const max = Math.max(1, ...props.workforce_planning.flatMap((x) => [x.plan, x.actual]));
                                        return (
                                            <div key={m.label}>
                                                <div className="mb-1.5 flex items-center justify-between text-xs">
                                                    <span className="font-bold text-slate-700">{m.label}</span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 font-black ${m.gap < 0 ? 'bg-amber-100 text-amber-700' : 'bg-brand-50 text-brand-700'}`}
                                                    >
                                                        Gap {m.gap > 0 ? `+${m.gap}` : m.gap}
                                                    </span>
                                                </div>
                                                {(['plan', 'actual'] as const).map((k) => (
                                                    <div key={k} className="mb-1 flex items-center gap-2">
                                                        <span className="w-12 text-[10px] font-bold text-slate-400 uppercase">{k}</span>
                                                        <div className="h-3 flex-1 overflow-hidden rounded-full bg-slate-100">
                                                            <motion.div
                                                                className={`h-full rounded-full ${k === 'plan' ? 'bg-brand-200' : 'bg-brand-600'}`}
                                                                initial={{ width: 0 }}
                                                                animate={{ width: `${(m[k] / max) * 100}%` }}
                                                                transition={{ duration: 0.7, delay: i * 0.06 }}
                                                            />
                                                        </div>
                                                        <span className="w-8 text-right text-xs font-black text-slate-700">{m[k]}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })}
                                    <p className="text-[11px] text-slate-400">Gap = Actual − (Plan + promosi + rotasi + demosi + keluar + pensiun + sakit). Minus berarti masih butuh orang.</p>
                                </div>
                            )}
                        </Card>
                        <div className="space-y-6">
                            {props.travel.upcoming && (
                                <Card title="Dinas & cuti 14 hari ke depan" icon={<Plane size={15} />} action={<OpenLink href="/time/leave-travel" show={can('leave-travel')} />}>
                                    <ul className="space-y-2">
                                        {props.travel.upcoming.map((t) => (
                                            <li key={`${t.name}${t.start_date}`} className="flex items-center gap-3">
                                                <span
                                                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${t.type === 'business_trip' ? 'bg-teal-50 text-teal-600' : 'bg-brand-50 text-brand-600'}`}
                                                >
                                                    {t.type === 'business_trip' ? <Plane size={15} /> : <Palmtree size={15} />}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-bold text-slate-700">{t.name}</p>
                                                    <p className="truncate text-[11px] text-slate-400">
                                                        {t.type === 'business_trip' ? 'Dinas' : `Cuti ${t.leave_category ?? ''}`} · {t.destination_city ?? '-'}
                                                    </p>
                                                </div>
                                                <span className="text-[11px] font-bold whitespace-nowrap text-slate-500">
                                                    {dmy(t.start_date)} – {dmy(t.end_date)}
                                                </span>
                                            </li>
                                        ))}
                                        {props.travel.upcoming.length === 0 && <li className="text-sm text-slate-400">Tidak ada jadwal.</li>}
                                    </ul>
                                </Card>
                            )}
                            {props.birthdays && (
                                <Card title="Ulang tahun 30 hari ke depan" icon={<Cake size={15} />}>
                                    <ul className="space-y-2">
                                        {props.birthdays.map((b) => (
                                            <li key={b.name} className="flex items-center gap-3">
                                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-accent-100 text-sm font-black text-brand-800">
                                                    {b.name.charAt(0)}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-bold text-slate-700">{b.name}</p>
                                                    <p className="truncate text-[11px] text-slate-400">{b.position ?? '-'}</p>
                                                </div>
                                                <span className="text-right text-[11px] font-bold text-slate-500">
                                                    {b.days === 0 ? '🎉 Hari ini' : dmy(b.date)}
                                                    <span className="block font-normal text-slate-400">{b.age} th</span>
                                                </span>
                                            </li>
                                        ))}
                                        {props.birthdays.length === 0 && <li className="text-sm text-slate-400">Tidak ada.</li>}
                                    </ul>
                                </Card>
                            )}
                        </div>
                    </div>

                    {/* Module guide */}
                    <section className="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-6">
                        <div className="mb-5 flex flex-wrap items-end justify-between gap-2">
                            <div>
                                <h2 className="flex items-center gap-2 text-lg font-black text-slate-800">
                                    <BookOpen size={20} className="text-brand-600" /> Panduan modul & fitur
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">Klik modul untuk melihat fiturnya, lalu buka halamannya langsung.</p>
                            </div>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {GUIDE_MODULES.map((m) => {
                                const Icon = m.icon;
                                const open = openModule === m.id;
                                return (
                                    <div key={m.id} className={`rounded-2xl border transition ${open ? 'border-brand-300 bg-brand-50/40 md:col-span-2 xl:col-span-3' : 'border-slate-100 hover:border-brand-200'}`}>
                                        <button onClick={() => setOpenModule(open ? null : m.id)} className="flex w-full items-start gap-3 p-4 text-left" aria-expanded={open}>
                                            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${m.tone}`}>
                                                <Icon size={19} />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block font-black text-slate-800">{m.name}</span>
                                                <span className="block text-xs font-semibold text-brand-600">{m.tagline}</span>
                                                {!open && <span className="mt-1 line-clamp-2 block text-xs text-slate-500">{m.desc}</span>}
                                            </span>
                                            <ChevronDown size={18} className={`mt-1 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
                                        </button>
                                        <AnimatePresence>
                                            {open && (
                                                <motion.div initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }} className="overflow-hidden">
                                                    <div className="px-4 pb-4">
                                                        <p className="mb-3 text-sm text-slate-600">{m.desc}</p>
                                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                                            {m.features.map((f: GuideFeature) => (
                                                                <div key={f.name} className="flex flex-col rounded-xl border border-slate-100 bg-white p-3">
                                                                    <p className="text-sm font-bold text-slate-800">{f.name}</p>
                                                                    <p className="mt-1 flex-1 text-xs leading-relaxed text-slate-500">{f.desc}</p>
                                                                    {f.href && can(f.menu) && (
                                                                        <Link href={f.href} className="mt-2 flex items-center gap-0.5 text-xs font-bold text-brand-600 hover:text-brand-800">
                                                                            Buka halaman <ArrowUpRight size={13} />
                                                                        </Link>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                </div>
            </div>

            {switchTo && workspace && <SwitchDataModal to={switchTo} onClose={() => setSwitchTo(null)} />}
        </>
    );
}

/** Confirms rebuilding the demo workspace with the chosen data. */
function SwitchDataModal({ to, onClose }: { to: 'demo' | 'blank'; onClose: () => void }) {
    const [confirmation, setKonfirmasi] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const submit = () =>
        router.post(
            '/workspace/data',
            { data_source: to, confirmation },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (e) => setError(Object.values(e)[0] ?? 'Gagal.'),
            },
        );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" onClick={onClose}>
            <motion.div
                initial={{ opacity: 0, scale: 0.95 }}
                animate={{ opacity: 1, scale: 1 }}
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl"
            >
                <div className="flex items-start justify-between">
                    <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                        {to === 'demo' ? <FlaskConical size={20} /> : <Database size={20} />}
                    </span>
                    <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100" aria-label="Tutup">
                        <X size={18} />
                    </button>
                </div>
                <h3 className="mt-4 text-lg font-black text-slate-800">{to === 'demo' ? 'Gunakan data demo sistem' : 'Gunakan data sendiri (kosong)'}</h3>
                <p className="mt-2 text-sm text-slate-500">
                    Workspace akan <b>disiapkan ulang</b>. Semua data yang ada sekarang akan dihapus dan diganti{' '}
                    {to === 'demo' ? 'dengan data contoh lengkap' : 'dengan workspace kosong (hanya master dasar)'}. Akun Super Admin Anda tetap sama.
                    Anda akan keluar dan bisa masuk lagi setelah selesai.
                </p>
                <label className="mt-4 block text-xs font-bold text-slate-600">
                    Ketik <span className="font-mono text-rose-600">SETUJU</span> untuk melanjutkan
                    <input
                        value={confirmation}
                        onChange={(e) => setKonfirmasi(e.target.value)}
                        className="mt-1.5 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:ring-4 focus:ring-brand-100"
                        autoFocus
                    />
                </label>
                {error && <p className="mt-2 text-xs font-semibold text-rose-600">{error}</p>}
                <div className="mt-5 flex justify-end gap-2">
                    <button onClick={onClose} className="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-100">
                        Batal
                    </button>
                    <button
                        onClick={submit}
                        disabled={confirmation !== 'SETUJU' || busy}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        {busy && <Loader2 size={15} className="animate-spin" />} Siapkan ulang
                    </button>
                </div>
            </motion.div>
        </div>
    );
}
