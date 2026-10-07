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
} from '@/components/attendance/ui';
import { monthShort, rupiah } from '@/lib/comben';

interface Base {
    id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    sub_group: string | null;
    departemen_id: number | null;
    work_location_id: number | null;
    sub_location_id: number | null;
    golongan: string | null;
    status_ptkp: string;
    warning: string | null;
    bulan: Record<string, number>;
}

interface PengobatanRow extends Base {
    layer: string | null;
    keluarga: boolean;
    plafon: number | null;
    terpakai: number;
    /** From the portal, waiting for HR (already held from Sisa). */
    menunggu: number;
    sisa: number | null;
}

interface KacamataRow extends Base {
    key: string;
    anggota: string;
    hubungan: string;
    plafon_bingkai: number | null;
    plafon_lensa: number | null;
    bingkai: number;
    lensa: number;
    sisa_bingkai: number | null;
    sisa_lensa: number | null;
    bingkai_terakhir: string | null;
}

interface Props {
    tahun: number;
    tahuns: number[];
    months: { value: string; label: string; span: string }[];
    current_bulan: string;
    pengobatan: PengobatanRow[];
    kacamata: KacamataRow[];
    locations: { id: number; name: string }[];
    sub_locations: SubLocationOption[];
    departemens: { id: number; name: string }[];
}

type Tab = 'pengobatan' | 'kacamata';

const th = 'border-b border-slate-200 bg-slate-100 px-2.5 py-2.5 text-[10px] font-bold tracking-wide whitespace-nowrap text-slate-500 uppercase';
const td = 'border-b border-slate-100 px-2.5 py-2 whitespace-nowrap';

