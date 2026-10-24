import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CircleSlash,
    Clock,
    Cpu,
    FileUp,
    Fingerprint,
    Loader2,
    LogIn,
    LogOut,
    Pencil,
    Play,
    Plus,
    Search,
    Timer,
    Trash2,
    Wifi,
    WifiOff,
    X,
} from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, smallInput } from '@/components/attendance/ui';

type Tab = 'resume' | 'lembur' | 'log' | 'mesin';

interface Option {
    id: number;
    label: string;
}

interface ResumeRow {
    karyawan_id: number;
    nrp: string;
    nama: string;
    lokasi: string | null;
    tanggal: string;
    roster: string | null;
    roster_jenis: string | null;
    jadwal: string | null;
    ci: string | null;
    co: string | null;
    jumlah_log: number;
    koreksi: boolean;
    status: string;
    terlambat: number;
    pulang_cepat: number;
    menit_kerja: number;
    jenis_hari: 'kerja' | 'istirahat' | 'libur';
    libur: boolean;
    berhak_lembur: boolean;
    lembur: { menit_lembur: number; jam_dibayar: number; jam_terhitung: number; rincian: { jam: number; kali: number }[] } | null;
    absensi: string | null;
    absensi_sumber: string | null;
}

interface LemburRow {
    id: number;
    tanggal: string;
    nrp: string;
    nama: string;
    lokasi: string | null;
    jenis_hari: 'kerja' | 'istirahat' | 'libur';
    mulai: string | null;
    selesai: string | null;
    jam_kerja: number;
    jam_dibayar: number;
    jam_terhitung: number;
    status: 'menunggu' | 'disetujui' | 'ditolak';
    catatan_verifikasi: string | null;
    verifier: string | null;
}

interface LogRow {
    id: number;
    waktu: string;
    pin: string;
    nrp: string | null;
    nama: string | null;
    mesin: string | null;
    sumber: 'mesin' | 'import' | 'manual';
    catatan: string | null;
}

interface MesinRow {
    id: number;
    nama: string;
    serial_number: string;
    merek: string | null;
    model: string | null;
    work_location_id: number | null;
    lokasi: string | null;
    status: 'aktif' | 'nonaktif' | 'baru';
    online: boolean;
    last_seen_at: string | null;
    last_ip: string | null;
    log_hari_ini: number;
    log_terakhir: string | null;
    catatan: string | null;
}

interface Props {
    tab: Tab;
    filters: { dari: string; sampai: string; lokasi: number | null; q: string; status: string };
    locations: { id: number; name: string }[];
    status_labels: Record<string, string>;
    can: { edit: boolean; approve: boolean };
    mesin_baru: number;
    // resume
    summary?: Record<string, number | null>;
    rows?: ResumeRow[];
    total_rows?: number;
    karyawan_options?: Option[];
    // lembur
    lembur_summary?: { key: string; label: string; n: number; jam: number; terhitung: number }[];
    lemburs?: LemburRow[];
    lembur_status?: string;
    // log
    logs?: LogRow[];
    mesin_options?: { id: number; nama: string }[];
    // mesin
    mesins?: MesinRow[];
    work_locations?: { id: number; name: string }[];
    server?: { host: string; path: string };
}

const URL = '/attendance/kehadiran';
const WEEKDAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const STATUS_TONE: Record<string, string> = {
    lengkap: 'bg-brand-50 text-brand-700',
    tanpa_co: 'bg-amber-100 text-amber-700',
    tanpa_ci: 'bg-amber-100 text-amber-700',
    tidak_hadir: 'bg-rose-100 text-rose-600',
    tidak_sesuai: 'bg-violet-100 text-violet-700',
    off: 'bg-slate-100 text-slate-500',
    tanpa_roster: 'bg-slate-100 text-slate-400',
    dinas: 'bg-sky-100 text-sky-700',
    cuti: 'bg-teal-100 text-teal-700',
    sakit: 'bg-orange-100 text-orange-700',
    izin: 'bg-orange-100 text-orange-700',
};
const LEMBUR_TONE = { menunggu: 'bg-amber-100 text-amber-700', disetujui: 'bg-brand-100 text-brand-700', ditolak: 'bg-rose-100 text-rose-600' };
const HARI_LABEL = { kerja: 'Hari kerja', istirahat: 'Hari OFF', libur: 'Libur nasional' };
const jam = (n: number) => `${String(Math.round(n * 100) / 100).replace('.', ',')} j`;
const menit = (m: number) => (m >= 60 ? `${Math.floor(m / 60)}j ${m % 60}m` : `${m}m`);
const tgl = (iso: string) => {
    const d = new Date(`${iso}T00:00:00`);
    return `${WEEKDAY[d.getDay()]}, ${d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })}`;
};

