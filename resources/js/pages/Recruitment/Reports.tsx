import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { AttendanceHeader, secondaryButton, smallInput } from '@/components/time/ui';

interface Props {
    year: number;
    years: number[];
    summary: {
        applicants: number;
        new_applicants: number;
        candidate: number;
        process: number;
        accepted: number;
        rejected: number;
        average_days: number | null;
    };
    stages: { id: number; title: string; color: string; reached: number; percent: number; now: number; rejected: number }[];
    per_form: {
        id: number;
        title: string;
        position_title: string | null;
        applicants: number;
        new: number;
        in_process: number;
        rejected: number;
        accepted: number;
    }[];
    per_month: { month: number; applicants: number; candidate: number; accepted: number; rejected: number }[];
    per_position_title: { position_title: string; candidate: number; process: number; accepted: number; rejected: number }[];
}

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
const td = 'px-3 py-2.5 tabular-nums';

/** Laporan Rekrutmen for one year. */
export default function Reports({ year, years, summary: r, stages, per_form, per_month, per_position_title }: Props) {
    const maxBulan = Math.max(1, ...per_month.map((m) => m.applicants + m.candidate));
    const tiles = [
        { label: 'Pelamar masuk', value: r.applicants, sub: `${r.new_applicants} belum diproses` },
        { label: 'Kandidat', value: r.candidate, sub: `${r.process} masih dalam proses` },
        { label: 'Diterima', value: r.accepted, sub: r.candidate ? `${Math.round((r.accepted / r.candidate) * 100)}% dari kandidat` : '—' },
        { label: 'Ditolak / tidak lanjut', value: r.rejected, sub: 'pelamar & kandidat' },
        { label: 'Rata-rata proses', value: r.average_days === null ? '—' : `${r.average_days} hari`, sub: 'melamar → jadi karyawan' },
    ];

    return (
        <>
            <Head title="Laporan Rekrutmen" />
            <AttendanceHeader title="Laporan Rekrutmen" subtitle="Lamaran dan kandidat yang masuk di tahun terpilih">
                <select
                    value={year}
                    onChange={(e) => router.get('/recruitment/reports', { year: e.target.value })}
                    className={`${smallInput} font-bold`}
                    aria-label="Tahun"
                >
                    {years.map((t) => (
                        <option key={t} value={t}>
                            Tahun {t}
                        </option>
                    ))}
                </select>
                <a href={`/recruitment/reports/export?year=${year}`} className={secondaryButton}>
                    <Download size={16} /> Export Excel
                </a>
            </AttendanceHeader>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                <section className="grid grid-cols-2 gap-3 lg:grid-cols-5 [&>*]:min-w-0">
                    {tiles.map((t) => (
                        <div key={t.label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">{t.label}</p>
                            <p className="mt-1 text-2xl font-black text-slate-800 tabular-nums">{t.value}</p>
                            <p className="truncate text-[11px] text-slate-400">{t.sub}</p>
                        </div>
                    ))}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                    <h2 className="text-sm font-bold text-slate-800">Sampai tahap mana</h2>
                    <p className="mb-4 text-xs text-slate-500">Jumlah kandidat yang pernah mencapai tiap tahap (termasuk yang sudah lewat).</p>
                    <div className="space-y-2">
                        {stages.map((s) => (
                            <div
                                key={s.id}
                                className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_auto] items-center gap-3 text-sm"
                                title={`${s.title}: ${s.reached} kandidat (${s.percent}%)`}
                            >
                                <span className="truncate text-xs font-bold text-slate-600">{s.title}</span>
                                <div className="h-6 overflow-hidden rounded-r-md bg-slate-100">
                                    <div className="h-full rounded-r-md bg-brand-500" style={{ width: `${s.percent}%` }} />
                                </div>
                                <span className="w-28 text-right text-xs tabular-nums">
                                    <b className="text-slate-800">{s.reached}</b> <span className="text-slate-400">({s.percent}%)</span>
                                    {s.now > 0 && <span className="block text-[10px] text-slate-400">{s.now} di sini sekarang</span>}
                                </span>
                            </div>
                        ))}
                        {stages.length === 0 && <p className="text-sm text-slate-400">Belum ada tahap.</p>}
                    </div>
                </section>

                <div className="grid gap-5 xl:grid-cols-2 [&>*]:min-w-0">
                    <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <h2 className="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-800">Per bulan</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className={`${th} pl-5`}>Bulan</th>
                                        <th className={`${th} text-right`}>Pelamar</th>
                                        <th className={`${th} text-right`}>Kandidat</th>
                                        <th className={`${th} text-right`}>Diterima</th>
                                        <th className={`${th} text-right`}>Tidak lanjut</th>
                                        <th className={`${th} w-1/3`} />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {per_month.map((m) => (
                                        <tr key={m.month}>
                                            <td className={`${td} pl-5 font-bold text-slate-600`}>{BULAN[m.month - 1]}</td>
                                            <td className={`${td} text-right`}>{m.applicants || <span className="text-slate-300">·</span>}</td>
                                            <td className={`${td} text-right`}>{m.candidate || <span className="text-slate-300">·</span>}</td>
                                            <td className={`${td} text-right font-bold text-emerald-700`}>
                                                {m.accepted || <span className="font-normal text-slate-300">·</span>}
                                            </td>
                                            <td className={`${td} text-right`}>{m.rejected || <span className="text-slate-300">·</span>}</td>
                                            <td className="pr-5">
                                                <div
                                                    className="h-2 rounded-full bg-brand-400"
                                                    style={{ width: `${((m.applicants + m.candidate) / maxBulan) * 100}%` }}
                                                    title={`${m.applicants} pelamar, ${m.candidate} kandidat`}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div className="space-y-5">
                        <Table
                            title="Per lowongan (form)"
                            empty="Belum ada lamaran tahun ini."
                            head={['Lowongan', 'Pelamar', 'Baru', 'Ke Tracking', 'Ditolak', 'Diterima']}
                            rows={per_form.map((f) => [
                                <span key="t" className="font-bold text-slate-700">
                                    {f.title}
                                </span>,
                                f.applicants,
                                f.new,
                                f.in_process,
                                f.rejected,
                                f.accepted,
                            ])}
                        />
                        <Table
                            title="Per posisi"
                            empty="Belum ada kandidat tahun ini."
                            head={['Posisi', 'Kandidat', 'Proses', 'Diterima', 'Tidak lanjut']}
                            rows={per_position_title.map((p) => [
                                <span key="p" className="font-bold text-slate-700">
                                    {p.position_title}
                                </span>,
                                p.candidate,
                                p.process,
                                p.accepted,
                                p.rejected,
                            ])}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

function Table({ title, head, rows, empty }: { title: string; head: string[]; rows: React.ReactNode[][]; empty: string }) {
    return (
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <h2 className="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-800">{title}</h2>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {head.map((h, i) => (
                                <th key={h} className={`${th} ${i === 0 ? 'pl-5' : 'text-right'}`}>
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((r, i) => (
                            <tr key={i}>
                                {r.map((cell, j) => (
                                    <td key={j} className={`${td} ${j === 0 ? 'pl-5' : 'text-right'}`}>
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                {rows.length === 0 && <p className="px-5 py-6 text-center text-xs text-slate-400">{empty}</p>}
            </div>
        </section>
    );
}