export default function Monitoring({ tahun, tahuns, months, current_bulan, pengobatan, kacamata, locations, sub_locations, departemens }: Props) {
    const query = new URLSearchParams(window.location.search);
    const [tab, setTab] = useState<Tab>(query.get('tab') === 'kacamata' ? 'kacamata' : 'pengobatan');
    const [lokasi, setLokasi] = useState(query.get('lokasi') ?? '');
    const [sub, setSub] = useState(query.get('sub') ?? '');
    const [departemen, setDepartemen] = useState(query.get('departemen') ?? '');
    const [search, setSearch] = useState(query.get('q') ?? '');

    useEffect(() => {
        const url = new URL(window.location.href);
        for (const [k, v] of [
            ['tab', tab === 'kacamata' ? 'kacamata' : ''],
            ['lokasi', lokasi],
            ['sub', sub],
            ['departemen', departemen],
            ['q', search.trim()],
        ]) {
            if (v) url.searchParams.set(k, v);
            else url.searchParams.delete(k);
        }
        window.history.replaceState(window.history.state, '', url);
    }, [tab, lokasi, sub, departemen, search]);

    const keep = (r: Base) => {
        const q = search.trim().toLowerCase();
        return (
            (!lokasi || String(r.work_location_id) === lokasi) &&
            matchesSubLocation(sub, r.sub_location_id) &&
            (!departemen || String(r.departemen_id) === departemen) &&
            (!q || r.name.toLowerCase().includes(q) || r.nrp.includes(q) || (r.jabatan ?? '').toLowerCase().includes(q))
        );
    };
    const rowsP = useMemo(() => pengobatan.filter(keep), [pengobatan, lokasi, sub, departemen, search]); // eslint-disable-line react-hooks/exhaustive-deps
    const rowsK = useMemo(() => kacamata.filter(keep), [kacamata, lokasi, sub, departemen, search]); // eslint-disable-line react-hooks/exhaustive-deps

    const totals = useMemo(() => {
        const plafon = rowsP.reduce((n, r) => n + (r.plafon ?? 0), 0);
        const terpakai = rowsP.reduce((n, r) => n + r.terpakai, 0);
        const kacamataTotal = rowsK.reduce((n, r) => n + Object.values(r.bulan).reduce((a, b) => a + b, 0), 0);
        return { plafon, terpakai, sisa: plafon - terpakai, kacamata: kacamataTotal, warnings: rowsP.filter((r) => r.warning).length };
    }, [rowsP, rowsK]);

    // Another year keeps the tab and the filters (they live in the address).
    const goTahun = (value: string) =>
        router.get('/comben/monitoring', { ...Object.fromEntries(new URL(window.location.href).searchParams), tahun: value });

    const exportUrl = () => {
        const q = new URLSearchParams({ tahun: String(tahun) });
        for (const [k, v] of Object.entries({ lokasi, sub, departemen, q: search.trim() })) if (v) q.set(k, v);
        return `/comben/monitoring/export?${q}`;
    };

    const monthHead = (m: { value: string; label: string; span: string }) => (
        <th
            key={m.value}
            title={`${m.label}: ${m.span}`}
            className={`${th} text-right ${m.value === current_bulan ? 'bg-brand-100! text-brand-800!' : ''}`}
        >
            {monthShort(m.value)}
        </th>
    );
    const monthCells = (r: Base) =>
        months.map((m) => (
            <td
                key={m.value}
                className={`${td} text-right tabular-nums ${m.value === current_bulan ? 'bg-brand-50/50' : ''} ${r.bulan[m.value] ? 'text-slate-700' : 'text-slate-300'}`}
            >
                {r.bulan[m.value] ? rupiah(r.bulan[m.value]) : '·'}
            </td>
        ));

    return (
        <>
            <Head title="Monitoring Plafon" />
            <AttendanceHeader title="Monitoring Plafon" subtitle="Plafon direset tiap awal tahun; bulan buku ditutup tiap tanggal 8">
                <select value={tahun} onChange={(e) => goTahun(e.target.value)} className={`${smallInput} font-bold`} aria-label="Tahun plafon">
                    {tahuns.map((t) => (
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
                                ['pengobatan', 'Pengobatan', Stethoscope],
                                ['kacamata', 'Kacamata', Glasses],
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
                        value={lokasi}
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
                        <SubLocationSelect value={sub} onChange={setSub} subLocations={sub_locations} locations={locations} lokasi={lokasi} />
                    )}
                    <select
                        value={departemen}
                        onChange={(e) => setDepartemen(e.target.value)}
                        className={`${smallInput} max-w-52`}
                        aria-label="Departemen"
                    >
                        <option value="">Semua departemen</option>
                        {departemens.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                </div>

                {/* Totals of what is shown */}
                <div className="grid shrink-0 grid-cols-2 gap-3 px-6 pt-4 lg:grid-cols-5">
                    {(tab === 'pengobatan'
                        ? [
                              ['Karyawan', String(rowsP.length), 'text-slate-700'],
                              ['Total plafon', rupiah(totals.plafon), 'text-slate-700'],
                              ['Terpakai', rupiah(totals.terpakai), 'text-brand-700'],
                              ['Sisa', rupiah(totals.sisa), 'text-emerald-700'],
                              ['Perlu dicek', `${totals.warnings} karyawan`, totals.warnings ? 'text-amber-700' : 'text-slate-400'],
                          ]
                        : [
                              ['Anggota keluarga', String(rowsK.length), 'text-slate-700'],
                              ['Klaim kacamata ' + tahun, rupiah(totals.kacamata), 'text-brand-700'],
                              ['Klaim bingkai', String(rowsK.filter((r) => r.bingkai > 0).length) + ' orang', 'text-slate-700'],
                              ['Klaim lensa', String(rowsK.filter((r) => r.lensa > 0).length) + ' orang', 'text-slate-700'],
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
                                {tab === 'kacamata' && <th className={`${th} text-left`}>Anggota</th>}
                                <th className={`${th} text-left`}>Jabatan</th>
                                <th className={`${th} text-left`}>SubGroup</th>
                                <th className={th}>Status</th>
                                {tab === 'pengobatan' ? (
                                    <th className={`${th} text-right`}>Plafon</th>
                                ) : (
                                    <>
                                        <th className={`${th} text-right`}>Plafon bingkai</th>
                                        <th className={`${th} text-right`}>Plafon lensa</th>
                                    </>
                                )}
                                {months.map(monthHead)}
                                {tab === 'pengobatan' ? (
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
                            {tab === 'pengobatan' &&
                                rowsP.map((r) => (
                                    <tr key={r.id} className="group hover:bg-slate-50">
                                        <td
                                            className={`${td} sticky left-0 z-10 w-[84px] min-w-[84px] max-w-[84px] bg-white font-mono text-[11px] text-slate-500 group-hover:bg-slate-50`}
                                        >
                                            {r.nrp}
                                        </td>
                                        <td className={`${td} sticky left-[84px] z-10 max-w-52 border-r bg-white group-hover:bg-slate-50`}>
                                            <Name row={r} />
                                        </td>
                                        <td className={`${td} max-w-56 truncate text-slate-500`} title={r.jabatan ?? undefined}>
                                            {r.jabatan ?? '-'}
                                        </td>
                                        <td className={`${td} text-slate-500`}>{r.sub_group ?? '-'}</td>
                                        <td className={`${td} text-center`}>
                                            <Status row={r} />
                                        </td>
                                        <td className={`${td} text-right font-bold text-slate-700 tabular-nums`}>
                                            {rupiah(r.plafon)}
                                            {r.layer && <span className="ml-1 text-[10px] font-normal text-slate-400">{r.layer}</span>}
                                        </td>
                                        {monthCells(r)}
                                        <td className={`${td} border-l text-right font-bold text-slate-700 tabular-nums`}>
                                            {r.terpakai ? rupiah(r.terpakai) : '-'}
                                            {r.menunggu > 0 && (
                                                <span className="block text-[10px] font-bold text-amber-700">+{rupiah(r.menunggu)} menunggu</span>
                                            )}
                                        </td>
                                        <td className={`${td} text-right`}>
                                            <Sisa sisa={r.sisa} plafon={r.plafon} />
                                        </td>
                                    </tr>
                                ))}
                            {tab === 'kacamata' &&
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
                                                {first ? r.nrp : ''}
                                            </td>
                                            <td className={`${td} sticky left-[84px] z-10 max-w-52 border-r bg-white group-hover:bg-slate-50`}>
                                                {first && <Name row={r} />}
                                            </td>
                                            <td className={`${td} max-w-48 truncate`} title={r.anggota}>
                                                <span className="font-bold text-slate-700">{r.anggota}</span>
                                                <span className="ml-1 text-[10px] text-slate-400">{r.hubungan}</span>
                                            </td>
                                            <td className={`${td} max-w-48 truncate text-slate-500`}>{first ? (r.jabatan ?? '-') : ''}</td>
                                            <td className={`${td} text-slate-500`}>{first ? (r.sub_group ?? '-') : ''}</td>
                                            <td className={`${td} text-center`}>{first && <Status row={r} />}</td>
                                            <td className={`${td} text-right text-slate-600 tabular-nums`}>{rupiah(r.plafon_bingkai)}</td>
                                            <td className={`${td} text-right text-slate-600 tabular-nums`}>{rupiah(r.plafon_lensa)}</td>
                                            {monthCells(r)}
                                            <td
                                                className={`${td} border-l text-right tabular-nums`}
                                                title={r.bingkai_terakhir ? `Bingkai terakhir: bulan buku ${r.bingkai_terakhir}` : undefined}
                                            >
                                                {r.bingkai ? rupiah(r.bingkai) : '-'}
                                            </td>
                                            <td className={`${td} text-right`}>
                                                <Sisa sisa={r.sisa_bingkai} plafon={r.plafon_bingkai} />
                                            </td>
                                            <td className={`${td} text-right tabular-nums`}>{r.lensa ? rupiah(r.lensa) : '-'}</td>
                                            <td className={`${td} text-right`}>
                                                <Sisa sisa={r.sisa_lensa} plafon={r.plafon_lensa} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            {(tab === 'pengobatan' ? rowsP : rowsK).length === 0 && (
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
            title={row.golongan ? `Golongan ${row.golongan}` : 'Golongan belum diisi'}
            className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-slate-600"
        >
            {row.status_ptkp}
        </span>
    );
}

/** What is left, with a thin bar of how much of the plafon is used. */
function Sisa({ sisa, plafon }: { sisa: number | null; plafon: number | null }) {
    if (sisa === null || plafon === null) return <span className="text-slate-300">-</span>;
    const used = plafon ? Math.min(100, ((plafon - sisa) / plafon) * 100) : 0;
    return (
        <div className="inline-flex min-w-24 flex-col items-end gap-1">
            <span className={`font-bold tabular-nums ${sisa <= 0 ? 'text-rose-600' : used >= 80 ? 'text-amber-700' : 'text-emerald-700'}`}>
                {rupiah(sisa)}
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
