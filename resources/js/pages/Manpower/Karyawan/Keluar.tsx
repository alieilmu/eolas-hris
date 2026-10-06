import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IdCard, Loader2, RotateCcw, Search, X } from 'lucide-react';
import { KaryawanHeader } from '@/components/manpower/KaryawanHeader';
import { fmtDate } from '@/lib/format';
import type { KeluarRow } from '@/types/manpower';

const COLUMNS = [
    'NRP',
    'Nama',
    'Jabatan Terakhir',
    'Departemen',
    'Work Location',
    'Tanggal Masuk',
    'Tanggal Keluar',
    'Masa Kerja',
    'Alasan Keluar',
    'Keterangan',
];

const chip = (active: boolean) =>
    `rounded-lg border px-2.5 py-1 text-[11px] font-bold transition-all ${
        active ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'
    }`;

export default function Keluar({ rows, alasan_options: alasanOptions }: { rows: KeluarRow[]; alasan_options: string[] }) {
    const [search, setSearch] = useState('');
    const [alasanFilter, setAlasanFilter] = useState<string | null>(null);
    const [restoring, setRestoring] = useState<KeluarRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const confirmRestore = () => {
        if (!restoring) return;
        router.delete(`/manpower/karyawan/${restoring.id}/keluar`, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setRestoring(null);
            },
            onSuccess: () => setError(null),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Gagal mengaktifkan kembali.'),
        });
    };

    const countByAlasan = useMemo(() => {
        const counts = new Map<string, number>();
        rows.forEach((r) => counts.set(r.alasan_keluar ?? '-', (counts.get(r.alasan_keluar ?? '-') ?? 0) + 1));
        return counts;
    }, [rows]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter(
            (r) =>
                (!alasanFilter || r.alasan_keluar === alasanFilter) &&
                (!q || [r.nrp, r.name, r.jabatan_name].some((v) => v.toLowerCase().includes(q))),
        );
    }, [rows, search, alasanFilter]);

    return (
        <>
            <Head title="Man Power — Karyawan Keluar" />
            <KaryawanHeader />

            <div className="min-h-0 flex-1 bg-slate-50 p-6">
                <div className="flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="shrink-0 space-y-3 border-b border-slate-100 bg-slate-50/50 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-bold text-slate-800">Karyawan Keluar</h2>
                                <p className="mt-0.5 text-xs text-slate-400">
                                    {filtered.length} dari {rows.length} karyawan • tidak dihitung di Dashboard &amp; Data Lengkap
                                </p>
                            </div>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-3 text-slate-300" size={16} />
                                <input
                                    type="text"
                                    placeholder="Cari NRP / nama / jabatan..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-56 rounded-xl border border-slate-200 bg-white py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-1.5">
                            <button onClick={() => setAlasanFilter(null)} className={chip(alasanFilter === null)}>
                                Semua ({rows.length})
                            </button>
                            {alasanOptions.map((a) => (
                                <button key={a} onClick={() => setAlasanFilter(alasanFilter === a ? null : a)} className={chip(alasanFilter === a)}>
                                    {a} ({countByAlasan.get(a) ?? 0})
                                </button>
                            ))}
                        </div>
                    </div>

                    {error && (
                        <div className="mx-5 mt-4 flex items-start justify-between gap-4 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                            <span>{error}</span>
                            <button onClick={() => setError(null)} className="shrink-0 text-rose-400 hover:text-rose-600" aria-label="Tutup">
                                <X size={16} />
                            </button>
                        </div>
                    )}

                    <div className="min-h-0 flex-1 overflow-auto">
                        <table className="w-full border-collapse text-left text-sm whitespace-nowrap">
                            <thead className="sticky top-0 z-10">
                                <tr>
                                    {COLUMNS.map((c) => (
                                        <th
                                            key={c}
                                            className="border-b border-slate-200 bg-slate-100 px-4 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase"
                                        >
                                            {c}
                                        </th>
                                    ))}
                                    <th className="border-b border-slate-200 bg-slate-100 px-4 py-3 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {filtered.map((r) => (
                                    <tr key={r.id} className="transition-colors hover:bg-brand-50/40">
                                        <td className="px-4 py-3 font-bold text-slate-700">{r.nrp}</td>
                                        <td className="px-4 py-3 font-bold text-slate-700">{r.name}</td>
                                        <td className="px-4 py-3 text-slate-600">{r.jabatan_name}</td>
                                        <td className="px-4 py-3 text-slate-600">{r.departemen_name}</td>
                                        <td className="px-4 py-3 text-slate-600">{r.work_location_name}</td>
                                        <td className="px-4 py-3 text-slate-600 tabular-nums">{fmtDate(r.tanggal_masuk)}</td>
                                        <td className="px-4 py-3 font-bold text-slate-700 tabular-nums">{fmtDate(r.tanggal_keluar)}</td>
                                        <td className="px-4 py-3 text-slate-600">{r.masa_kerja ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <span className="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                                                {r.alasan_keluar ?? '-'}
                                            </span>
                                        </td>
                                        <td className="max-w-xs truncate px-4 py-3 text-slate-500" title={r.keterangan_keluar ?? undefined}>
                                            {r.keterangan_keluar ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1">
                                                <Link
                                                    href={`/manpower/karyawan/${r.id}/profil`}
                                                    title="Lihat profil lengkap"
                                                    className="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <IdCard size={14} />
                                                </Link>
                                                <button
                                                    onClick={() => setRestoring(r)}
                                                    title="Aktifkan kembali (batalkan status keluar)"
                                                    className="inline-flex rounded-lg p-2 text-slate-400 transition-all hover:bg-emerald-50 hover:text-emerald-600"
                                                >
                                                    <RotateCcw size={14} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {filtered.length === 0 && (
                            <p className="py-20 text-center text-sm font-medium text-slate-400">
                                {rows.length === 0 ? 'Belum ada karyawan yang tercatat keluar.' : 'Tidak ada karyawan yang cocok.'}
                            </p>
                        )}
                    </div>
                </div>
            </div>

            {restoring && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
                    <div className="w-full max-w-sm overflow-hidden rounded-3xl bg-white p-6 text-center shadow-2xl">
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                            <RotateCcw size={22} />
                        </div>
                        <h3 className="mb-1 font-bold text-slate-800">Aktifkan Kembali?</h3>
                        <p className="mb-6 text-sm text-slate-500">
                            <span className="font-bold">{restoring.name}</span> akan kembali berstatus Aktif di jabatan{' '}
                            <span className="font-bold">{restoring.jabatan_name}</span>, dan catatan keluarnya ({restoring.alasan_keluar},{' '}
                            {fmtDate(restoring.tanggal_keluar)}) dihapus.
                        </p>
                        <div className="flex justify-center gap-3">
                            <button
                                onClick={() => setRestoring(null)}
                                className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                            >
                                Batal
                            </button>
                            <button
                                onClick={confirmRestore}
                                disabled={busy}
                                className="flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2 text-sm font-bold text-white transition-all hover:bg-emerald-700 disabled:opacity-50"
                            >
                                {busy ? <Loader2 className="animate-spin" size={16} /> : <RotateCcw size={16} />}
                                Aktifkan Kembali
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
