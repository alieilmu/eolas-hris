import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Download, FileText, Search } from 'lucide-react';
import { AttendanceHeader, secondaryButton, smallInput } from '@/components/time/ui';
import { rupiah } from '@/lib/compensation';

interface Row {
    employee_id: number;
    employee_number: string;
    name: string;
    position: string | null;
    location: string | null;
    ptkp: string;
    months: number;
    month_start: number;
    month_end: number;
    complete: boolean;
    gross: number;
    tax_year: number;
    withheld: number;
    difference: number;
}

/** Laporan Tahunan: every employee's 1721-A1 of a year, the Coretax import and a PDF per employee. */
export default function PayrollAnnual({ year, years, rows, settings_ready }: { year: number; years: number[]; rows: Row[]; settings_ready: boolean }) {
    const [q, setQ] = useState('');
    const shown = useMemo(() => rows.filter((r) => `${r.employee_number} ${r.name}`.toLowerCase().includes(q.toLowerCase())), [rows, q]);
    const sum = (k: 'gross' | 'tax_year' | 'withheld' | 'difference') => rows.reduce((n, r) => n + r[k], 0);
    const incomplete = rows.filter((r) => !r.complete).length;

    return (
        <>
            <Head title={`1721-A1 ${year}`} />
            <AttendanceHeader title={`Laporan Tahunan ${year}`} subtitle="1721-A1 setiap karyawan dari slip yang sudah disetujui (gaji, THR, final pay)">
                <select value={year} onChange={(e) => router.get('/compensation/payroll-annual', { year: e.target.value })} className={smallInput} aria-label="Tahun">
                    {years.map((y) => (
                        <option key={y}>{y}</option>
                    ))}
                </select>
                {rows.length > 0 && (
                    <a href={`/compensation/payroll-annual/export?year=${year}`} className={secondaryButton}>
                        <Download size={15} /> Coretax BPA1 + rekap
                    </a>
                )}
            </AttendanceHeader>
            <main className="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-6">
                {!settings_ready && (
                    <p className="flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        <AlertTriangle size={15} className="mt-0.5 shrink-0" /> NPWP dan NITKU perusahaan belum diisi.{' '}
                        <Link href="/compensation/settings?tab=payroll" className="font-bold underline">
                            Isi di Pengaturan Kompensasi → Payroll
                        </Link>
                    </p>
                )}
                {incomplete > 0 && (
                    <p className="text-sm text-slate-500">
                        {incomplete} karyawan belum lengkap setahun (gaji Desember belum disetujui): angka masih sementara.
                    </p>
                )}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {(
                        [
                            ['Karyawan', String(rows.length)],
                            ['Bruto setahun', rupiah(sum('gross'))],
                            ['PPh 21 terutang', rupiah(sum('tax_year'))],
                            ['Sudah dipotong', rupiah(sum('withheld'))],
                        ] as const
                    ).map(([l, v]) => (
                        <div key={l} className="rounded-2xl border border-slate-200 bg-white p-4">
                            <p className="text-xs font-semibold text-slate-400">{l}</p>
                            <p className="mt-1 text-lg font-black text-slate-800 tabular-nums">{v}</p>
                        </div>
                    ))}
                </div>
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div className="border-b border-slate-100 p-3">
                        <div className="relative w-64">
                            <Search size={14} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP" className={`${smallInput} w-full pl-8`} />
                        </div>
                    </div>
                    {rows.length === 0 ? (
                        <p className="py-14 text-center text-sm text-slate-400">Belum ada slip yang disetujui di tahun {year}.</p>
                    ) : (
                        <div className="max-h-[65vh] overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-slate-50 text-left text-xs font-bold text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2.5">Karyawan</th>
                                        <th className="px-4 py-2.5">Masa</th>
                                        <th className="px-4 py-2.5">PTKP</th>
                                        <th className="px-4 py-2.5 text-right">Bruto</th>
                                        <th className="px-4 py-2.5 text-right">PPh 21 setahun</th>
                                        <th className="px-4 py-2.5 text-right">Dipotong</th>
                                        <th className="px-4 py-2.5 text-right">Selisih</th>
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((r) => (
                                        <tr key={r.employee_id} className="border-t border-slate-100">
                                            <td className="px-4 py-2">
                                                <span className="font-semibold text-slate-800">{r.name}</span>
                                                <span className="block text-xs text-slate-400">
                                                    {r.employee_number} · {r.location}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-xs text-slate-500">
                                                {r.month_start}–{r.month_end} ({r.months} bln){!r.complete && <span className="ml-1 font-semibold text-amber-600">sementara</span>}
                                            </td>
                                            <td className="px-4 py-2 text-xs">{r.ptkp}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(r.gross)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(r.tax_year)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{rupiah(r.withheld)}</td>
                                            <td className={`px-4 py-2 text-right tabular-nums ${r.difference ? 'font-bold text-amber-700' : 'text-slate-400'}`}>{rupiah(r.difference)}</td>
                                            <td className="px-4 py-2 text-right">
                                                <a href={`/compensation/payroll-annual/${r.employee_id}/pdf?year=${year}`} className="inline-flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline">
                                                    <FileText size={13} /> PDF
                                                </a>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </main>
        </>
    );
}