export default function Kehadiran(props: Props) {
    const { tab, filters, locations, can } = props;
    const [q, setQ] = useState(filters.q);
    const [busy, setBusy] = useState(false);

    const go = (patch: Record<string, string | number | null>) => {
        const params = { tab, dari: filters.dari, sampai: filters.sampai, lokasi: filters.lokasi ?? '', q: filters.q, status: filters.status, ...patch };
        const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null)) as Record<string, string>;
        router.get(URL, clean, { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };
    const proses = () =>
        router.post(
            `${URL}/proses`,
            { dari: filters.dari, sampai: filters.sampai, lokasi: filters.lokasi ?? '' },
            { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) },
        );

    const tabs: { id: Tab; label: string; icon: ReactNode; badge?: number }[] = [
        { id: 'resume', label: 'Resume Absensi', icon: <Fingerprint size={15} /> },
        { id: 'lembur', label: 'Lembur', icon: <Timer size={15} /> },
        { id: 'log', label: 'Log Absen', icon: <Clock size={15} /> },
        { id: 'mesin', label: 'Mesin Absen', icon: <Cpu size={15} />, badge: props.mesin_baru },
    ];

    return (
        <>
            <Head title="Kehadiran" />
            <AttendanceHeader title="Kehadiran" subtitle="Clock in / clock out dari mesin absen, dibandingkan dengan roster">
                {busy && <Loader2 size={18} className="animate-spin text-brand-600" />}
                {can.edit && tab !== 'mesin' && (
                    <button onClick={proses} className={primaryButton} title="Hitung ulang Absensi & Lembur dari CI/CO untuk rentang ini">
                        <Play size={15} /> Proses ke Absensi
                    </button>
                )}
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-6 py-2.5">
                    <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
                        {tabs.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => go({ tab: t.id, status: t.id === 'lembur' ? 'menunggu' : 'masalah' })}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold transition ${
                                    tab === t.id ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                {t.icon}
                                {t.label}
                                {!!t.badge && <span className="rounded-full bg-amber-400 px-1.5 text-[10px] text-white">{t.badge}</span>}
                            </button>
                        ))}
                    </div>
                    {tab !== 'mesin' && (
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <input type="date" value={filters.dari} onChange={(e) => e.target.value && go({ dari: e.target.value })} className={smallInput} />
                            <span className="text-xs text-slate-400">s/d</span>
                            <input type="date" value={filters.sampai} onChange={(e) => e.target.value && go({ sampai: e.target.value })} className={smallInput} />
                            {locations.length > 1 && (
                                <select value={filters.lokasi ?? ''} onChange={(e) => go({ lokasi: e.target.value || null })} className={smallInput}>
                                    <option value="">Semua lokasi</option>
                                    {locations.map((l) => (
                                        <option key={l.id} value={l.id}>
                                            {l.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    go({ q });
                                }}
                                className="relative"
                            >
                                <Search size={14} className="absolute top-2.5 left-2.5 text-slate-400" />
                                <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama / NRP" className={`${smallInput} w-44 pl-8`} />
                            </form>
                        </div>
                    )}
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-6">
                    {tab === 'resume' && <ResumeTab {...props} go={go} />}
                    {tab === 'lembur' && <LemburTab {...props} go={go} />}
                    {tab === 'log' && <LogTab {...props} />}
                    {tab === 'mesin' && <MesinTab {...props} />}
                </div>
            </div>
        </>
    );
}

// --- Resume -----------------------------------------------------------------------------------

function ResumeTab({ summary = {}, rows = [], total_rows = 0, filters, status_labels, can, karyawan_options = [], go }: Props & { go: (p: Record<string, string | number | null>) => void }) {
    const [koreksi, setKoreksi] = useState<{ karyawan_id: number; waktu: string } | null>(null);
    const cards: { key: string; label: string; value: number | string | null | undefined; tone: string; icon: ReactNode }[] = [
        { key: 'semua', label: 'Kepatuhan CI/CO', value: summary.kepatuhan !== null && summary.kepatuhan !== undefined ? `${String(summary.kepatuhan).replace('.', ',')}%` : '–', tone: 'text-brand-700', icon: <Check size={15} /> },
        { key: 'lengkap', label: 'Lengkap', value: summary.lengkap, tone: 'text-brand-700', icon: <Fingerprint size={15} /> },
        { key: 'tanpa_ci', label: 'Tidak ada CI', value: summary.tanpa_ci, tone: 'text-amber-600', icon: <LogIn size={15} /> },
        { key: 'tanpa_co', label: 'Tidak ada CO', value: summary.tanpa_co, tone: 'text-amber-600', icon: <LogOut size={15} /> },
        { key: 'tidak_hadir', label: 'Tidak hadir', value: summary.tidak_hadir, tone: 'text-rose-600', icon: <CircleSlash size={15} /> },
        { key: 'tidak_sesuai', label: 'Tidak sesuai roster', value: summary.tidak_sesuai, tone: 'text-violet-700', icon: <AlertTriangle size={15} /> },
        { key: 'terlambat', label: 'Terlambat', value: summary.terlambat, tone: 'text-orange-600', icon: <Clock size={15} /> },
        { key: 'lembur', label: 'Jam lembur', value: summary.jam_lembur !== undefined && summary.jam_lembur !== null ? jam(Number(summary.jam_lembur)) : '–', tone: 'text-teal-700', icon: <Timer size={15} /> },
    ];
    const filterOptions = [
        ['masalah', 'Perlu perhatian'],
        ['semua', 'Semua hari'],
        ...Object.entries(status_labels),
        ['terlambat', 'Terlambat'],
        ['pulang_cepat', 'Pulang cepat'],
        ['lembur', 'Ada lembur'],
    ];

    return (
        <div className="space-y-5">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                {cards.map((c) => (
                    <button
                        key={c.key}
                        onClick={() => go({ status: c.key })}
                        className={`rounded-2xl border bg-white p-3 text-left shadow-sm transition hover:border-brand-200 ${filters.status === c.key ? 'border-brand-400 ring-2 ring-brand-100' : 'border-slate-100'}`}
                    >
                        <span className={`flex items-center gap-1.5 text-[11px] font-bold ${c.tone}`}>
                            {c.icon}
                            {c.label}
                        </span>
                        <span className="mt-1 block text-2xl font-black text-slate-800">{c.value ?? 0}</span>
                    </button>
                ))}
            </div>

            <div className="rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                    <span className="text-sm font-bold text-slate-700">
                        {total_rows.toLocaleString('id-ID')} hari
                        {total_rows > rows.length && <span className="ml-1 text-xs font-normal text-slate-400">(ditampilkan {rows.length})</span>}
                    </span>
                    <select value={filters.status} onChange={(e) => go({ status: e.target.value })} className={`${smallInput} ml-auto`}>
                        {filterOptions.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                    {can.edit && (
                        <button onClick={() => setKoreksi({ karyawan_id: 0, waktu: '' })} className={ghostButton}>
                            <span className="flex items-center gap-1.5">
                                <Plus size={14} /> Koreksi log
                            </span>
                        </button>
                    )}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className="px-3 py-2">Tanggal</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2">Roster</th>
                                <th className="px-3 py-2">CI</th>
                                <th className="px-3 py-2">CO</th>
                                <th className="px-3 py-2">Jam kerja</th>
                                <th className="px-3 py-2">Terlambat / cepat</th>
                                <th className="px-3 py-2">Lembur</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Absensi</th>
                                {can.edit && <th className="px-3 py-2" />}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={`${r.karyawan_id}-${r.tanggal}`} className="border-t border-slate-100 align-top hover:bg-slate-50/60">
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">
                                        {tgl(r.tanggal)}
                                        {r.libur && <span className="ml-1 rounded bg-rose-50 px-1 text-[10px] font-bold text-rose-500">libur</span>}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="block font-semibold text-slate-700">{r.nama}</span>
                                        <span className="text-[11px] text-slate-400">
                                            {r.nrp} · {r.lokasi}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <span className="font-bold text-slate-700">{r.roster ?? '–'}</span>
                                        {r.jadwal && <span className="block text-[11px] text-slate-400">{r.jadwal}</span>}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">{r.ci ?? <span className="text-rose-400">—</span>}</td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                        {r.co ?? <span className="text-rose-400">—</span>}
                                        {r.koreksi && <span className="ml-1 rounded bg-sky-50 px-1 font-sans text-[10px] font-bold text-sky-600">koreksi</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap text-slate-600">{r.menit_kerja ? menit(r.menit_kerja) : '–'}</td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap">
                                        {r.terlambat > 0 && <span className="block text-orange-600">+{menit(r.terlambat)} telat</span>}
                                        {r.pulang_cepat > 0 && <span className="block text-orange-600">−{menit(r.pulang_cepat)} cepat</span>}
                                        {!r.terlambat && !r.pulang_cepat && <span className="text-slate-300">–</span>}
                                    </td>
                                    <td className="px-3 py-2 text-xs whitespace-nowrap">
                                        {r.lembur && r.lembur.menit_lembur > 0 ? (
                                            r.berhak_lembur ? (
                                                <span title={r.lembur.rincian.map((x) => `${x.jam} j × ${x.kali}`).join(' + ')}>
                                                    <b className="text-teal-700">{jam(r.lembur.jam_dibayar)}</b>
                                                    <span className="block text-[10px] text-slate-400">
                                                        {HARI_LABEL[r.jenis_hari]} · terhitung {jam(r.lembur.jam_terhitung)}
                                                    </span>
                                                </span>
                                            ) : (
                                                <span className="text-slate-400" title="SubGroup ini tidak berhak upah lembur di lokasi ini">
                                                    tidak berhak
                                                </span>
                                            )
                                        ) : (
                                            <span className="text-slate-300">–</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap ${STATUS_TONE[r.status] ?? 'bg-slate-100'}`}>
                                            {status_labels[r.status] ?? r.status}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-500">
                                        {r.absensi ?? '–'}
                                        {r.absensi_sumber && <span className="block text-[10px] text-slate-400">{r.absensi_sumber}</span>}
                                    </td>
                                    {can.edit && (
                                        <td className="px-3 py-2">
                                            {['tanpa_ci', 'tanpa_co', 'tidak_hadir', 'tidak_sesuai'].includes(r.status) && (
                                                <button
                                                    onClick={() => setKoreksi({ karyawan_id: r.karyawan_id, waktu: `${r.tanggal}T${r.status === 'tanpa_ci' ? '07:00' : '17:00'}` })}
                                                    title="Tambah log koreksi"
                                                    className="rounded-lg p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <Pencil size={14} />
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={11} className="px-4 py-10 text-center text-sm text-slate-400">
                                        Tidak ada hari dengan status ini pada rentang yang dipilih.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
            {koreksi && <KoreksiModal initial={koreksi} options={karyawan_options} onClose={() => setKoreksi(null)} />}
        </div>
    );
}

function KoreksiModal({ initial, options, onClose }: { initial: { karyawan_id: number; waktu: string }; options: Option[]; onClose: () => void }) {
    const form = useForm({ karyawan_id: initial.karyawan_id || '', waktu: initial.waktu, catatan: '' });
    const submit = (e?: FormEvent) => {
        e?.preventDefault();
        form.post(`${URL}/log`, { preserveScroll: true, onSuccess: onClose });
    };
    return (
        <Modal
            title="Koreksi log absen"
            subtitle="Tambahkan CI/CO yang terlewat (mis. lupa absen). Tercatat sebagai koreksi HR dengan alasannya."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => submit()} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label="Karyawan">
                    <select value={form.data.karyawan_id} onChange={(e) => form.setData('karyawan_id', e.target.value)} className={inputClass}>
                        <option value="">Pilih karyawan…</option>
                        {options.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    {form.errors.karyawan_id && <p className="mt-1 text-xs text-rose-600">{form.errors.karyawan_id}</p>}
                </Field>
                <Field label="Waktu (jam mesin)">
                    <input type="datetime-local" value={form.data.waktu} onChange={(e) => form.setData('waktu', e.target.value)} className={inputClass} />
                    {form.errors.waktu && <p className="mt-1 text-xs text-rose-600">{form.errors.waktu}</p>}
                </Field>
                <Field label="Alasan koreksi">
                    <input value={form.data.catatan} onChange={(e) => form.setData('catatan', e.target.value)} placeholder="mis. Lupa CO, mesin mati" className={inputClass} />
                    {form.errors.catatan && <p className="mt-1 text-xs text-rose-600">{form.errors.catatan}</p>}
                </Field>
            </form>
        </Modal>
    );
}

// --- Lembur -----------------------------------------------------------------------------------

function LemburTab({ lembur_summary = [], lemburs = [], lembur_status = 'menunggu', can, go }: Props & { go: (p: Record<string, string | number | null>) => void }) {
    const [selected, setSelected] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const selectable = lemburs.filter((l) => l.status === 'menunggu').map((l) => l.id);
    const all = selectable.length > 0 && selectable.every((id) => selected.includes(id));
    const total = useMemo(() => lemburs.reduce((s, l) => ({ dibayar: s.dibayar + l.jam_dibayar, terhitung: s.terhitung + l.jam_terhitung }), { dibayar: 0, terhitung: 0 }), [lemburs]);

    const decide = (ids: number[], keputusan: 'disetujui' | 'ditolak' | 'menunggu') => {
        let catatan: string | null = null;
        if (keputusan === 'ditolak') {
            catatan = prompt('Alasan penolakan:');
            if (catatan === null) return;
        }
        router.post(`${URL}/lembur/verifikasi`, { ids, keputusan, catatan } as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setSelected([]),
        });
    };

    return (
        <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-3">
                {lembur_summary.map((s) => (
                    <button
                        key={s.key}
                        onClick={() => go({ status: s.key })}
                        className={`rounded-2xl border bg-white p-4 text-left shadow-sm ${lembur_status === s.key ? 'border-brand-400 ring-2 ring-brand-100' : 'border-slate-100'}`}
                    >
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${LEMBUR_TONE[s.key as keyof typeof LEMBUR_TONE]}`}>{s.label}</span>
                        <span className="mt-2 block text-2xl font-black text-slate-800">{s.n.toLocaleString('id-ID')} hari</span>
                        <span className="text-xs text-slate-500">
                            {jam(s.jam)} dibayar · {jam(s.terhitung)} terhitung
                        </span>
                    </button>
                ))}
            </div>
            <p className="rounded-xl bg-brand-50/60 px-4 py-2.5 text-xs text-slate-600">
                <b>Jam terhitung</b> = jam lembur × pengali PP 35/2021 (hari kerja: jam ke-1 × 1,5, berikutnya × 2; hari OFF/libur: × 2 / × 3 / × 4). Upah lembur = jam
                terhitung × 1/173 × (gaji pokok + tunjangan tetap), dihitung di Payroll. Hanya lembur <b>disetujui</b> yang dibayar.
            </p>

            <div className="rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
                    <span className="text-sm font-bold text-slate-700">
                        {lemburs.length.toLocaleString('id-ID')} hari · {jam(total.dibayar)} · terhitung {jam(total.terhitung)}
                    </span>
                    <select value={lembur_status} onChange={(e) => go({ status: e.target.value })} className={`${smallInput} ml-auto`}>
                        <option value="menunggu">Menunggu</option>
                        <option value="disetujui">Disetujui</option>
                        <option value="ditolak">Ditolak</option>
                        <option value="semua">Semua</option>
                    </select>
                    {can.approve && selected.length > 0 && (
                        <>
                            <button onClick={() => decide(selected, 'disetujui')} disabled={busy} className={primaryButton}>
                                <Check size={15} /> Setujui {selected.length}
                            </button>
                            <button onClick={() => decide(selected, 'ditolak')} disabled={busy} className="flex items-center gap-1.5 rounded-xl bg-rose-50 px-4 py-2 text-sm font-bold text-rose-600 hover:bg-rose-100">
                                <X size={15} /> Tolak
                            </button>
                        </>
                    )}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                {can.approve && (
                                    <th className="w-8 px-3 py-2">
                                        <input type="checkbox" checked={all} onChange={(e) => setSelected(e.target.checked ? selectable : [])} disabled={!selectable.length} />
                                    </th>
                                )}
                                <th className="px-3 py-2">Tanggal</th>
                                <th className="px-3 py-2">Karyawan</th>
                                <th className="px-3 py-2">Jenis hari</th>
                                <th className="px-3 py-2">CI – CO</th>
                                <th className="px-3 py-2">Jam kerja</th>
                                <th className="px-3 py-2">Lembur</th>
                                <th className="px-3 py-2">Terhitung</th>
                                <th className="px-3 py-2">Status</th>
                                {can.approve && <th className="px-3 py-2" />}
                            </tr>
                        </thead>
                        <tbody>
                            {lemburs.map((l) => (
                                <tr key={l.id} className="border-t border-slate-100 hover:bg-slate-50/60">
                                    {can.approve && (
                                        <td className="px-3 py-2">
                                            {l.status === 'menunggu' && (
                                                <input
                                                    type="checkbox"
                                                    checked={selected.includes(l.id)}
                                                    onChange={(e) => setSelected((prev) => (e.target.checked ? [...prev, l.id] : prev.filter((id) => id !== l.id)))}
                                                />
                                            )}
                                        </td>
                                    )}
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{tgl(l.tanggal)}</td>
                                    <td className="px-3 py-2">
                                        <span className="block font-semibold text-slate-700">{l.nama}</span>
                                        <span className="text-[11px] text-slate-400">
                                            {l.nrp} · {l.lokasi}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-600">{HARI_LABEL[l.jenis_hari]}</td>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                        {l.mulai ?? '–'} – {l.selesai ?? '–'}
                                    </td>
                                    <td className="px-3 py-2 text-xs">{jam(l.jam_kerja)}</td>
                                    <td className="px-3 py-2 font-bold text-teal-700">
                                        {jam(l.jam_dibayar)}
                                        {l.jenis_hari === 'kerja' && l.jam_dibayar > 4 && (
                                            <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] font-bold text-amber-700" title="Melebihi batas wajar 4 jam/hari (PP 35/2021)">
                                                &gt;4j
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-slate-600">{jam(l.jam_terhitung)}</td>
                                    <td className="px-3 py-2">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${LEMBUR_TONE[l.status]}`}>{l.status}</span>
                                        {l.verifier && <span className="block text-[10px] text-slate-400">oleh {l.verifier}</span>}
                                        {l.catatan_verifikasi && <span className="block text-[10px] text-rose-500">{l.catatan_verifikasi}</span>}
                                    </td>
                                    {can.approve && (
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {l.status === 'menunggu' ? (
                                                <>
                                                    <button onClick={() => decide([l.id], 'disetujui')} title="Setujui" className="rounded-lg p-1 text-brand-600 hover:bg-brand-50">
                                                        <Check size={15} />
                                                    </button>
                                                    <button onClick={() => decide([l.id], 'ditolak')} title="Tolak" className="rounded-lg p-1 text-rose-500 hover:bg-rose-50">
                                                        <X size={15} />
                                                    </button>
                                                </>
                                            ) : (
                                                <button onClick={() => decide([l.id], 'menunggu')} className="text-[11px] font-bold text-slate-400 hover:text-brand-600">
                                                    batalkan
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {lemburs.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-slate-400">
                                        Tidak ada lembur pada rentang ini.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

// --- Log ----------------------------------------------------------------------------------------

function LogTab({ logs = [], can, karyawan_options = [], mesin_options = [] }: Props) {
    const [koreksi, setKoreksi] = useState(false);
    const form = useForm<{ file: File | null; mesin_absen_id: string }>({ file: null, mesin_absen_id: '' });
    const SUMBER = { mesin: 'bg-slate-100 text-slate-600', import: 'bg-violet-100 text-violet-700', manual: 'bg-sky-100 text-sky-700' };

    return (
        <div className="space-y-5">
            {can.edit && (
                <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                    <div>
                        <p className="text-sm font-bold text-slate-700">Import log dari file</p>
                        <p className="text-[11px] text-slate-400">attlog.dat / txt / csv / xlsx — kolom: PIN (atau NRP), tanggal-jam (atau tanggal + jam)</p>
                    </div>
                    <input type="file" accept=".dat,.txt,.csv,.xlsx,.xls" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="text-xs" />
                    <select value={form.data.mesin_absen_id} onChange={(e) => form.setData('mesin_absen_id', e.target.value)} className={smallInput}>
                        <option value="">Mesin (opsional)</option>
                        {mesin_options.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.nama}
                            </option>
                        ))}
                    </select>
                    <button
                        onClick={() => form.post(`${URL}/import`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() })}
                        disabled={!form.data.file || form.processing}
                        className={primaryButton}
                    >
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <FileUp size={15} />} Import
                    </button>
                    {form.errors.file && <p className="w-full text-xs text-rose-600">{form.errors.file}</p>}
                    <button onClick={() => setKoreksi(true)} className={`${ghostButton} ml-auto`}>
                        <span className="flex items-center gap-1.5">
                            <Plus size={14} /> Koreksi log
                        </span>
                    </button>
                </div>
            )}
            <div className="overflow-x-auto rounded-2xl border border-slate-100 bg-white shadow-sm">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th className="px-3 py-2">Waktu</th>
                            <th className="px-3 py-2">PIN</th>
                            <th className="px-3 py-2">Karyawan</th>
                            <th className="px-3 py-2">Mesin</th>
                            <th className="px-3 py-2">Sumber</th>
                            <th className="px-3 py-2">Catatan</th>
                            {can.edit && <th className="px-3 py-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {logs.map((l) => (
                            <tr key={l.id} className="border-t border-slate-100">
                                <td className="px-3 py-2 font-mono text-xs">{l.waktu}</td>
                                <td className="px-3 py-2 font-mono text-xs text-slate-500">{l.pin}</td>
                                <td className="px-3 py-2">{l.nama ? `${l.nrp} · ${l.nama}` : <span className="text-xs text-rose-500">PIN tidak dikenal</span>}</td>
                                <td className="px-3 py-2 text-xs text-slate-500">{l.mesin ?? '–'}</td>
                                <td className="px-3 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${SUMBER[l.sumber]}`}>{l.sumber === 'manual' ? 'koreksi' : l.sumber}</span>
                                </td>
                                <td className="px-3 py-2 text-xs text-slate-500">{l.catatan ?? ''}</td>
                                {can.edit && (
                                    <td className="px-3 py-2">
                                        {l.sumber === 'manual' && (
                                            <button
                                                onClick={() => confirm('Hapus log koreksi ini?') && router.delete(`${URL}/log/${l.id}`, { preserveScroll: true })}
                                                className="rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                                title="Hapus koreksi"
                                            >
                                                <Trash2 size={14} />
                                            </button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                        {logs.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-400">
                                    Belum ada log absen pada rentang ini.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {koreksi && <KoreksiModal initial={{ karyawan_id: 0, waktu: '' }} options={karyawan_options} onClose={() => setKoreksi(false)} />}
        </div>
    );
}

// --- Mesin --------------------------------------------------------------------------------------

function MesinTab({ mesins = [], work_locations = [], server, can }: Props) {
    const [editing, setEditing] = useState<MesinRow | 'new' | null>(null);
    const STATUS = { aktif: 'bg-brand-100 text-brand-700', nonaktif: 'bg-slate-100 text-slate-500', baru: 'bg-amber-100 text-amber-700' };

    return (
        <div className="space-y-5">
            <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 font-bold text-slate-800">
                            <Cpu size={17} className="text-brand-600" /> Mesin absen ({mesins.length})
                        </h2>
                        {can.edit && (
                            <button onClick={() => setEditing('new')} className={primaryButton}>
                                <Plus size={15} /> Tambah mesin
                            </button>
                        )}
                    </div>
                    <div className="space-y-2.5">
                        {mesins.map((m) => (
                            <div key={m.id} className={`flex flex-wrap items-center gap-4 rounded-2xl border p-4 ${m.status === 'baru' ? 'border-amber-200 bg-amber-50/40' : 'border-slate-100'}`}>
                                <span className={`flex h-10 w-10 items-center justify-center rounded-xl ${m.online ? 'bg-brand-50 text-brand-600' : 'bg-slate-100 text-slate-400'}`}>
                                    {m.online ? <Wifi size={18} /> : <WifiOff size={18} />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold text-slate-800">
                                        {m.nama}{' '}
                                        <span className={`ml-1 rounded-full px-2 py-0.5 text-[10px] font-bold ${STATUS[m.status]}`}>
                                            {m.status === 'baru' ? 'baru terdeteksi' : m.status}
                                        </span>
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        SN <span className="font-mono">{m.serial_number}</span>
                                        {(m.merek || m.model) && ` · ${[m.merek, m.model].filter(Boolean).join(' ')}`}
                                        {m.lokasi && ` · ${m.lokasi}`}
                                    </p>
                                    <p className="text-[11px] text-slate-400">
                                        {m.online ? 'Online' : 'Offline'}
                                        {m.last_seen_at && ` · terakhir terhubung ${m.last_seen_at}`}
                                        {m.last_ip && ` dari ${m.last_ip}`} · {m.log_hari_ini} log hari ini
                                    </p>
                                </div>
                                {can.edit && (
                                    <div className="flex gap-1">
                                        <button
                                            onClick={() => setEditing(m.status === 'baru' ? { ...m, status: 'aktif', nama: '' } : m)}
                                            className={m.status === 'baru' ? primaryButton : 'rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600'}
                                            title="Ubah"
                                        >
                                            {m.status === 'baru' ? (
                                                <>
                                                    <Check size={15} /> Terima
                                                </>
                                            ) : (
                                                <Pencil size={15} />
                                            )}
                                        </button>
                                        <button
                                            onClick={() =>
                                                confirm(`Keluarkan mesin ${m.nama} (${m.serial_number})? Log yang sudah masuk tetap tersimpan.`) &&
                                                router.delete(`${URL}/mesin/${m.id}`, { preserveScroll: true })
                                            }
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                            title="Keluarkan mesin"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    </div>
                                )}
                            </div>
                        ))}
                        {mesins.length === 0 && <p className="py-6 text-center text-sm text-slate-400">Belum ada mesin absen.</p>}
                    </div>
                </div>

                <div className="h-fit rounded-2xl border border-brand-100 bg-white p-5 shadow-sm">
                    <h3 className="flex items-center gap-2 font-bold text-slate-800">
                        <Fingerprint size={17} className="text-brand-600" /> Cara menghubungkan mesin
                    </h3>
                    <p className="mt-1 text-xs text-slate-500">Untuk mesin dengan fitur ADMS / Cloud / Push (ZKTeco, Solution, Fingerspot, dan yang kompatibel).</p>
                    <ol className="mt-3 list-decimal space-y-2 pl-4 text-xs text-slate-600">
                        <li>Tambahkan mesin di sini dengan <b>serial number</b> persis seperti di mesin (Menu → Info Sistem).</li>
                        <li>
                            Di mesin: Menu → Komunikasi → <b>Cloud Server / ADMS</b>. Alamat server: <code className="rounded bg-slate-100 px-1">{server?.host}</code>, port{' '}
                            <code className="rounded bg-slate-100 px-1">80</code> (atau 443 bila mesin mendukung HTTPS), domain name ON.
                        </li>
                        <li>Pastikan jam & tanggal mesin benar. Setiap karyawan memakai PIN = NRP-nya (atau PIN Absen di data karyawan).</li>
                        <li>Status berubah <b>Online</b> dalam ±1 menit, dan CI/CO langsung masuk ke Resume Absensi.</li>
                    </ol>
                    <p className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-[11px] text-amber-700">
                        Mesin yang mengirim data sebelum didaftarkan muncul sebagai <b>baru terdeteksi</b>; datanya baru diterima setelah Anda klik Terima.
                    </p>
                    <p className="mt-2 text-[11px] text-slate-400">Mesin tanpa ADMS: unduh log (USB / aplikasi bawaan) lalu import di tab Log Absen.</p>
                </div>
            </div>
            {editing && <MesinModal mesin={editing === 'new' ? null : editing} locations={work_locations} onClose={() => setEditing(null)} />}
        </div>
    );
}

function MesinModal({ mesin, locations, onClose }: { mesin: MesinRow | null; locations: { id: number; name: string }[]; onClose: () => void }) {
    const form = useForm({
        nama: mesin?.nama ?? '',
        serial_number: mesin?.serial_number ?? '',
        merek: mesin?.merek ?? '',
        model: mesin?.model ?? '',
        work_location_id: mesin?.work_location_id ? String(mesin.work_location_id) : '',
        status: mesin && mesin.status !== 'baru' ? mesin.status : 'aktif',
        catatan: mesin?.catatan ?? '',
    });
    const save = () => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (mesin) form.put(`${URL}/mesin/${mesin.id}`, opts);
        else form.post(`${URL}/mesin`, opts);
    };
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="mt-1 text-xs text-rose-600">{form.errors[k]}</p>;

    return (
        <Modal
            title={mesin ? 'Ubah mesin absen' : 'Tambah mesin absen'}
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Field label="Nama mesin">
                        <input value={form.data.nama} onChange={(e) => form.setData('nama', e.target.value)} placeholder="mis. Fingerprint Pos Satpam Site" className={inputClass} />
                        {err('nama')}
                    </Field>
                </div>
                <Field label="Serial number">
                    <input value={form.data.serial_number} onChange={(e) => form.setData('serial_number', e.target.value)} className={`${inputClass} font-mono`} />
                    {err('serial_number')}
                </Field>
                <Field label="Lokasi kerja">
                    <select value={form.data.work_location_id} onChange={(e) => form.setData('work_location_id', e.target.value)} className={inputClass}>
                        <option value="">—</option>
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Merek">
                    <input value={form.data.merek} onChange={(e) => form.setData('merek', e.target.value)} placeholder="ZKTeco / Solution / Fingerspot" className={inputClass} />
                </Field>
                <Field label="Model">
                    <input value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={inputClass} />
                </Field>
                <Field label="Status">
                    <select value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'aktif' | 'nonaktif')} className={inputClass}>
                        <option value="aktif">Aktif — data diterima</option>
                        <option value="nonaktif">Nonaktif — data ditahan</option>
                    </select>
                </Field>
                <Field label="Catatan">
                    <input value={form.data.catatan} onChange={(e) => form.setData('catatan', e.target.value)} className={inputClass} />
                </Field>
            </div>
        </Modal>
    );
}
