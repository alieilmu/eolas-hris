import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { AttendanceHeader, secondaryButton, smallInput } from '@/components/attendance/ui';

interface Props {
    tahun: number;
    tahuns: number[];
    ringkasan: {
        pelamar: number;
        pelamar_baru: number;
        kandidat: number;
        proses: number;
        diterima: number;
        ditolak: number;
        rata_hari: number | null;
    };
    tahapan: { id: number; title: string; color: string; mencapai: number; persen: number; sekarang: number; ditolak: number }[];
    per_form: {
        id: number;
        title: string;
        posisi: string | null;
        pelamar: number;
        baru: number;
        diproses: number;
        ditolak: number;
        diterima: number;
    }[];
    per_bulan: { bulan: number; pelamar: number; kandidat: number; diterima: number; ditolak: number }[];
    per_posisi: { posisi: string; kandidat: number; proses: number; diterima: number; ditolak: number }[];
}

const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';
const td = 'px-3 py-2.5 tabular-nums';

/** Laporan Recruitment for one year. */
export default function Laporan({ tahun, tahuns, ringkasan: r, tahapan, per_form, per_bulan, per_posisi }: Props) {
    const maxBulan = Math.max(1, ...per_bulan.map((m) => m.pelamar + m.kandidat));
    const tiles = [
        { label: 'Pelamar masuk', value: r.pelamar, sub: `${r.pelamar_baru} belum diproses` },
        { label: 'Kandidat', value: r.kandidat, sub: `${r.proses} masih dalam proses` },
        { label: 'Diterima', value: r.diterima, sub: r.kandidat ? `${Math.round((r.diterima / r.kandidat) * 100)}% dari kandidat` : '—' },
        { label: 'Ditolak / tidak lanjut', value: r.ditolak, sub: 'pelamar & kandidat' },
        { label: 'Rata-rata proses', value: r.rata_hari === null ? '—' : `${r.rata_hari} hari`, sub: 'melamar → jadi karyawan' },
    ];

    return (
        <>
            <Head title="Laporan Recruitment" />
            <AttendanceHeader title="Laporan Recruitment" subtitle="Lamaran dan kandidat yang masuk di tahun terpilih">
                <select
                    value={tahun}
                    onChange={(e) => router.get('/recruitment/laporan', { tahun: e.target.value })}
                    className={`${smallInput} font-bold`}
                    aria-label="Tahun"
                >
                    {tahuns.map((t) => (
                        <option key={t} value={t}>
                            Tahun {t}
                        </option>
                    ))}
                </select>
                <a href={`/recruitment/laporan/export?tahun=${tahun}`} className={secondaryButton}>
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
                        {tahapan.map((s) => (
                            <div
                                key={s.id}
                                className="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_auto] items-center gap-3 text-sm"
                                title={`${s.title}: ${s.mencapai} kandidat (${s.persen}%)`}
                            >
                                <span className="truncate text-xs font-bold text-slate-600">{s.title}</span>
                                <div className="h-6 overflow-hidden rounded-r-md bg-slate-100">
                                    <div className="h-full rounded-r-md bg-brand-500" style={{ width: `${s.persen}%` }} />
                                </div>
                                <span className="w-28 text-right text-xs tabular-nums">
                                    <b className="text-slate-800">{s.mencapai}</b> <span className="text-slate-400">({s.persen}%)</span>
                                    {s.sekarang > 0 && <span className="block text-[10px] text-slate-400">{s.sekarang} di sini sekarang</span>}
                                </span>
                            </div>
                        ))}
                        {tahapan.length === 0 && <p className="text-sm text-slate-400">Belum ada tahap.</p>}
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
                                    {per_bulan.map((m) => (
                                        <tr key={m.bulan}>
                                            <td className={`${td} pl-5 font-bold text-slate-600`}>{BULAN[m.bulan - 1]}</td>
                                            <td className={`${td} text-right`}>{m.pelamar || <span className="text-slate-300">·</span>}</td>
                                            <td className={`${td} text-right`}>{m.kandidat || <span className="text-slate-300">·</span>}</td>
                                            <td className={`${td} text-right font-bold text-emerald-700`}>
                                                {m.diterima || <span className="font-normal text-slate-300">·</span>}
                                            </td>
                                            <td className={`${td} text-right`}>{m.ditolak || <span className="text-slate-300">·</span>}</td>
                                            <td className="pr-5">
                                                <div
                                                    className="h-2 rounded-full bg-brand-400"
                                                    style={{ width: `${((m.pelamar + m.kandidat) / maxBulan) * 100}%` }}
                                                    title={`${m.pelamar} pelamar, ${m.kandidat} kandidat`}
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
                                f.pelamar,
                                f.baru,
                                f.diproses,
                                f.ditolak,
                                f.diterima,
                            ])}
                        />
                        <Table
                            title="Per posisi"
                            empty="Belum ada kandidat tahun ini."
                            head={['Posisi', 'Kandidat', 'Proses', 'Diterima', 'Tidak lanjut']}
                            rows={per_posisi.map((p) => [
                                <span key="p" className="font-bold text-slate-700">
                                    {p.posisi}
                                </span>,
                                p.kandidat,
                                p.proses,
                                p.diterima,
                                p.ditolak,
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
