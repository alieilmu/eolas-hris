import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, ChevronRight, Clock, Glasses, Palmtree, Plus, Stethoscope, UserPen, X } from 'lucide-react';
import { DeklarasiForm } from '@/components/comben/DeklarasiForm';
import { TripForm, type TripData } from '@/components/attendance/TripForm';
import { Modal } from '@/components/attendance/ui';
import { rupiah, type DeklarasiRow, type PlafonInfo } from '@/lib/comben';
import { fmtDate } from '@/lib/format';
import { BESAR_STATUS, gapok, type CutiInfo } from '@/lib/cuti';
import { TiketBadges, type Bandara } from '@/components/attendance/BandaraPicker';
import { LampiranButton } from '@/components/lampiran/LampiranPicker';
import type { LampiranFile, LampiranLimits } from '@/lib/lampiran';

interface Props {
    karyawan: PlafonInfo['karyawan'];
    plafon: Omit<PlafonInfo, 'karyawan' | 'bulan_buku'>;
    bulan_buku: { value: string; label: string; span: string };
    deklarasis: (DeklarasiRow & { status: Status; catatan_verifikasi: string | null })[];
    trips: (TripData & { status: Status; catatan_verifikasi: string | null })[];
    cities: string[];
    tempat_options: string[];
    bandara: Bandara[];
    lampiran_limits: LampiranLimits;
    /** A Pengajuan Update Data waiting for HR. */
    pengajuan_data: boolean;
    cuti: CutiInfo;
    cuti_aturan: { besar_hari: number; besar_gapok_diambil: number; besar_gapok_diuangkan: number };
}

type Status = 'diajukan' | 'disetujui' | 'ditolak';

