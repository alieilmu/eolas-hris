import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Download, Glasses, Search, Stethoscope } from 'lucide-react';
import {
    AttendanceHeader,
    SubLocationSelect,
    matchesSubLocation,
    secondaryButton,
    smallInput,
    subLocationFor,
    type SubLocationOption,
} from '@/components/time/ui';
import { monthShort, rupiah } from '@/lib/compensation';

interface Base {
    id: number;
    employee_number: string;
    name: string;
    position: string | null;
    job_level: string | null;
    department_id: number | null;
    work_location_id: number | null;
    sub_location_id: number | null;
    grade: string | null;
    ptkp_status: string;
    warning: string | null;
    month: Record<string, number>;
}

interface PengobatanRow extends Base {
    layer: string | null;
    family: boolean;
    benefit_limit: number | null;
    used: number;
    /** From the portal, waiting for HR (already held from Sisa). */
    pending: number;
    remaining: number | null;
}

interface KacamataRow extends Base {
    key: string;
    members: string;
    relationship: string;
    frame_limit: number | null;
    lens_limit: number | null;
    frame: number;
    lens: number;
    frame_remaining: number | null;
    lens_remaining: number | null;
    last_frame: string | null;
}

interface Props {
    year: number;
    years: number[];
    months: { value: string; label: string; span: string }[];
    current_month: string;
    medical: PengobatanRow[];
    optical: KacamataRow[];
    locations: { id: number; name: string }[];
    sub_locations: SubLocationOption[];
    departments: { id: number; name: string }[];
}

type Tab = 'medical' | 'optical';

const th = 'border-b border-slate-200 bg-slate-100 px-2.5 py-2.5 text-[10px] font-bold tracking-wide whitespace-nowrap text-slate-500 uppercase';
const td = 'border-b border-slate-100 px-2.5 py-2 whitespace-nowrap';

