import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Briefcase, Download, MapPin, Palmtree, Plane, Users } from 'lucide-react';
import { OnsiteChart, onsitePct, type OnsiteDay } from '@/components/time/OnsiteChart';
import { LeaveTravelForm, type TripPerson } from '@/components/time/LeaveTravelForm';
import type { Airport } from '@/components/time/AirportPicker';
import type { LampiranLimits } from '@/lib/attachment';
import {
    AttendanceHeader,
    TypeBadge,
    Modal,
    NO_SUB_LOCATION,
    PeriodPicker,
    SubLocationSelect,
    ghostButton,
    primaryButton,
    secondaryButton,
    smallInput,
    subLocationFor,
    type SubLocationOption,
} from '@/components/time/ui';
import type { PeriodInfo, PeriodOption } from '@/lib/time';
import { fmtDate } from '@/lib/format';

interface Away {
    id: number;
    type: 'business_trip' | 'leave';
    employee_number: string;
    name: string;
    position: string | null;
    work_location: string | null;
    destination_city: string;
    start_date: string;
    end_date: string;
    days_left: number;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    location: number | null;
    /** Sub Lokasi filter: '' (all), 'none', or an id. */
    sub: string;
    locations: { id: number; name: string }[];
    sub_locations: SubLocationOption[];
    daily: OnsiteDay[];
    today: OnsiteDay;
    away: Away[];
    people: TripPerson[];
    cities: string[];
    airport: Airport[];
    attachment_limits: LampiranLimits;
}

