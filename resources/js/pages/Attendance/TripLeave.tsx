import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Briefcase, MapPin, Palmtree, Plane, Users } from 'lucide-react';
import { OnsiteChart, onsitePct, type OnsiteDay } from '@/components/attendance/OnsiteChart';
import { TripForm, type TripPerson } from '@/components/attendance/TripForm';
import type { Bandara } from '@/components/attendance/BandaraPicker';
import type { LampiranLimits } from '@/lib/lampiran';
import {
    AttendanceHeader,
    JenisBadge,
    NO_SUB_LOCATION,
    PeriodPicker,
    SubLocationSelect,
    smallInput,
    subLocationFor,
    type SubLocationOption,
} from '@/components/attendance/ui';
import type { PeriodInfo, PeriodOption } from '@/lib/attendance';
import { fmtDate } from '@/lib/format';

interface Away {
    id: number;
    jenis: 'dinas' | 'cuti';
    nrp: string;
    name: string;
    jabatan: string | null;
    work_location: string | null;
    kota_tujuan: string;
    tanggal_awal: string;
    tanggal_akhir: string;
    sisa_hari: number;
}

interface Props {
    period: PeriodInfo;
    periods: PeriodOption[];
    lokasi: number | null;
    /** Sub Lokasi filter: '' (all), 'none', or an id. */
    sub: string;
    locations: { id: number; name: string }[];
    sub_locations: SubLocationOption[];
    daily: OnsiteDay[];
    today: OnsiteDay;
    away: Away[];
    people: TripPerson[];
    cities: string[];
    bandara: Bandara[];
    lampiran_limits: LampiranLimits;
}

const percent = (n: number, total: number) => (total ? `${((n / total) * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 })}%` : '-');

export default function TripLeave({
    period,
    periods,
    lokasi,
    sub,
    locations,
    sub_locations,
    daily,
    today,
    away,
    people,
    cities,
    bandara,
    lampiran_limits,
}: Props) {
    const visit = (params: { periode?: string; lokasi?: number | null; sub?: string }) => {
        const query: Record<string, string> = { periode: params.periode ?? period.value };
        const loc = params.lokasi === undefined ? lokasi : params.lokasi;
        const subLocation = params.sub ?? subLocationFor(sub, loc ? String(loc) : '', sub_locations);
        if (loc) query.lokasi = String(loc);
        if (subLocation) query.sub = subLocation;
        router.get('/attendance/trip-leave', query, {
            preserveScroll: true,
            preserveState: true,
            only: ['period', 'lokasi', 'sub', 'daily', 'today', 'away'],
        });
    };

    const past = daily.filter((d) => d.date <= period.today && d.total > 0);
    const average = past.length ? past.reduce((s, d) => s + onsitePct(d), 0) / past.length : null;
    const subName = sub === NO_SUB_LOCATION ? 'tanpa sub lokasi' : sub_locations.find((s) => String(s.id) === sub)?.name;
    const locationName = [locations.find((l) => l.id === lokasi)?.name ?? 'Semua lokasi', subName].filter(Boolean).join(' · ');

    const tiles = [
        { label: 'Man Power', value: today.total, sub: locationName, icon: Users, tone: 'text-slate-700' },
        { label: 'Onsite', value: today.onsite, sub: percent(today.onsite, today.total), icon: MapPin, tone: 'text-brand-700' },
        { label: 'Dinas', value: today.dinas, sub: percent(today.dinas, today.total), icon: Briefcase, tone: 'text-sky-700' },
        { label: 'Cuti', value: today.cuti, sub: percent(today.cuti, today.total), icon: Palmtree, tone: 'text-violet-700' },
        {
            label: 'Off / Tidak hadir',
            value: today.off + today.absen,
            sub: `${today.off} off · ${today.absen} sakit/izin/mangkir`,
            icon: Users,
            tone: 'text-slate-600',
        },
        { label: 'Belum ada data', value: today.kosong, sub: 'Absensi & Roster kosong', icon: Users, tone: 'text-amber-700' },
    ];

    return (
        <>
            <Head title="Trip & Leave" />
            <AttendanceHeader title="Trip & Leave" subtitle="Perjalanan dinas dan cuti karyawan — otomatis mengisi Absensi">
                <Link
                    href="/attendance/pengajuan-dinas"
                    className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-brand-600 hover:bg-brand-50"
                >
                    Pengajuan Dinas
                    <ArrowRight size={15} />
                </Link>
            </AttendanceHeader>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50 p-6">
                {/* Input */}
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800">
                        <Plane size={16} className="text-brand-500" />
                        Input Perjalanan Dinas / Cuti
                    </h2>
                    <TripForm people={people} cities={cities} bandara={bandara} limits={lampiran_limits} />
                </section>

                {/* Today */}
                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-bold text-slate-800">
                            Status hari ini <span className="font-normal text-slate-400">· {fmtDate(period.today)}</span>
                        </h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                value={lokasi ?? ''}
                                onChange={(e) => visit({ lokasi: e.target.value ? Number(e.target.value) : null })}
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
                                    lokasi={lokasi}
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
                                Onsite = hadir di lokasi menurut Absensi; Dinas &amp; Cuti dari Trip &amp; Leave. Hari tanpa Absensi memakai Roster.
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
                            <PeriodPicker period={period} periods={periods} onChange={(v) => visit({ periode: v })} />
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
                        <Link href="/attendance/pengajuan-dinas" className="text-xs font-bold text-brand-600 hover:underline">
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
                                                {a.nrp} · {a.jabatan ?? '-'}
                                            </p>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <JenisBadge jenis={a.jenis} />
                                        </td>
                                        <td className="px-3 py-2.5 text-slate-600">{a.kota_tujuan}</td>
                                        <td className="px-3 py-2.5 text-slate-600 tabular-nums">
                                            {fmtDate(a.tanggal_awal)} – {fmtDate(a.tanggal_akhir)}
                                        </td>
                                        <td className="px-5 py-2.5 text-right text-xs text-slate-500">
                                            {a.sisa_hari === 0 ? 'Hari terakhir' : `${a.sisa_hari} hari lagi`}
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
