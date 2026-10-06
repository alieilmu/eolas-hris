import { useState, type ReactNode } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Cake, CalendarClock, ClipboardCheck, Hourglass, Loader2, RefreshCw, Users } from 'lucide-react';
import { ExtendPkwtDialog } from '@/components/manpower/ExtendPkwtDialog';
import { KaryawanHeader } from '@/components/manpower/KaryawanHeader';
import { fmtDate } from '@/lib/format';
import type { DashboardData, PkwtListItem } from '@/types/manpower';

// Riung blue and gold, nudged in chroma/lightness to pass the palette validator, plus a neutral
// for "not filled in" so a missing value never looks like a real category.
const COLOR_A = '#4a67a8';
const COLOR_B = '#d9a300';
const COLOR_NEUTRAL = '#c3c2b7';

const pct = (value: number, total: number) => (total > 0 ? Math.round((value / total) * 100) : 0);

interface Slice {
    label: string;
    value: number;
    color: string;
}

function Donut({ title, slices, centerLabel }: { title: string; slices: Slice[]; centerLabel: string }) {
    const [hover, setHover] = useState<number | null>(null);
    const total = slices.reduce((s, x) => s + x.value, 0);

    const size = 168;
    const stroke = 24;
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const gap = 2; // surface-colored gap between slices

    const drawn = slices.filter((s) => s.value > 0);
    let offset = 0;

    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 className="mb-4 text-sm font-bold text-slate-800">{title}</h3>
            <div className="flex items-center gap-6">
                <div className="relative shrink-0" style={{ width: size, height: size }}>
                    <svg
                        width={size}
                        height={size}
                        viewBox={`0 0 ${size} ${size}`}
                        role="img"
                        aria-label={`${title}: ${slices.map((s) => `${s.label} ${s.value}`).join(', ')}`}
                    >
                        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#f1f5f9" strokeWidth={stroke} />
                        {total > 0 &&
                            drawn.map((s) => {
                                const len = (s.value / total) * c;
                                const visible = drawn.length === 1 ? len : Math.max(len - gap, 0);
                                const el = (
                                    <circle
                                        key={s.label}
                                        cx={size / 2}
                                        cy={size / 2}
                                        r={r}
                                        fill="none"
                                        stroke={s.color}
                                        strokeWidth={stroke}
                                        strokeDasharray={`${visible} ${c - visible}`}
                                        strokeDashoffset={-offset}
                                        transform={`rotate(-90 ${size / 2} ${size / 2})`}
                                        opacity={hover === null || slices[hover]?.label === s.label ? 1 : 0.35}
                                        onMouseEnter={() => setHover(slices.findIndex((x) => x.label === s.label))}
                                        onMouseLeave={() => setHover(null)}
                                    >
                                        <title>{`${s.label}: ${s.value} (${pct(s.value, total)}%)`}</title>
                                    </circle>
                                );
                                offset += len;
                                return el;
                            })}
                    </svg>
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-2xl leading-none font-black text-slate-800">{total}</span>
                        <span className="mt-1 text-[10px] font-bold tracking-wide text-slate-400 uppercase">{centerLabel}</span>
                    </div>
                </div>

                <ul className="min-w-0 flex-1 space-y-2.5">
                    {slices.map((s, i) => (
                        <li
                            key={s.label}
                            className="flex items-center gap-2.5 text-sm"
                            onMouseEnter={() => setHover(i)}
                            onMouseLeave={() => setHover(null)}
                        >
                            <span className="h-3 w-3 shrink-0 rounded-sm" style={{ background: s.color }} />
                            <span className="flex-1 truncate text-slate-600">{s.label}</span>
                            <span className="font-bold text-slate-800 tabular-nums">{s.value}</span>
                            <span className="w-10 text-right text-xs text-slate-400 tabular-nums">{pct(s.value, total)}%</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function StatTile({ icon, label, value, hint }: { icon: ReactNode; label: string; value: string; hint?: string }) {
    return (
        <div className="flex items-center gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">{icon}</div>
            <div className="min-w-0">
                <p className="text-2xl leading-tight font-black text-slate-800">{value}</p>
                <p className="text-xs font-bold text-slate-500">{label}</p>
                {hint && <p className="mt-0.5 text-[11px] text-slate-400">{hint}</p>}
            </div>
        </div>
    );
}

function Card({ title, icon, count, children }: { title: string; icon: ReactNode; count?: number; children: ReactNode }) {
    return (
        <div className="flex min-h-0 flex-col rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center gap-2 border-b border-slate-100 px-6 py-4">
                <span className="text-slate-400">{icon}</span>
                <h3 className="flex-1 text-sm font-bold text-slate-800">{title}</h3>
                {count !== undefined && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">{count}</span>}
            </div>
            <div className="max-h-72 overflow-y-auto">{children}</div>
        </div>
    );
}

const Empty = ({ text }: { text: string }) => <p className="px-6 py-8 text-center text-sm text-slate-400">{text}</p>;

const MAX_PKWT_PERIODS = 6;

function PkwtRows({ items, kind, onExtend }: { items: PkwtListItem[]; kind: 'akan' | 'sudah'; onExtend: (item: PkwtListItem) => void }) {
    return (
        <ul className="divide-y divide-slate-50">
            {items.map((it) => (
                <li key={it.karyawan_id} className="flex items-center gap-3 px-6 py-3">
                    <div className="min-w-0 flex-1">
                        <Link
                            href={`/manpower/karyawan/${it.karyawan_id}/profil`}
                            className="block truncate text-sm font-bold text-slate-700 hover:text-brand-600"
                        >
                            {it.name}
                        </Link>
                        <p className="truncate text-[11px] text-slate-400">
                            NRP {it.nrp} • {it.jabatan} • {it.periode_label}
                        </p>
                    </div>
                    <div className="shrink-0 text-right">
                        <p className={`text-xs font-bold tabular-nums ${kind === 'akan' ? 'text-red-600' : 'text-slate-600'}`}>
                            {fmtDate(it.tanggal_berakhir)}
                        </p>
                        <p className="text-[11px] text-slate-400">
                            {kind === 'akan'
                                ? it.sisa_hari === 0
                                    ? 'berakhir hari ini'
                                    : `${it.sisa_hari} hari lagi`
                                : `lewat ${Math.abs(it.sisa_hari)} hari`}
                        </p>
                    </div>
                    <button
                        onClick={() => onExtend(it)}
                        title={it.period_count >= MAX_PKWT_PERIODS ? `Sudah ${MAX_PKWT_PERIODS} periode PKWT` : 'Tambah perpanjangan PKWT'}
                        className="shrink-0 rounded-lg bg-brand-50 px-3 py-1.5 text-[11px] font-bold text-brand-600 transition-all hover:bg-brand-100"
                    >
                        Perpanjang
                    </button>
                </li>
            ))}
        </ul>
    );
}

export default function Dashboard({ dashboard: data }: { dashboard: DashboardData }) {
    const [reloading, setReloading] = useState(false);
    const [extending, setExtending] = useState<PkwtListItem | null>(null);
    const { asal, status_pegawai: statusPegawai, completeness } = data;

    const reload = () =>
        router.reload({
            only: ['dashboard'],
            onStart: () => setReloading(true),
            onFinish: () => setReloading(false),
        });

    return (
        <>
            <Head title="Man Power — Dashboard" />
            <KaryawanHeader />

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50">
                <div className="w-full space-y-6 px-8 py-6">
                    <div className="flex items-center justify-between">
                        <p className="text-xs font-medium text-slate-400">
                            Menghitung karyawan berstatus <span className="font-bold">Aktif</span> • per {fmtDate(data.today)}
                        </p>
                        <button
                            onClick={reload}
                            disabled={reloading}
                            className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-500 transition-all hover:bg-slate-50 disabled:opacity-50"
                        >
                            {reloading ? <Loader2 size={13} className="animate-spin" /> : <RefreshCw size={13} />}
                            Muat ulang
                        </button>
                    </div>

                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatTile icon={<Users size={20} />} label="Karyawan Aktif" value={String(data.total_aktif)} />
                        <StatTile
                            icon={<ClipboardCheck size={20} />}
                            label="Kelengkapan Data"
                            value={completeness.overall_percent === null ? '-' : `${completeness.overall_percent}%`}
                            hint="rata-rata seluruh kelompok"
                        />
                        <StatTile
                            icon={<CalendarClock size={20} />}
                            label="PKWT Berakhir ≤ 1 Bulan"
                            value={String(data.pkwt_akan_berakhir.length)}
                            hint={`${data.pkwt_berakhir.length} sudah berakhir`}
                        />
                        <StatTile
                            icon={<Hourglass size={20} />}
                            label="Pensiun ≤ 1 Tahun"
                            value={String(data.pensiun.length)}
                            hint={`usia pensiun ${data.pensiun_age} tahun`}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Donut
                            title="Karyawan Lokal vs Kiriman"
                            centerLabel="karyawan"
                            slices={[
                                { label: 'Lokal', value: asal.lokal, color: COLOR_A },
                                { label: 'Kiriman', value: asal.kiriman, color: COLOR_B },
                                { label: 'Belum diisi', value: asal.belum_diisi, color: COLOR_NEUTRAL },
                            ]}
                        />
                        <Donut
                            title="Karyawan PKWT vs Tetap"
                            centerLabel="karyawan"
                            slices={[
                                { label: 'PKWT', value: statusPegawai.pkwt, color: COLOR_A },
                                { label: 'Tetap', value: statusPegawai.tetap, color: COLOR_B },
                            ]}
                        />
                    </div>

                    <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="mb-1 flex items-start justify-between gap-4">
                            <h3 className="text-sm font-bold text-slate-800">Kelengkapan Data per Kelompok</h3>
                            <span className="text-2xl leading-none font-black text-slate-800">
                                {completeness.overall_percent === null ? '-' : `${completeness.overall_percent}%`}
                            </span>
                        </div>
                        <p className="mb-5 text-xs text-slate-400">
                            Hanya field yang relevan yang dihitung — mis. data pasangan &amp; mertua hanya untuk yang berstatus Menikah, data anak
                            hanya untuk anak yang tercatat, PKWT hanya untuk yang belum Tetap.
                        </p>
                        <ul className="space-y-3">
                            {completeness.groups.map((g) => (
                                <li key={g.id} className="grid grid-cols-[minmax(0,15rem)_1fr_auto] items-center gap-4">
                                    <span className="truncate text-xs font-bold text-slate-600" title={g.title}>
                                        {g.title}
                                    </span>
                                    <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                                        {g.percent !== null && (
                                            <div className="h-full rounded-full" style={{ width: `${g.percent}%`, background: COLOR_A }} />
                                        )}
                                    </div>
                                    <span className="w-44 text-right">
                                        {g.percent === null ? (
                                            <span className="text-[11px] text-slate-400">tidak ada yang perlu diisi</span>
                                        ) : (
                                            <>
                                                <span className="text-sm font-black text-slate-800 tabular-nums">{g.percent}%</span>
                                                <span className="ml-2 text-[11px] text-slate-400 tabular-nums">{g.applicable} karyawan</span>
                                            </>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <Card title="PKWT Akan Berakhir dalam 1 Bulan" icon={<CalendarClock size={16} />} count={data.pkwt_akan_berakhir.length}>
                            {data.pkwt_akan_berakhir.length === 0 ? (
                                <Empty text="Tidak ada PKWT yang berakhir dalam 1 bulan ke depan." />
                            ) : (
                                <PkwtRows items={data.pkwt_akan_berakhir} kind="akan" onExtend={setExtending} />
                            )}
                        </Card>

                        <Card title="PKWT Sudah Berakhir" icon={<CalendarClock size={16} />} count={data.pkwt_berakhir.length}>
                            {data.pkwt_berakhir.length === 0 ? (
                                <Empty text="Tidak ada PKWT yang sudah berakhir tanpa perpanjangan." />
                            ) : (
                                <PkwtRows items={data.pkwt_berakhir} kind="sudah" onExtend={setExtending} />
                            )}
                        </Card>

                        <Card
                            title={`Akan Pensiun dalam 1 Tahun (usia ${data.pensiun_age})`}
                            icon={<Hourglass size={16} />}
                            count={data.pensiun.length}
                        >
                            {data.pensiun.length === 0 ? (
                                <Empty text="Tidak ada karyawan yang akan pensiun dalam 1 tahun ke depan." />
                            ) : (
                                <ul className="divide-y divide-slate-50">
                                    {data.pensiun.map((p) => (
                                        <li key={p.karyawan_id} className="flex items-center gap-3 px-6 py-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-bold text-slate-700">{p.name}</p>
                                                <p className="truncate text-[11px] text-slate-400">
                                                    NRP {p.nrp} • {p.jabatan} • lahir {fmtDate(p.tanggal_lahir)}
                                                </p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className="text-xs font-bold text-slate-700 tabular-nums">{fmtDate(p.tanggal_pensiun)}</p>
                                                <p className="text-[11px] text-slate-400">
                                                    {p.sisa_hari <= 60 ? `${p.sisa_hari} hari lagi` : `± ${Math.round(p.sisa_hari / 30)} bulan lagi`}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>

                        <Card title={`Berulang Tahun — ${data.ulang_tahun_bulan}`} icon={<Cake size={16} />} count={data.ulang_tahun.length}>
                            {data.ulang_tahun.length === 0 ? (
                                <Empty text={`Belum ada karyawan yang berulang tahun di bulan ${data.ulang_tahun_bulan}.`} />
                            ) : (
                                <ul className="divide-y divide-slate-50">
                                    {data.ulang_tahun.map((u) => (
                                        <li key={u.karyawan_id} className="flex items-center gap-3 px-6 py-3">
                                            <div
                                                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-sm font-black ${
                                                    u.hari_ini ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'
                                                }`}
                                            >
                                                {u.hari}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-bold text-slate-700">{u.name}</p>
                                                <p className="truncate text-[11px] text-slate-400">
                                                    NRP {u.nrp} • {u.jabatan}
                                                </p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                {u.hari_ini && (
                                                    <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold text-brand-600">
                                                        Hari ini
                                                    </span>
                                                )}
                                                <p className="mt-0.5 text-[11px] text-slate-400">ke-{u.usia_baru} tahun</p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                </div>
            </div>

            {extending && <ExtendPkwtDialog item={extending} onClose={() => setExtending(null)} />}
        </>
    );
}