export default function BenefitBalances({ year, years, months, current_month, medical, optical, locations, sub_locations, departments }: Props) {
    const query = new URLSearchParams(window.location.search);
    const [tab, setTab] = useState<Tab>(query.get('tab') === 'optical' ? 'optical' : 'medical');
    const [location, setLokasi] = useState(query.get('location') ?? '');
    const [sub, setSub] = useState(query.get('sub') ?? '');
    const [department, setDepartemen] = useState(query.get('department') ?? '');
    const [search, setSearch] = useState(query.get('q') ?? '');

    useEffect(() => {
        const url = new URL(window.location.href);
        for (const [k, v] of [
            ['tab', tab === 'optical' ? 'optical' : ''],
            ['location', location],
            ['sub', sub],
            ['department', department],
            ['q', search.trim()],
        ]) {
            if (v) url.searchParams.set(k, v);
            else url.searchParams.delete(k);
        }
        window.history.replaceState(window.history.state, '', url);
    }, [tab, location, sub, department, search]);

    const keep = (r: Base) => {
        const q = search.trim().toLowerCase();
        return (
            (!location || String(r.work_location_id) === location) &&
            matchesSubLocation(sub, r.sub_location_id) &&
            (!department || String(r.department_id) === department) &&
            (!q || r.name.toLowerCase().includes(q) || r.employee_number.includes(q) || (r.position ?? '').toLowerCase().includes(q))
        );
    };
    const rowsP = useMemo(() => medical.filter(keep), [medical, location, sub, department, search]); // eslint-disable-line react-hooks/exhaustive-deps
    const rowsK = useMemo(() => optical.filter(keep), [optical, location, sub, department, search]); // eslint-disable-line react-hooks/exhaustive-deps

    const totals = useMemo(() => {
        const benefit_limit = rowsP.reduce((n, r) => n + (r.benefit_limit ?? 0), 0);
        const used = rowsP.reduce((n, r) => n + r.used, 0);
        const kacamataTotal = rowsK.reduce((n, r) => n + Object.values(r.month).reduce((a, b) => a + b, 0), 0);
        return { benefit_limit, used, remaining: benefit_limit - used, optical: kacamataTotal, warnings: rowsP.filter((r) => r.warning).length };
    }, [rowsP, rowsK]);

    // Another year keeps the tab and the filters (they live in the address).
    const goTahun = (value: string) =>
        router.get('/compensation/benefit-balances', { ...Object.fromEntries(new URL(window.location.href).searchParams), year: value });

    const exportUrl = () => {
        const q = new URLSearchParams({ year: String(year) });
        for (const [k, v] of Object.entries({ location, sub, department, q: search.trim() })) if (v) q.set(k, v);
        return `/compensation/benefit-balances/export?${q}`;
    };

    const monthHead = (m: { value: string; label: string; span: string }) => (
        <th
            key={m.value}
            title={`${m.label}: ${m.span}`}
            className={`${th} text-right ${m.value === current_month ? 'bg-brand-100! text-brand-800!' : ''}`}
        >
            {monthShort(m.value)}
        </th>
    );
    const monthCells = (r: Base) =>
        months.map((m) => (
            <td
                key={m.value}
                className={`${td} text-right tabular-nums ${m.value === current_month ? 'bg-brand-50/50' : ''} ${r.month[m.value] ? 'text-slate-700' : 'text-slate-300'}`}
            >
                {r.month[m.value] ? rupiah(r.month[m.value]) : '·'}
            </td>
        ));

    return (
        <>
            <Head title="Monitoring Plafon" />
            <AttendanceHeader title="Monitoring Plafon" subtitle="Plafon direset tiap awal tahun; bulan buku ditutup tiap tanggal 8">
                <select value={year} onChange={(e) => goTahun(e.target.value)} className={`${smallInput} font-bold`} aria-label="Tahun plafon">
                    {years.map((t) => (
                        <option key={t} value={t}>
                            Tahun {t}
                        </option>
                    ))}
                </select>
                <a href={exportUrl()} className={secondaryButton} title="Unduh Pengobatan & Kacamata sesuai filter">
                    <Download size={16} />
                    Export Excel
                </a>
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-6 py-3">
                    <div className="flex rounded-xl bg-slate-100 p-1">
                        {(
                            [
                                ['medical', 'Pengobatan', Stethoscope],
                                ['optical', 'Kacamata', Glasses],
                            ] as const
                        ).map(([value, label, Icon]) => (
                            <button
                                key={value}
                                onClick={() => setTab(value)}
                                className={`flex items-center gap-1.5 rounded-lg px-3.5 py-1 text-sm font-bold transition-all ${
                                    tab === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                <Icon size={14} />
                                {label}
                            </button>
                        ))}
                    </div>
                    <div className="relative">
                        <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama / NRP / jabatan"
                            className={`${smallInput} w-52 pl-8`}
                        />
                    </div>
                    <select
                        value={location}
                        onChange={(e) => {
                            setLokasi(e.target.value);
                            setSub((s) => subLocationFor(s, e.target.value, sub_locations));
                        }}
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
                        <SubLocationSelect value={sub} onChange={setSub} subLocations={sub_locations} locations={locations} location={location} />
                    )}
                    <select
                        value={department}
                        onChange={(e) => setDepartemen(e.target.value)}
                        className={`${smallInput} max-w-52`}
                        aria-label="Departemen"
                    >
                        <option value="">Semua departemen</option>
                        {departments.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                </div>

                {/* Totals of what is shown */}
                <div className="grid shrink-0 grid-cols-2 gap-3 px-6 pt-4 lg:grid-cols-5">
                    {(tab === 'medical'
                        ? [
                              ['Karyawan', String(rowsP.length), 'text-slate-700'],
                              ['Total plafon', rupiah(totals.benefit_limit), 'text-slate-700'],
                              ['Terpakai', rupiah(totals.used), 'text-brand-700'],
                              ['Sisa', rupiah(totals.remaining), 'text-emerald-700'],
                              ['Perlu dicek', `${totals.warnings} karyawan`, totals.warnings ? 'text-amber-700' : 'text-slate-400'],
                          ]
                        : [
                              ['Anggota keluarga', String(rowsK.length), 'text-slate-700'],
                              ['Klaim kacamata ' + year, rupiah(totals.optical), 'text-brand-700'],
                              ['Klaim bingkai', String(rowsK.filter((r) => r.frame > 0).length) + ' orang', 'text-slate-700'],
                              ['Klaim lensa', String(rowsK.filter((r) => r.lens > 0).length) + ' orang', 'text-slate-700'],
                              ['Perlu dicek', `${new Set(rowsK.filter((r) => r.warning).map((r) => r.id)).size} karyawan`, 'text-amber-700'],
                          ]
                    ).map(([label, value, tone]) => (
                        <div key={label} className="rounded-2xl border border-slate-200 bg-white px-4 py-2.5">
                            <p className="text-[11px] font-bold text-slate-400">{label}</p>
                            <p className={`text-lg font-black tabular-nums ${tone}`}>{value}</p>
                        </div>
                    ))}
                </div>

                <div className="m-6 mt-4 min-h-0 flex-1 overflow-auto rounded-2xl border border-slate-200 bg-white">
                    <table className="border-separate border-spacing-0 text-xs" style={{ minWidth: '100%' }}>
                        <thead className="sticky top-0 z-20">
                            <tr>
                                <th className={`${th} sticky left-0 z-30 w-[84px] min-w-[84px] max-w-[84px] text-left`}>NRP</th>
                                <th className={`${th} sticky left-[84px] z-30 border-r text-left`}>Nama</th>
                                {tab === 'optical' && <th className={`${th} text-left`}>Anggota</th>}
                                <th className={`${th} text-left`}>Jabatan</th>
                                <th className={`${th} text-left`}>SubGroup</th>
                                <th className={th}>Status</th>
                                {tab === 'medical' ? (
                                    <th className={`${th} text-right`}>Plafon</th>
                                ) : (
                                    <>
                                        <th className={`${th} text-right`}>Plafon bingkai</th>
                                        <th className={`${th} text-right`}>Plafon lensa</th>
                                    </>
                                )}
                                {months.map(monthHead)}
                                {tab === 'medical' ? (
                                    <>
                                        <th className={`${th} border-l text-right`}>Terpakai</th>
                                        <th className={`${th} text-right`}>Sisa</th>
                                    </>
                                ) : (
                                    <>
                                        <th className={`${th} border-l text-right`} title="Bingkai: tahun ini dan tahun lalu (2 tahun sekali)">
                                            Bingkai (2 thn)
                                        </th>
                                        <th className={`${th} text-right`}>Sisa bingkai</th>
                                        <th className={`${th} text-right`}>Lensa</th>
                                        <th className={`${th} text-right`}>Sisa lensa</th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {tab === 'medical' &&
                                rowsP.map((r) => (
                                    <tr key={r.id} className="group hover:bg-slate-50">
                                        <td
                                            className={`${td} sticky left-0 z-10 w-[84px] min-w-[84px] max-w-[84px] bg-white font-mono text-[11px] text-slate-500 group-hover:bg-slate-50`}
                                        >
                                            {r.employee_number}
                                        </td>
                                        <td className={`${td} sticky left-[84px] z-10 max-w-52 border-r bg-white group-hover:bg-slate-50`}>
                                            <Name row={r} />
                                        </td>
                                        <td className={`${td} max-w-56 truncate text-slate-500`} title={r.position ?? undefined}>
                                            {r.position ?? '-'}
                                        </td>
                                        <td className={`${td} text-slate-500`}>{r.job_level ?? '-'}</td>
                                        <td className={`${td} text-center`}>
                                            <Status row={r} />
                                        </td>
                                        <td className={`${td} text-right font-bold text-slate-700 tabular-nums`}>
                                            {rupiah(r.benefit_limit)}
                                            {r.layer && <span className="ml-1 text-[10px] font-normal text-slate-400">{r.layer}</span>}
                                        </td>
                                        {monthCells(r)}
                                        <td className={`${td} border-l text-right font-bold text-slate-700 tabular-nums`}>
                                            {r.used ? rupiah(r.used) : '-'}
                                            {r.pending > 0 && (
                                                <span className="block text-[10px] font-bold text-amber-700">+{rupiah(r.pending)} menunggu</span>
                                            )}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            <Sisa remaining={r.remaining} benefit_limit={r.benefit_limit} />
                                        </td>
                                    </tr>
                                ))}
                            {tab === 'optical' &&
                                rowsK.map((r, i) => {
                                    const first = i === 0 || rowsK[i - 1].id !== r.id;
                                    return (
                                        <tr
                                            key={r.key}
                                            className={`group hover:bg-slate-50 ${first ? '[&>td]:border-t [&>td]:border-t-slate-200' : ''}`}
                                        >
                                            <td
                                                className={`${td} sticky left-0 z-10 w-[84px] min-w-[84px] max-w-[84px] bg-white font-mono text-[11px] text-slate-500 group-hover:bg-slate-50`}
                                            >
                                                {first ? r.employee_number : ''}
                                            </td>
                                            <td className={`${td} sticky left-[84px] z-10 max-w-52 border-r bg-white group-hover:bg-slate-50`}>
                                                {first && <Name row={r} />}
                                            </td>
                                            <td className={`${td} max-w-48 truncate`} title={r.members}>
                                                <span className="font-bold text-slate-700">{r.members}</span>
                                                <span className="ml-1 text-[10px] text-slate-400">{r.relationship}</span>
                                            </td>
                                            <td className={`${td} max-w-48 truncate text-slate-500`}>{first ? (r.position ?? '-') : ''}</td>
                                            <td className={`${td} text-slate-500`}>{first ? (r.job_level ?? '-') : ''}</td>
                                            <td className={`${td} text-center`}>{first && <Status row={r} />}</td>
                                            <td className={`${td} text-right text-slate-600 tabular-nums`}>{rupiah(r.frame_limit)}</td>
                                            <td className={`${td} text-right text-slate-600 tabular-nums`}>{rupiah(r.lens_limit)}</td>
                                            {monthCells(r)}
                                            <td
                                                className={`${td} border-l text-right tabular-nums`}
                                                title={r.last_frame ? `Bingkai terakhir: bulan buku ${r.last_frame}` : undefined}
                                            >
                                                {r.frame ? rupiah(r.frame) : '-'}
                                            </td>
                                            <td className={`${td} text-right`}>
                                                <Sisa remaining={r.frame_remaining} benefit_limit={r.frame_limit} />
                                            </td>
                                            <td className={`${td} text-right tabular-nums`}>{r.lens ? rupiah(r.lens) : '-'}</td>
                                            <td className={`${td} text-right`}>
                                                <Sisa remaining={r.lens_remaining} benefit_limit={r.lens_limit} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            {(tab === 'medical' ? rowsP : rowsK).length === 0 && (
                                <tr>
                                    <td colSpan={30} className="px-6 py-12 text-center text-sm text-slate-400">
                                        Tidak ada karyawan yang cocok dengan filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function Name({ row }: { row: Base }) {
    return (
        <div className="flex items-center gap-1.5">
            <span className="truncate font-bold text-slate-700" title={row.name}>
                {row.name}
            </span>
            {row.warning && (
                <span title={row.warning} className="shrink-0 text-amber-500">
                    <AlertTriangle size={13} />
                </span>
            )}
        </div>
    );
}

function Status({ row }: { row: Base }) {
    return (
        <span
            title={row.grade ? `Golongan ${row.grade}` : 'Golongan belum diisi'}
            className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-600"
        >
            {row.ptkp_status}
        </span>
    );
}

/** What is left, with a thin bar of how much of the plafon is used. */
function Sisa({ remaining, benefit_limit }: { remaining: number | null; benefit_limit: number | null }) {
    if (remaining === null || benefit_limit === null) return <span className="text-slate-300">-</span>;
    const used = benefit_limit ? Math.min(100, ((benefit_limit - remaining) / benefit_limit) * 100) : 0;
    return (
        <div className="inline-flex min-w-24 flex-col items-end gap-1">
            <span className={`font-bold tabular-nums ${remaining <= 0 ? 'text-rose-600' : used >= 80 ? 'text-amber-700' : 'text-emerald-700'}`}>
                {rupiah(remaining)}
            </span>
            <span className="h-1 w-full overflow-hidden rounded-full bg-slate-100">
                <span
                    className={`block h-full ${used >= 100 ? 'bg-rose-500' : used >= 80 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                    style={{ width: `${used}%` }}
                />
            </span>
        </div>
    );
}