const percent = (n: number, total: number) => (total ? `${((n / total) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : '-');

export default function LeaveTravel({
    period,
    periods,
    location,
    sub,
    locations,
    sub_locations,
    daily,
    today,
    away,
    people,
    cities,
    airport,
    attachment_limits,
}: Props) {
    const visit = (params: { period?: string; location?: number | null; sub?: string }) => {
        const query: Record<string, string> = { period: params.period ?? period.value };
        const loc = params.location === undefined ? location : params.location;
        const subLocation = params.sub ?? subLocationFor(sub, loc ? String(loc) : '', sub_locations);
        if (loc) query.location = String(loc);
        if (subLocation) query.sub = subLocation;
        router.get('/time/leave-travel', query, {
            preserveScroll: true,
            preserveState: true,
            only: ['period', 'location', 'sub', 'daily', 'today', 'away'],
        });
    };

    const [exporting, setExporting] = useState(false);

    const past = daily.filter((d) => d.date <= period.today && d.total > 0);
    const average = past.length ? past.reduce((s, d) => s + onsitePct(d), 0) / past.length : null;
    const subName = sub === NO_SUB_LOCATION ? 'tanpa sub lokasi' : sub_locations.find((s) => String(s.id) === sub)?.name;
    const locationName = [locations.find((l) => l.id === location)?.name ?? 'Semua lokasi', subName].filter(Boolean).join(' · ');

    const tiles = [
        { label: 'Man Power', value: today.total, sub: locationName, icon: Users, tone: 'text-slate-700' },
        { label: 'Onsite', value: today.onsite, sub: percent(today.onsite, today.total), icon: MapPin, tone: 'text-brand-700' },
        { label: 'Dinas', value: today.business_trip, sub: percent(today.business_trip, today.total), icon: Briefcase, tone: 'text-sky-700' },
        { label: 'Cuti', value: today.leave, sub: percent(today.leave, today.total), icon: Palmtree, tone: 'text-violet-700' },
        {
            label: 'Off / Tidak hadir',
            value: today.off + today.attendance,
            sub: `${today.off} off · ${today.attendance} sakit/izin/mangkir`,
            icon: Users,
            tone: 'text-slate-600',
        },
        { label: 'Belum ada data', value: today.blank, sub: 'Absensi & Roster kosong', icon: Users, tone: 'text-amber-700' },
    ];

    return (
        <>
            <Head title="Cuti & Dinas" />
            <AttendanceHeader title="Cuti & Dinas" subtitle="Perjalanan dinas dan cuti karyawan — otomatis mengisi Absensi">
                <Link
                    href="/time/travel-requests"
                    className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-brand-600 hover:bg-brand-50"
                >
                    Pengajuan Dinas
                    <ArrowRight size={15} />
                </Link>
                <button onClick={() => setExporting(true)} className={secondaryButton}>
                    <Download size={16} />
                    Export Excel
                </button>
            </AttendanceHeader>
            {exporting && <ExportDialog period={period.value} location={location} sub={sub} scope={locationName} onClose={() => setExporting(false)} />}

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50 p-6">
                {/* Input */}
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800">
                        <Plane size={16} className="text-brand-500" />
                        Input Perjalanan Dinas / Cuti
                    </h2>
                    <LeaveTravelForm people={people} cities={cities} airport={airport} limits={attachment_limits} />
                </section>

                {/* Today */}
                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-bold text-slate-800">
                            Status hari ini <span className="font-normal text-slate-400">· {fmtDate(period.today)}</span>
                        </h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                value={location ?? ''}
                                onChange={(e) => visit({ location: e.target.value ? Number(e.target.value) : null })}
                                className={smallInput}
                                aria-label="Lokasi kerja"
                            >
                                <option value="">Semua lokasi</option>
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                            {sub_locations.length > 0 && (
                                <SubLocationSelect
                                    value={sub}
                                    onChange={(v) => visit({ sub: v })}
                                    subLocations={sub_locations}
                                    locations={locations}
                                    location={location}
                                />
                            )}
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        {tiles.map((t) => (
                            <div key={t.label} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                <p className="flex items-center gap-1.5 text-[11px] font-bold text-slate-400">
                                    <t.icon size={13} />
                                    {t.label}
                                </p>
                                <p className={`mt-1 text-2xl font-black tabular-nums ${t.tone}`}>{t.value}</p>
                                <p className="truncate text-[11px] text-slate-500">{t.sub}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Chart */}
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-bold text-slate-800">% Man Onsite per hari — {locationName}</h2>
                            <p className="text-[11px] text-slate-400">
                                Onsite = hadir di lokasi menurut Absensi; Dinas &amp; Cuti dari Cuti &amp; Dinas. Hari tanpa Absensi memakai Roster.
                                {average !== null && (
                                    <>
                                        {' '}
                                        Rata-rata s/d hari ini:{' '}
                                        <b className="text-slate-600">{average.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%</b>.
                                    </>
                                )}
                            </p>
                        </div>
                        <div className="flex items-center gap-4">
                            <div className="flex items-center gap-3 text-[11px] text-slate-500">
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2.5 w-2.5 rounded-sm bg-brand-600" />
                                    Aktual
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="h-2.5 w-2.5 rounded-sm bg-brand-300" />
                                    Rencana (Roster)
                                </span>
                            </div>
                            <PeriodPicker period={period} periods={periods} onChange={(v) => visit({ period: v })} />
                        </div>
                    </div>
                    <OnsiteChart days={daily} today={period.today} />
                </section>

                {/* Away today */}
                <section className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                        <h2 className="text-sm font-bold text-slate-800">
                            Sedang dinas / cuti hari ini <span className="font-normal text-slate-400">({away.length})</span>
                        </h2>
                        <Link href="/time/travel-requests" className="text-xs font-bold text-brand-600 hover:underline">
                            Lihat semua pengajuan
                        </Link>
                    </div>
                    {away.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-slate-400">Tidak ada karyawan yang sedang dinas atau cuti.</p>
                    ) : (
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-5 py-2.5">Karyawan</th>
                                    <th className="px-3 py-2.5">Jenis</th>
                                    <th className="px-3 py-2.5">Tujuan</th>
                                    <th className="px-3 py-2.5">Tanggal</th>
                                    <th className="px-5 py-2.5 text-right">Kembali</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {away.map((a) => (
                                    <tr key={a.id}>
                                        <td className="px-5 py-2.5">
                                            <p className="font-bold text-slate-700">{a.name}</p>
                                            <p className="text-[11px] text-slate-400">
                                                {a.employee_number} · {a.position ?? '-'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <TypeBadge type={a.type} />
                                        </td>
                                        <td className="px-3 py-2.5 text-slate-600">{a.destination_city}</td>
                                        <td className="px-3 py-2.5 text-slate-600 tabular-nums">
                                            {fmtDate(a.start_date)} – {fmtDate(a.end_date)}
                                        </td>
                                        <td className="px-5 py-2.5 text-right text-xs text-slate-500">
                                            {a.days_left === 0 ? 'Hari terakhir' : `${a.days_left} hari lagi`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>
        </>
    );
}

/** Trips and leave touching the chosen months, for the location picked on the page. */
function ExportDialog({
    period,
    location,
    sub,
    scope,
    onClose,
}: {
    period: string;
    location: number | null;
    sub: string;
    scope: string;
    onClose: () => void;
}) {
    const [from, setDari] = useState(period);
    const [until, setSampai] = useState(period);
    const [type, setJenis] = useState<'all' | 'business_trip' | 'leave'>('all');
    const invalid = !from || !until || until < from;
    const url = () => {
        const q = new URLSearchParams({ from, until, type });
        if (location) q.set('location', String(location));
        if (sub) q.set('sub', sub);
        return `/time/leave-travel/export?${q}`;
    };

    return (
        <Modal
            title="Export Cuti & Dinas"
            subtitle={`Dinas & cuti yang tanggalnya mengenai bulan terpilih · ${scope}`}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <a
                        href={invalid ? undefined : url()}
                        onClick={(e) => (invalid ? e.preventDefault() : setTimeout(onClose, 300))}
                        aria-disabled={invalid}
                        className={`${primaryButton} ${invalid ? 'pointer-events-none opacity-50' : ''}`}
                    >
                        <Download size={16} />
                        Unduh Excel
                    </a>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Dari bulan</span>
                    <input type="month" value={from} onChange={(e) => setDari(e.target.value)} className={`${smallInput} w-full`} />
                </label>
                <label className="block">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Sampai bulan</span>
                    <input type="month" value={until} min={from} onChange={(e) => setSampai(e.target.value)} className={`${smallInput} w-full`} />
                </label>
                <label className="block sm:col-span-2">
                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Jenis</span>
                    <select value={type} onChange={(e) => setJenis(e.target.value as typeof type)} className={`${smallInput} w-full`}>
                        <option value="all">Dinas &amp; cuti</option>
                        <option value="business_trip">Dinas saja</option>
                        <option value="leave">Cuti saja</option>
                    </select>
                </label>
            </div>
            {until < from && <p className="mt-3 text-xs font-bold text-rose-600">Bulan akhir tidak boleh sebelum bulan awal.</p>}
            <p className="mt-3 text-[11px] text-slate-500">
                Paling banyak 36 bulan sekali unduh. Untuk daftar sesuai status / pencarian, pakai Export di Pengajuan Dinas.
            </p>
        </Modal>
    );
}
