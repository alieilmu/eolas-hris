import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Check, Download, Loader2, Plus, Search, Trash2, X } from 'lucide-react';
import { AttendanceHeader, Modal, ghostButton, primaryButton, secondaryButton, smallInput } from '@/components/attendance/ui';
import { DateInput } from '@/components/DateInput';
import { fmtDate } from '@/lib/format';
import { BESAR_STATUS, KATEGORI_LABEL, gapok, itemWhen, type Besar, type BesarPeriode, type CutiItem, type Tahunan } from '@/lib/cuti';

interface Row {
    id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    departemen: string | null;
    work_location: string | null;
    sub_location: string | null;
    masuk: string | null;
    masa_kerja: string | null;
    tahunan: Tahunan;
    besar: Besar;
    pengambilan: CutiItem[];
}

interface Props {
    tab: 'tahunan' | 'besar';
    filters: { lokasi: number | null; sub: string; q: string; status: string };
    rows: Row[];
    locations: { id: number; name: string }[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    aturan: {
        jatah_tahunan: number;
        masa_aktif_tahun: number;
        besar_interval_tahun: number;
        besar_hari: number;
        besar_gapok_diambil: number;
        besar_gapok_diuangkan: number;
        besar_batas_bulan: number;
    };
    menunggu_besar: number;
    can_edit: boolean;
    today: string;
}

const visit = { preserveScroll: true, preserveState: true } as const;
const th = 'border-b border-slate-200 bg-slate-100 px-2 py-2 text-[10px] font-black tracking-wide text-slate-600 uppercase';
const td = 'border-b border-slate-100 px-2 py-1.5';

/**
 * Comben → Cuti Tahunan & Besar. Tahunan follows the old "SISA CUTI TAHUNAN" sheet:
 * one row per employee with the active balance and each leave as Hari | Tgl.
 */
export default function CutiPage({ tab, filters, rows, locations, sub_locations, aturan, menunggu_besar, can_edit, today }: Props) {
    const [search, setSearch] = useState(filters.q);
    const [detail, setDetail] = useState<number | null>(null);
    const first = useRef(true);

    const go = (next: Partial<Props['filters'] & { tab: Props['tab'] }>) => {
        const m = { tab, ...filters, q: search, ...next };
        const query: Record<string, string> = { tab: m.tab };
        if (m.lokasi) query.lokasi = String(m.lokasi);
        if (m.sub) query.sub = m.sub;
        if (m.q.trim()) query.q = m.q.trim();
        if (m.status && m.tab === 'besar') query.status = m.status;
        router.get('/comben/cuti', query, { ...visit, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => go({ q: search }), 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const exportUrl = () => {
        const q = new URLSearchParams();
        if (filters.lokasi) q.set('lokasi', String(filters.lokasi));
        if (filters.sub) q.set('sub', filters.sub);
        if (filters.q) q.set('q', filters.q);
        return `/comben/cuti/export?${q}`;
    };

    return (
        <>
            <Head title="Cuti Tahunan & Besar" />
            <AttendanceHeader
                title="Cuti Tahunan & Cuti Besar"
                subtitle={`Tahunan ${aturan.jatah_tahunan} hari/thn, berlaku ${aturan.masa_aktif_tahun} thn (FIFO) · Besar tiap ${aturan.besar_interval_tahun} thn: ${aturan.besar_hari} hari + ${gapok(aturan.besar_gapok_diambil)}, atau ${gapok(aturan.besar_gapok_diuangkan)}`}
            >
                <a href={exportUrl()} className={secondaryButton}>
                    <Download size={16} />
                    Export Excel
                </a>
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50 p-6">
                <div className="flex min-h-0 flex-1 flex-col rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4">
                        <div className="flex rounded-xl bg-slate-100 p-1">
                            {(
                                [
                                    ['tahunan', 'Cuti Tahunan'],
                                    ['besar', 'Cuti Besar'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    onClick={() => go({ tab: value })}
                                    className={`flex items-center gap-1.5 rounded-lg px-3.5 py-1 text-sm font-bold ${tab === value ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                                >
                                    {label}
                                    {value === 'besar' && menunggu_besar > 0 && (
                                        <span className="rounded-full bg-amber-500 px-1.5 text-[10px] text-white">{menunggu_besar}</span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <select
                            value={filters.lokasi ?? ''}
                            onChange={(e) => go({ lokasi: e.target.value ? Number(e.target.value) : null, sub: '' })}
                            className={smallInput}
                            aria-label="Lokasi"
                        >
                            <option value="">Semua lokasi</option>
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                        <select value={filters.sub} onChange={(e) => go({ sub: e.target.value })} className={smallInput} aria-label="Sub lokasi">
                            <option value="">Semua sub lokasi</option>
                            <option value="none">Tanpa sub lokasi</option>
                            {sub_locations
                                .filter((s) => !filters.lokasi || s.work_location_id === filters.lokasi)
                                .map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                        </select>
                        {tab === 'besar' && (
                            <select
                                value={filters.status}
                                onChange={(e) => go({ status: e.target.value })}
                                className={smallInput}
                                aria-label="Status cuti besar"
                            >
                                <option value="">Semua status</option>
                                {Object.entries(BESAR_STATUS).map(([v, s]) => (
                                    <option key={v} value={v}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        <div className="relative ml-auto">
                            <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama / NRP"
                                className={`${smallInput} w-56 pl-8`}
                            />
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-auto">
                        {tab === 'tahunan' ? (
                            <TahunanTable rows={rows} onOpen={setDetail} />
                        ) : (
                            <BesarTable rows={rows} canEdit={can_edit} onOpen={setDetail} today={today} />
                        )}
                    </div>
                    <div className="border-t border-slate-100 px-5 py-2 text-xs text-slate-400">
                        {rows.length} karyawan aktif · klik nama untuk rincian per tahun
                    </div>
                </div>
            </div>

            {detail !== null && <Detail id={detail} canEdit={can_edit} onClose={() => setDetail(null)} />}
        </>
    );
}

// --- Cuti Tahunan, in the layout of the old sheet ------------------------------------------

function TahunanTable({ rows, onOpen }: { rows: Row[]; onOpen: (id: number) => void }) {
    const taken = useMemo(() => rows.map((r) => r.pengambilan.filter((i) => i.potong > 0 && i.status !== 'ditolak')), [rows]);
    const pairs = Math.max(5, ...taken.map((t) => t.length));
    const sticky = 'sticky z-10 bg-white';

    return (
        <table className="min-w-full border-separate border-spacing-0 text-xs">
            <thead className="sticky top-0 z-20">
                <tr>
                    <th rowSpan={2} className={`${th} ${sticky} left-0 w-10 !bg-slate-100`}>
                        No
                    </th>
                    <th rowSpan={2} className={`${th} ${sticky} left-10 min-w-48 !bg-slate-100 text-left`}>
                        Nama Lengkap
                    </th>
                    <th rowSpan={2} className={th}>
                        NRP
                    </th>
                    <th rowSpan={2} className={`${th} text-left`}>
                        Departemen
                    </th>
                    <th rowSpan={2} className={th}>
                        Tgl. Masuk
                    </th>
                    <th rowSpan={2} className={th}>
                        Masa Kerja
                    </th>
                    <th rowSpan={2} className={`${th} !bg-emerald-100 text-emerald-800`}>
                        Sisa Cuti Tahunan Aktif
                    </th>
                    <th rowSpan={2} className={th}>
                        Total Cuti Diajukan
                    </th>
                    <th rowSpan={2} className={th}>
                        Hutang
                    </th>
                    <th rowSpan={2} className={th}>
                        Menunggu
                    </th>
                    <th rowSpan={2} className={th}>
                        Hangus 31.12
                    </th>
                    <th colSpan={pairs * 2} className={`${th} !bg-brand-600 text-white`}>
                        Pengambilan Cuti Tahunan
                    </th>
                </tr>
                <tr>
                    {Array.from({ length: pairs }, (_, i) => [
                        <th key={`h${i}`} className={`${th} w-8`}>
                            {i + 1}
                        </th>,
                        <th key={`t${i}`} className={`${th} min-w-40`}>
                            Tgl
                        </th>,
                    ])}
                </tr>
            </thead>
            <tbody>
                {rows.length === 0 && (
                    <tr>
                        <td colSpan={11 + pairs * 2} className="px-5 py-12 text-center text-sm text-slate-400">
                            Tidak ada karyawan.
                        </td>
                    </tr>
                )}
                {rows.map((r, n) => {
                    const t = r.tahunan;
                    return (
                        <tr key={r.id} className="group hover:bg-brand-50/30">
                            <td className={`${td} ${sticky} left-0 text-center text-slate-400 group-hover:bg-brand-50`}>{n + 1}</td>
                            <td className={`${td} ${sticky} left-10 group-hover:bg-brand-50`}>
                                <button
                                    onClick={() => onOpen(r.id)}
                                    className="text-left font-bold text-slate-700 hover:text-brand-600 hover:underline"
                                >
                                    {r.name}
                                </button>
                                <span className="block text-[10px] text-slate-400">
                                    {[r.work_location, r.sub_location].filter(Boolean).join(' · ')}
                                </span>
                            </td>
                            <td className={`${td} font-mono text-slate-600`}>{r.nrp}</td>
                            <td className={`${td} text-slate-600`}>{r.departemen ?? '-'}</td>
                            <td className={`${td} text-center whitespace-nowrap tabular-nums`}>{fmtDate(r.masuk) || '-'}</td>
                            <td className={`${td} whitespace-nowrap text-slate-500`}>{r.masa_kerja ?? '-'}</td>
                            <td className={`${td} bg-emerald-50/50 text-center`}>
                                {t.ada ? (
                                    <b className={`text-sm tabular-nums ${(t.saldo ?? 0) === 0 ? 'text-rose-600' : 'text-emerald-700'}`}>{t.saldo}</b>
                                ) : (
                                    <span className="text-[10px] text-amber-700" title={t.alasan}>
                                        Tgl masuk kosong
                                    </span>
                                )}
                            </td>
                            <td className={`${td} text-center tabular-nums`}>{t.diambil_total}</td>
                            <td className={`${td} text-center tabular-nums ${t.hutang ? 'font-bold text-rose-600' : 'text-slate-300'}`}>
                                {t.hutang || '-'}
                            </td>
                            <td className={`${td} text-center tabular-nums ${t.menunggu ? 'font-bold text-amber-700' : 'text-slate-300'}`}>
                                {t.menunggu || '-'}
                            </td>
                            <td className={`${td} text-center tabular-nums ${t.hangus_akhir_tahun ? 'text-amber-700' : 'text-slate-300'}`}>
                                {t.hangus_akhir_tahun || '-'}
                            </td>
                            {Array.from({ length: pairs }, (_, i) => {
                                const item = taken[n][i];
                                return [
                                    <td
                                        key={`h${i}`}
                                        className={`${td} text-center font-bold tabular-nums ${item?.status === 'diajukan' ? 'text-amber-700' : 'text-slate-700'}`}
                                    >
                                        {item?.potong ?? ''}
                                    </td>,
                                    <td
                                        key={`t${i}`}
                                        className={`${td} whitespace-nowrap ${item?.status === 'diajukan' ? 'text-amber-700' : 'text-slate-500'}`}
                                    >
                                        {item && (
                                            <>
                                                {itemWhen(item)}
                                                {item.sumber === 'trip' && item.jenis !== 'tahunan' && (
                                                    <span className="ml-1 text-[9px] text-slate-400">({KATEGORI_LABEL[item.jenis]})</span>
                                                )}
                                                {item.status === 'diajukan' && <span className="ml-1 text-[9px]">menunggu</span>}
                                            </>
                                        )}
                                    </td>,
                                ];
                            })}
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}

// --- Cuti Besar ------------------------------------------------------------------------------

function BesarTable({ rows, canEdit, onOpen, today }: { rows: Row[]; canEdit: boolean; onOpen: (id: number) => void; today: string }) {
    return (
        <table className="min-w-full text-left text-sm">
            <thead className="sticky top-0 z-10 bg-slate-50">
                <tr>
                    <th className={`${th} pl-5 text-left`}>Karyawan</th>
                    <th className={th}>Tgl Masuk</th>
                    <th className={`${th} text-left`}>Hak Cuti Besar</th>
                    <th className={th}>Berikutnya</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
                {rows.length === 0 && (
                    <tr>
                        <td colSpan={4} className="px-5 py-12 text-center text-sm text-slate-400">
                            Tidak ada karyawan.
                        </td>
                    </tr>
                )}
                {rows.map((r) => (
                    <tr key={r.id} className="align-top">
                        <td className="min-w-56 py-3 pr-3 pl-5">
                            <button onClick={() => onOpen(r.id)} className="text-left font-bold text-slate-700 hover:text-brand-600 hover:underline">
                                {r.name}
                            </button>
                            <p className="text-[11px] text-slate-400">
                                {r.nrp} · {r.masa_kerja ?? '-'}
                            </p>
                        </td>
                        <td className="px-3 py-3 text-xs whitespace-nowrap tabular-nums">{fmtDate(r.masuk) || '-'}</td>
                        <td className="px-3 py-3">
                            {r.besar.periode.length === 0 ? (
                                <span className="text-xs text-slate-400">Belum mencapai</span>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {r.besar.periode.map((p) => (
                                        <PeriodeCard key={p.periode_ke} karyawanId={r.id} p={p} canEdit={canEdit} today={today} />
                                    ))}
                                </div>
                            )}
                        </td>
                        <td className="px-3 py-3 text-xs whitespace-nowrap text-slate-500">
                            {r.besar.berikutnya ? (
                                <>
                                    {fmtDate(r.besar.berikutnya)}
                                    <span className="block text-[10px] text-slate-400">{r.besar.berikutnya_masa_kerja} thn</span>
                                </>
                            ) : (
                                '-'
                            )}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function PeriodeCard({ karyawanId, p, canEdit, today }: { karyawanId: number; p: BesarPeriode; canEdit: boolean; today: string }) {
    const s = BESAR_STATUS[p.status];
    const decide = (keputusan: 'diambil' | 'diuangkan' | null) => {
        const what = keputusan
            ? keputusan === 'diambil'
                ? `Diambil (${p.periode_ke}) — cuti + 1× gapok`
                : 'Diuangkan — 2× gapok'
            : 'kosongkan pilihan';
        if (confirm(`Cuti Besar ${p.masa_kerja} thn: ${what}?`))
            router.post(`/comben/cuti/karyawan/${karyawanId}/besar`, { periode_ke: p.periode_ke, keputusan }, visit);
    };
    const nearDeadline = p.status === 'belum_dipilih' && p.batas <= addDays(today, 60);
    return (
        <div className="w-60 rounded-2xl border border-slate-200 p-2.5 text-xs">
            <div className="flex items-center justify-between gap-2">
                <b className="text-slate-700">
                    Ke-{p.periode_ke} · {p.masa_kerja} thn
                </b>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${s.className}`}>{s.label}</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
                Hak {fmtDate(p.tanggal_hak)} · pilih s/d <span className={nearDeadline ? 'font-bold text-rose-600' : ''}>{fmtDate(p.batas)}</span>
            </p>
            {p.status === 'diambil' && (
                <p className="text-[11px] text-emerald-700">
                    Sisa <b>{p.sisa}</b> / {p.hari} hari{p.menunggu ? ` · ${p.menunggu} menunggu` : ''} · {gapok(p.gapok)}
                </p>
            )}
            {(p.status === 'diuangkan' || p.status === 'otomatis_diuangkan') && (
                <p className="text-[11px] text-violet-700">Kompensasi {gapok(p.gapok)}</p>
            )}
            {p.status === 'menunggu' && (
                <p className="text-[11px] text-sky-700">Karyawan memilih: {p.keputusan === 'diambil' ? 'Diambil' : 'Diuangkan'}</p>
            )}
            {canEdit && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {p.status === 'menunggu' && p.id ? (
                        <>
                            <VerifyBesar id={p.id} />
                        </>
                    ) : (
                        <>
                            {p.status !== 'diambil' && (
                                <SmallButton tone="emerald" onClick={() => decide('diambil')}>
                                    Ambil
                                </SmallButton>
                            )}
                            {p.status !== 'diuangkan' && (
                                <SmallButton tone="violet" onClick={() => decide('diuangkan')}>
                                    Uangkan
                                </SmallButton>
                            )}
                            {(p.status === 'diambil' || p.status === 'diuangkan') && (
                                <SmallButton tone="slate" onClick={() => decide(null)}>
                                    Kosongkan
                                </SmallButton>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

/** Confirms or rejects a choice sent from the portal. */
function VerifyBesar({ id }: { id: number }) {
    const send = (keputusan: 'setujui' | 'tolak') => {
        let catatan: string | null = null;
        if (keputusan === 'tolak') {
            catatan = prompt('Alasan menolak pilihan Cuti Besar ini:')?.trim() || null;
            if (!catatan) return;
        } else if (!confirm('Setujui pilihan Cuti Besar karyawan ini?')) return;
        router.post(`/comben/cuti/besar/${id}/verifikasi`, { keputusan, catatan }, visit);
    };
    return (
        <>
            <SmallButton tone="emerald" onClick={() => send('setujui')}>
                <Check size={12} /> Setujui
            </SmallButton>
            <SmallButton tone="rose" onClick={() => send('tolak')}>
                <X size={12} /> Tolak
            </SmallButton>
        </>
    );
}

function SmallButton({
    tone,
    onClick,
    children,
    disabled,
}: {
    tone: 'emerald' | 'violet' | 'slate' | 'rose';
    onClick: () => void;
    children: React.ReactNode;
    disabled?: boolean;
}) {
    const tones = {
        emerald: 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100',
        violet: 'bg-violet-50 text-violet-700 hover:bg-violet-100',
        slate: 'bg-slate-100 text-slate-600 hover:bg-slate-200',
        rose: 'bg-rose-50 text-rose-700 hover:bg-rose-100',
    };
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-bold disabled:opacity-50 ${tones[tone]}`}
        >
            {children}
        </button>
    );
}

const addDays = (iso: string, n: number) => {
    const d = new Date(`${iso}T00:00:00`);
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
};

// --- one employee ------------------------------------------------------------------------------

interface DetailData {
    karyawan: { id: number; nrp: string; name: string; jabatan: string | null };
    tahunan: Tahunan;
    besar: Besar;
    pengambilan: CutiItem[];
    lapangan: { cuti: number; berangkat: number; kembali: number; total: number } | null;
}

function Detail({ id, canEdit, onClose }: { id: number; canEdit: boolean; onClose: () => void }) {
    const [data, setData] = useState<DetailData | null>(null);
    const [version, setVersion] = useState(0);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ tanggal_awal: '', tanggal_akhir: '', hari: '', catatan: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        fetch(`/comben/cuti/karyawan/${id}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then(setData);
    }, [id, version]);

    const reload = () => setVersion((v) => v + 1);
    const days =
        form.tanggal_awal && form.tanggal_akhir ? Math.round((Date.parse(form.tanggal_akhir) - Date.parse(form.tanggal_awal)) / 86400000) + 1 : null;

    const save = () =>
        router.post(
            `/comben/cuti/karyawan/${id}/pengambilan`,
            { jenis: 'tahunan', ...form, tanggal_akhir: form.tanggal_akhir || null, hari: form.hari || days || '' },
            {
                ...visit,
                onError: setErrors,
                onSuccess: () => {
                    setAdding(false);
                    setForm({ tanggal_awal: '', tanggal_akhir: '', hari: '', catatan: '' });
                    setErrors({});
                    reload();
                },
            },
        );

    const remove = (item: CutiItem) => {
        if (item.id && confirm(`Hapus catatan cuti ${item.hari} hari (${itemWhen(item)})?`))
            router.delete(`/comben/cuti/pengambilan/${item.id}`, { ...visit, onSuccess: reload });
    };

    const t = data?.tahunan;

    return (
        <Modal
            title={data ? `Cuti — ${data.karyawan.name}` : 'Memuat…'}
            subtitle={data ? `${data.karyawan.nrp} · ${data.karyawan.jabatan ?? ''}` : undefined}
            onClose={onClose}
            width="max-w-4xl"
        >
            {!data ? (
                <div className="flex justify-center py-10">
                    <Loader2 className="animate-spin text-slate-400" />
                </div>
            ) : (
                <div className="space-y-5">
                    {t && !t.ada && (
                        <p className="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            <AlertTriangle size={15} /> {t.alasan}
                        </p>
                    )}
                    {t?.ada && (
                        <div className="grid gap-3 sm:grid-cols-4">
                            <Stat label="Sisa cuti aktif" value={t.saldo} tone="text-emerald-700" />
                            <Stat label="Hutang" value={t.hutang} tone={t.hutang ? 'text-rose-600' : 'text-slate-400'} />
                            <Stat label="Menunggu persetujuan" value={t.menunggu} tone={t.menunggu ? 'text-amber-700' : 'text-slate-400'} />
                            <Stat label="Jatah berikutnya masuk" value={fmtDate(t.hak_berikutnya)} tone="text-slate-500 !text-lg" />
                        </div>
                    )}
                    {data.lapangan && (
                        <p className="text-xs text-slate-500">
                            Cuti lapangan lokasi ini: {data.lapangan.cuti} hari + {data.lapangan.berangkat} berangkat + {data.lapangan.kembali}{' '}
                            kembali = <b>{data.lapangan.total} hari</b>; lebihnya memotong cuti tahunan.
                        </p>
                    )}

                    {t?.tahun && t.tahun.length > 0 && (
                        <div className="overflow-x-auto rounded-2xl border border-slate-200">
                            <table className="w-full text-center text-xs">
                                <thead className="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-2 py-2 text-left">Tahun</th>
                                        {t.tahun.map((y) => (
                                            <th key={y.tahun} className="px-2 py-2">
                                                {y.tahun}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {(
                                        [
                                            ['jatah', 'Jatah'],
                                            ['diambil', 'Diambil'],
                                            ['sisa', 'Sisa aktif'],
                                            ['hutang', 'Hutang'],
                                        ] as const
                                    ).map(([k, label]) => (
                                        <tr key={k} className="border-t border-slate-100">
                                            <td className="px-2 py-1.5 text-left font-bold text-slate-600">{label}</td>
                                            {t.tahun!.map((y) => (
                                                <td
                                                    key={y.tahun}
                                                    className={`px-2 py-1.5 tabular-nums ${k === 'hutang' && y.hutang ? 'font-bold text-rose-600' : k === 'sisa' ? 'font-bold text-emerald-700' : 'text-slate-600'}`}
                                                >
                                                    {y[k]}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-700">Riwayat pengambilan ({data.pengambilan.length})</h3>
                            {canEdit && !adding && (
                                <button onClick={() => setAdding(true)} className={`${ghostButton} flex items-center gap-1.5 text-brand-600`}>
                                    <Plus size={14} /> Catat cuti manual
                                </button>
                            )}
                        </div>
                        {adding && (
                            <div className="mb-3 grid gap-2 rounded-2xl border border-brand-100 bg-brand-50/40 p-3 sm:grid-cols-[150px_150px_90px_1fr_auto]">
                                <DateInput
                                    value={form.tanggal_awal}
                                    onChange={(v) => setForm((f) => ({ ...f, tanggal_awal: v }))}
                                    className={smallInput}
                                    aria-label="Tanggal awal"
                                />
                                <DateInput
                                    value={form.tanggal_akhir}
                                    onChange={(v) => setForm((f) => ({ ...f, tanggal_akhir: v }))}
                                    min={form.tanggal_awal || undefined}
                                    className={smallInput}
                                    aria-label="Tanggal akhir"
                                />
                                <input
                                    value={form.hari}
                                    onChange={(e) => setForm((f) => ({ ...f, hari: e.target.value.replace(/\D/g, '') }))}
                                    placeholder={days ? `${days} hari` : 'Hari'}
                                    className={smallInput}
                                    aria-label="Jumlah hari"
                                />
                                <input
                                    value={form.catatan}
                                    onChange={(e) => setForm((f) => ({ ...f, catatan: e.target.value }))}
                                    placeholder="Catatan (opsional)"
                                    className={smallInput}
                                />
                                <div className="flex gap-1">
                                    <button onClick={save} className={primaryButton}>
                                        Simpan
                                    </button>
                                    <button onClick={() => setAdding(false)} className={ghostButton}>
                                        Batal
                                    </button>
                                </div>
                                {Object.values(errors).length > 0 && (
                                    <p className="text-xs text-rose-600 sm:col-span-5">{Object.values(errors).join(' ')}</p>
                                )}
                            </div>
                        )}
                        <div className="max-h-72 overflow-y-auto rounded-2xl border border-slate-200">
                            <table className="w-full text-left text-xs">
                                <thead className="sticky top-0 bg-slate-50 text-[10px] font-bold text-slate-500 uppercase">
                                    <tr>
                                        <th className="px-3 py-2">Tahun</th>
                                        <th className="px-3 py-2">Tanggal</th>
                                        <th className="px-3 py-2">Jenis</th>
                                        <th className="px-3 py-2 text-right">Hari</th>
                                        <th className="px-3 py-2 text-right">Potong tahunan</th>
                                        <th className="px-3 py-2">Sumber</th>
                                        <th className="w-8" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {data.pengambilan.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-3 py-6 text-center text-slate-400">
                                                Belum ada pengambilan cuti.
                                            </td>
                                        </tr>
                                    )}
                                    {[...data.pengambilan].reverse().map((i, n) => (
                                        <tr key={n} className={i.status === 'diajukan' ? 'bg-amber-50/50' : ''}>
                                            <td className="px-3 py-1.5 tabular-nums">{i.tahun}</td>
                                            <td className="px-3 py-1.5">{itemWhen(i)}</td>
                                            <td className="px-3 py-1.5">
                                                {KATEGORI_LABEL[i.jenis] ?? i.jenis}
                                                {i.status === 'diajukan' && <span className="ml-1 text-amber-700">(menunggu)</span>}
                                            </td>
                                            <td className="px-3 py-1.5 text-right tabular-nums">{i.hari}</td>
                                            <td className="px-3 py-1.5 text-right font-bold tabular-nums">{i.potong}</td>
                                            <td className="px-3 py-1.5 text-slate-500">
                                                {{ import: 'Excel lama', manual: 'Manual HR', trip: 'Trip & Leave' }[i.sumber]}
                                            </td>
                                            <td className="px-1 py-1.5">
                                                {canEdit && i.id && (
                                                    <button
                                                        onClick={() => remove(i)}
                                                        title="Hapus"
                                                        className="rounded p-1 text-slate-300 hover:bg-rose-50 hover:text-rose-600"
                                                    >
                                                        <Trash2 size={13} />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </Modal>
    );
}

function Stat({ label, value, tone }: { label: string; value: number | string | undefined; tone: string }) {
    return (
        <div className="rounded-2xl border border-slate-200 px-4 py-3">
            <p className="text-[11px] font-bold text-slate-400">{label}</p>
            <p className={`text-2xl font-black tabular-nums ${tone}`}>{value ?? '-'}</p>
        </div>
    );
}