const STATUS: Record<Status, { label: string; className: string }> = {
    diajukan: { label: 'Menunggu HR', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    disetujui: { label: 'Disetujui', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    ditolak: { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 ring-rose-200' },
};

/** Portal Karyawan: own plafon, and pengajuan of deklarasi, cuti and dinas with their status. */
export default function PortalHome({
    karyawan,
    plafon,
    bulan_buku,
    deklarasis,
    trips,
    cities,
    tempat_options,
    bandara,
    lampiran_limits,
    pengajuan_data,
    cuti,
    cuti_aturan,
}: Props) {
    const [form, setForm] = useState<'deklarasi' | 'cuti' | 'dinas' | null>(null);
    const person = { id: karyawan.id, nrp: karyawan.nrp, name: karyawan.name, jabatan: karyawan.jabatan, work_location: karyawan.work_location };
    const p = plafon.pengobatan;
    const used = p.plafon ? Math.min(100, ((p.terpakai + (p.menunggu ?? 0)) / p.plafon) * 100) : 0;

    const cancel = (jenis: 'deklarasi' | 'trip', id: number) => {
        if (confirm('Batalkan pengajuan ini?')) router.delete(`/portal/pengajuan/${jenis}/${id}`, { preserveScroll: true });
    };

    const actions = [
        { key: 'deklarasi' as const, label: 'Deklarasi Pengobatan / Kacamata', icon: Stethoscope, tone: 'bg-emerald-600 hover:bg-emerald-700' },
        { key: 'cuti' as const, label: 'Ajukan Cuti', icon: Palmtree, tone: 'bg-violet-600 hover:bg-violet-700' },
        { key: 'dinas' as const, label: 'Ajukan Dinas', icon: Briefcase, tone: 'bg-sky-600 hover:bg-sky-700' },
    ];

    return (
        <>
            <Head title="Portal Karyawan" />
            <div className="space-y-5">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-xs font-bold text-slate-400">Selamat datang,</p>
                    <h1 className="text-xl font-black text-slate-800">{karyawan.name}</h1>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {karyawan.nrp} · {karyawan.jabatan ?? 'Belum ditempatkan'}
                        {karyawan.work_location ? ` · ${karyawan.work_location}` : ''} · Status keluarga <b>{plafon.status_ptkp}</b>
                    </p>
                    <div className="mt-4 grid gap-2 sm:grid-cols-3">
                        {actions.map((a) => (
                            <button
                                key={a.key}
                                onClick={() => setForm(a.key)}
                                className={`flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-sm font-bold text-white shadow-sm ${a.tone}`}
                            >
                                <Plus size={16} />
                                <a.icon size={16} />
                                {a.label}
                            </button>
                        ))}
                    </div>
                </section>

                <Link
                    href="/portal/data"
                    className="flex items-center gap-3 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-200 hover:bg-brand-50/40"
                >
                    <span className="rounded-2xl bg-brand-50 p-2.5 text-brand-600">
                        <UserPen size={20} />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold text-slate-700">Update Data Saya</span>
                        <span className="block text-xs text-slate-400">
                            {pengajuan_data
                                ? 'Ada pengajuan yang menunggu verifikasi HR.'
                                : 'Alamat, nomor HP, rekening, keluarga, pendidikan, dokumen — dikirim ke HR untuk diverifikasi.'}
                        </span>
                    </span>
                    {pengajuan_data && (
                        <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-amber-200">
                            Menunggu HR
                        </span>
                    )}
                    <ChevronRight size={18} className="text-slate-300" />
                </Link>

                {/* Plafon */}
                <section className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <Stethoscope size={16} className="text-emerald-600" />
                            Plafon Pengobatan {plafon.tahun}
                        </h2>
                        {p.plafon === null ? (
                            <p className="mt-3 text-sm text-amber-700">Plafon belum diatur untuk jabatan Anda. Hubungi HR.</p>
                        ) : (
                            <>
                                <p className="mt-3 text-3xl font-black text-emerald-700 tabular-nums">Rp {rupiah(p.sisa)}</p>
                                <p className="text-xs text-slate-400">
                                    sisa dari Rp {rupiah(p.plafon)} ({plafon.keluarga ? 'Keluarga' : 'Lajang'})
                                </p>
                                <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className="h-full rounded-full bg-emerald-500" style={{ width: `${used}%` }} />
                                </div>
                                <div className="mt-2 flex justify-between text-xs text-slate-500">
                                    <span>Terpakai Rp {rupiah(p.terpakai)}</span>
                                    {!!p.menunggu && <span className="font-bold text-amber-700">Menunggu HR Rp {rupiah(p.menunggu)}</span>}
                                </div>
                            </>
                        )}
                        <p className="mt-3 text-[11px] text-slate-400">
                            Bulan buku berjalan {bulan_buku.label} ({bulan_buku.span}). Plafon direset tiap awal tahun.
                        </p>
                    </div>
                    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                            <Glasses size={16} className="text-sky-600" />
                            Plafon Kacamata
                        </h2>
                        <p className="text-[11px] text-slate-400">Per anggota keluarga · bingkai 2 tahun sekali · lensa 1 tahun sekali</p>
                        <table className="mt-3 w-full text-sm">
                            <thead className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                <tr>
                                    <th className="py-1 text-left">Anggota</th>
                                    <th className="py-1 text-right">Sisa bingkai</th>
                                    <th className="py-1 text-right">Sisa lensa</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {plafon.kacamata.map((m) => (
                                    <tr key={m.key}>
                                        <td className="py-1.5">
                                            <p className="font-bold text-slate-700">{m.nama}</p>
                                            <p className="text-[11px] text-slate-400">{m.hubungan}</p>
                                        </td>
                                        <td className="py-1.5 text-right text-slate-700 tabular-nums">{rupiah(m.bingkai.sisa)}</td>
                                        <td className="py-1.5 text-right text-slate-700 tabular-nums">{rupiah(m.lensa.sisa)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* Cuti */}
                <CutiCard cuti={cuti} aturan={cuti_aturan} />

                {/* Pengajuan */}
                <section className="grid gap-4 lg:grid-cols-2">
                    <History title="Deklarasi saya" empty="Belum ada deklarasi.">
                        {deklarasis.map((d) => (
                            <Item
                                key={d.id}
                                status={d.status}
                                note={d.catatan_verifikasi}
                                title={`${d.nomor} · Rp ${rupiah(d.total)}`}
                                subtitle={`${fmtDate(d.tanggal)} · buku ${d.bulan_label}`}
                                lampiran={d.lampiran}
                                lampiranTitle={`Nota ${d.nomor}`}
                                noun="nota"
                                onCancel={d.status === 'diajukan' ? () => cancel('deklarasi', d.id) : undefined}
                            />
                        ))}
                    </History>
                    <History title="Cuti & dinas saya" empty="Belum ada pengajuan cuti atau dinas.">
                        {trips.map((t) => (
                            <Item
                                key={t.id}
                                status={t.status}
                                note={t.catatan_verifikasi}
                                title={`${t.jenis === 'dinas' ? 'Dinas' : 'Cuti'} ke ${t.kota_tujuan} · ${t.lama} hari`}
                                subtitle={`${fmtDate(t.tanggal_awal)} – ${fmtDate(t.tanggal_akhir)}`}
                                extra={<TiketBadges trip={t} />}
                                lampiran={t.lampiran}
                                lampiranTitle={`Form ${t.jenis}`}
                                onCancel={t.status === 'diajukan' ? () => cancel('trip', t.id) : undefined}
                            />
                        ))}
                    </History>
                </section>
            </div>

            {form === 'deklarasi' && (
                <DeklarasiForm
                    people={[person]}
                    tempatOptions={tempat_options}
                    self={{ person, infoUrl: '/portal/plafon', submitUrl: '/portal/deklarasi' }}
                    limits={lampiran_limits}
                    onClose={() => setForm(null)}
                />
            )}
            {(form === 'cuti' || form === 'dinas') && (
                <Modal
                    title={form === 'cuti' ? 'Ajukan Cuti' : 'Ajukan Perjalanan Dinas'}
                    subtitle="Dikirim ke HR; masuk Absensi setelah disetujui."
                    onClose={() => setForm(null)}
                    width="max-w-xl"
                >
                    <TripForm
                        key={form}
                        people={[person]}
                        cities={cities}
                        bandara={bandara}
                        limits={lampiran_limits}
                        layout="stack"
                        self={{ person, submitUrl: '/portal/cuti-dinas' }}
                        initialJenis={form}
                        onDone={() => setForm(null)}
                    />
                </Modal>
            )}
        </>
    );
}

function History({ title, empty, children }: { title: string; empty: string; children: React.ReactNode[] }) {
    return (
        <div className="rounded-3xl border border-slate-200 bg-white shadow-sm">
            <h2 className="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5 text-sm font-bold text-slate-700">
                <Clock size={15} className="text-brand-500" />
                {title}
            </h2>
            {children.length ? (
                <ul className="divide-y divide-slate-100">{children}</ul>
            ) : (
                <p className="px-5 py-8 text-center text-sm text-slate-400">{empty}</p>
            )}
        </div>
    );
}

function Item({
    status,
    note,
    title,
    subtitle,
    extra,
    lampiran,
    lampiranTitle,
    noun,
    onCancel,
}: {
    status: Status;
    note: string | null;
    title: string;
    subtitle: string;
    extra?: React.ReactNode;
    lampiran: LampiranFile[];
    lampiranTitle: string;
    noun?: string;
    onCancel?: () => void;
}) {
    const s = STATUS[status];
    return (
        <li className="flex items-start gap-3 px-5 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-bold text-slate-700">{title}</p>
                <p className="text-[11px] text-slate-400">{subtitle}</p>
                {note && <p className="mt-0.5 text-[11px] text-rose-600">Catatan HR: {note}</p>}
                {extra}
            </div>
            <span className="shrink-0">
                <LampiranButton files={lampiran} title={lampiranTitle} noun={noun} />
            </span>
            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${s.className}`}>{s.label}</span>
            {onCancel && (
                <button onClick={onCancel} title="Batalkan" className="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600">
                    <X size={14} />
                </button>
            )}
        </li>
    );
}

/** Own Cuti Tahunan balance, and each Cuti Besar reached with its choice (take it or cash it in). */
function CutiCard({ cuti, aturan }: { cuti: CutiInfo; aturan: Props['cuti_aturan'] }) {
    const t = cuti.tahunan;
    const choose = (periode_ke: number, keputusan: 'diambil' | 'diuangkan') => {
        const what =
            keputusan === 'diambil'
                ? `diambil sebagai cuti ${aturan.besar_hari} hari + ${gapok(aturan.besar_gapok_diambil)}`
                : `diuangkan ${gapok(aturan.besar_gapok_diuangkan)}`;
        if (confirm(`Cuti Besar ${what}? Pilihan dikirim ke HR untuk disetujui.`))
            router.post('/portal/cuti-besar', { periode_ke, keputusan }, { preserveScroll: true });
    };
    return (
        <section className="grid gap-4 md:grid-cols-2">
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <Palmtree size={16} className="text-violet-600" />
                    Cuti Tahunan
                </h2>
                {!t.ada ? (
                    <p className="mt-3 text-sm text-amber-700">{t.alasan} Hubungi HR.</p>
                ) : (
                    <>
                        <p className="mt-3 text-3xl font-black text-violet-700 tabular-nums">{(t.saldo ?? 0) - (t.hutang ?? 0)} hari</p>
                        <p className="text-xs text-slate-400">sisa cuti aktif{t.menunggu ? ` · ${t.menunggu} hari menunggu persetujuan` : ''}</p>
                        <div className="mt-3 space-y-0.5 text-xs text-slate-500">
                            {!!t.hutang && <p className="font-bold text-rose-600">Hutang cuti {t.hutang} hari (dipotong dari jatah berikutnya)</p>}
                            {!!t.hangus_akhir_tahun && <p className="text-amber-700">{t.hangus_akhir_tahun} hari hangus 31.12 bila tidak diambil</p>}
                            <p>Jatah berikutnya masuk {fmtDate(t.hak_berikutnya)}</p>
                            {cuti.lapangan && (
                                <p>
                                    Cuti lapangan {cuti.lapangan.cuti} hari + {cuti.lapangan.berangkat + cuti.lapangan.kembali} hari perjalanan;
                                    lebihnya memotong cuti tahunan.
                                </p>
                            )}
                        </div>
                    </>
                )}
            </div>
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <Palmtree size={16} className="text-emerald-600" />
                    Cuti Besar
                </h2>
                {cuti.besar.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-400">Belum ada hak cuti besar yang perlu dipilih.</p>
                ) : (
                    <ul className="mt-3 space-y-2">
                        {cuti.besar.map((b) => (
                            <li key={b.periode_ke} className="rounded-2xl border border-slate-200 p-3 text-xs">
                                <div className="flex items-center justify-between gap-2">
                                    <b className="text-slate-700">Masa kerja {b.masa_kerja} tahun</b>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${BESAR_STATUS[b.status].className}`}
                                    >
                                        {BESAR_STATUS[b.status].label}
                                    </span>
                                </div>
                                <p className="mt-0.5 text-slate-500">
                                    Hak sejak {fmtDate(b.tanggal_hak)} · pilih paling lambat {fmtDate(b.batas)}
                                </p>
                                {b.status === 'diambil' && (
                                    <p className="text-emerald-700">
                                        Sisa {b.sisa} dari {b.hari} hari · ajukan lewat "Ajukan Cuti" jenis Cuti Besar
                                    </p>
                                )}
                                {b.status === 'belum_dipilih' && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <button
                                            onClick={() => choose(b.periode_ke, 'diambil')}
                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 font-bold text-white hover:bg-emerald-700"
                                        >
                                            Ambil cuti ({aturan.besar_hari} hari + {gapok(aturan.besar_gapok_diambil)})
                                        </button>
                                        <button
                                            onClick={() => choose(b.periode_ke, 'diuangkan')}
                                            className="rounded-lg bg-violet-600 px-3 py-1.5 font-bold text-white hover:bg-violet-700"
                                        >
                                            Uangkan ({gapok(aturan.besar_gapok_diuangkan)})
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
