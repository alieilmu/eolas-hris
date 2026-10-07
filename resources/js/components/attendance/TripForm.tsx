import { useEffect, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Banknote, CalendarHeart, Info, Loader2, PlaneLanding, PlaneTakeoff, Save, TrainFront } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { BandaraPicker, bandaraForCity, type Bandara } from '@/components/attendance/BandaraPicker';
import { LampiranPicker } from '@/components/lampiran/LampiranPicker';
import { MoneyInput } from '@/components/comben/MoneyInput';
import { daysBetween } from '@/lib/attendance';
import { fmtDate } from '@/lib/format';
import { draftPayload, emptyDraft, type LampiranFile, type LampiranLimits } from '@/lib/lampiran';
import { KATEGORI_LABEL, type CutiInfo, type KategoriCuti } from '@/lib/cuti';

export interface TripPerson {
    id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    work_location: string | null;
}

export type TripJenis = 'dinas' | 'cuti';

/** A trip as listed on Pengajuan Dinas. */
export interface TripData {
    id: number;
    karyawan_id: number;
    nrp: string;
    name: string;
    jabatan: string | null;
    work_location: string | null;
    jenis: TripJenis;
    tanggal_awal: string;
    tanggal_akhir: string;
    kota_asal: string;
    kota_tujuan: string;
    keterangan: string | null;
    status_uang: string | null;
    status: 'diajukan' | 'disetujui' | 'ditolak';
    sumber: 'hr' | 'portal';
    catatan_verifikasi: string | null;
    tiket_pesawat: boolean;
    bandara_asal: string | null;
    bandara_tujuan: string | null;
    pesawat_pp: boolean;
    pulang_tanggal: string | null;
    pulang_bandara_asal: string | null;
    pulang_bandara_tujuan: string | null;
    tiket_kereta: boolean;
    uang_muka: boolean;
    uang_muka_nominal: number | null;
    lampiran: LampiranFile[];
    kategori_cuti: KategoriCuti | null;
    hari_cuti: number | null;
    potong_tahunan: number;
    potong_besar: number;
    lama: number;
    perjalanan: 'akan' | 'berjalan' | 'selesai';
    hari_ke: number;
}

const input = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const label = 'mb-1 block text-[11px] font-bold text-slate-500';

/**
 * NRP, Tgl Awal, Kota Asal, Tgl Akhir, Kota Tujuan (+ keterangan) of a trip or leave, the
 * flight / train tickets asked with it, and the signed cuti / dinas form (required).
 * New entries go to Trip & Leave; an existing one is updated in place.
 */
export function TripForm({
    people,
    cities,
    bandara,
    limits,
    trip,
    onDone,
    layout = 'row',
    self,
    initialJenis,
}: {
    people: TripPerson[];
    cities: string[];
    /** Airports to search for a flight ticket. */
    bandara: Bandara[];
    /** Upload limits of the server, for the signed form. */
    limits: LampiranLimits;
    trip?: TripData;
    onDone?: () => void;
    /** The employee portal: the employee's own cuti / dinas, sent to HR for approval. */
    self?: { person: TripPerson; submitUrl: string };
    /** Starts as cuti or dinas (the portal opens it from either button). */
    initialJenis?: TripJenis;
    /** row: one line on top of Trip & Leave; stack: inside a dialog. */
    layout?: 'row' | 'stack';
}) {
    const form = useForm({
        jenis: (trip?.jenis ?? initialJenis ?? 'dinas') as TripJenis,
        kategori_cuti: (trip?.kategori_cuti ?? '') as KategoriCuti | '',
        nrp: self?.person.nrp ?? trip?.nrp ?? '',
        tanggal_awal: trip?.tanggal_awal ?? '',
        kota_asal: trip?.kota_asal ?? '',
        tanggal_akhir: trip?.tanggal_akhir ?? '',
        kota_tujuan: trip?.kota_tujuan ?? '',
        keterangan: trip?.keterangan ?? '',
        tiket_pesawat: trip?.tiket_pesawat ?? false,
        bandara_asal: trip?.bandara_asal ?? '',
        bandara_tujuan: trip?.bandara_tujuan ?? '',
        pesawat_pp: trip?.pesawat_pp ?? false,
        pulang_tanggal: trip?.pulang_tanggal ?? '',
        pulang_bandara_asal: trip?.pulang_bandara_asal ?? '',
        pulang_bandara_tujuan: trip?.pulang_bandara_tujuan ?? '',
        tiket_kereta: trip?.tiket_kereta ?? false,
        uang_muka: trip?.uang_muka ?? false,
        uang_muka_nominal: (trip?.uang_muka_nominal ?? null) as number | null,
    });
    const [lampiran, setLampiran] = useState(() => emptyDraft(trip?.lampiran ?? []));
    const byNrp = useMemo(() => new Map(people.map((p) => [p.nrp, p])), [people]);
    const person =
        self?.person ??
        byNrp.get(form.data.nrp.trim()) ??
        (trip && trip.nrp === form.data.nrp.trim() ? { ...trip, id: trip.karyawan_id } : undefined);
    const lama = form.data.tanggal_awal && form.data.tanggal_akhir ? daysBetween(form.data.tanggal_awal, form.data.tanggal_akhir) + 1 : null;

    // A cuti: the employee's balance, field-leave allowance and Cuti Besar, to show what it will take.
    const [cuti, setCuti] = useState<CutiInfo | null>(null);
    const personId = person?.id;
    useEffect(() => {
        if (form.data.jenis !== 'cuti' || !personId) {
            setCuti(null);
            return;
        }
        const controller = new AbortController();
        const query = trip ? `?kecuali=${trip.id}` : '';
        fetch(`${self ? '/portal/cuti-info' : `/attendance/cuti-info/${personId}`}${query}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((r) => (r.ok ? r.json() : null))
            .then(setCuti)
            .catch(() => {});
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.jenis, personId]);
    const besarOpen = cuti?.besar.find((b) => b.status === 'diambil' && b.sisa > 0);
    const potong = (() => {
        if (!lama || lama < 1 || !form.data.kategori_cuti) return null;
        switch (form.data.kategori_cuti) {
            case 'tahunan':
                return { tahunan: lama, besar: 0 };
            case 'lapangan':
                return cuti?.lapangan ? { tahunan: Math.max(0, lama - cuti.lapangan.total), besar: 0 } : null;
            case 'besar': {
                const besar = Math.min(lama, besarOpen?.sisa ?? 0);
                return { tahunan: lama - besar, besar };
            }
            default:
                return { tahunan: 0, besar: 0 };
        }
    })();

    const submit = () => {
        form.transform(({ nrp, ...data }) => ({
            ...data,
            karyawan_id: person?.id ?? null,
            keterangan: data.keterangan || null,
            ...draftPayload(lampiran),
            // Files go as multipart, which PHP only reads on POST: an update is a POST with _method=put.
            ...(trip && !self ? { _method: 'put' } : {}),
        }));
        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                if (!trip) {
                    form.reset();
                    setLampiran(emptyDraft());
                }
                onDone?.();
            },
        };
        if (self) form.post(self.submitUrl, options);
        else if (trip) form.post(`/attendance/trip-leave/${trip.id}`, options);
        else form.post('/attendance/trip-leave', options);
    };

    const error = (key: string) => form.errors[key as keyof typeof form.errors];
    const err = (key: string) => (error(key) ? <p className="mt-1 text-[11px] text-rose-500">{error(key)}</p> : null);
    const lampiranError = error('lampiran') ?? Object.entries(form.errors).find(([k]) => k.startsWith('lampiran.'))?.[1];

    // Ticking the flight fills the airports of the cities already typed.
    const togglePesawat = (on: boolean) =>
        form.setData((d) => ({
            ...d,
            tiket_pesawat: on,
            bandara_asal: on && !d.bandara_asal ? bandaraForCity(bandara, d.kota_asal) : d.bandara_asal,
            bandara_tujuan: on && !d.bandara_tujuan ? bandaraForCity(bandara, d.kota_tujuan) : d.bandara_tujuan,
        }));

    // A round trip: the way back starts as the way there reversed, on the last day.
    const togglePp = (on: boolean) =>
        form.setData((d) => ({
            ...d,
            pesawat_pp: on,
            pulang_tanggal: on && !d.pulang_tanggal ? d.tanggal_akhir : d.pulang_tanggal,
            pulang_bandara_asal: on && !d.pulang_bandara_asal ? d.bandara_tujuan : d.pulang_bandara_asal,
            pulang_bandara_tujuan: on && !d.pulang_bandara_tujuan ? d.bandara_asal : d.pulang_bandara_tujuan,
        }));
    const kereta = form.data.jenis === 'dinas' && form.data.tiket_kereta;

    const check = 'h-4 w-4 rounded border-slate-300 accent-brand-600';
    const tiket = (
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3">
            <span className={label}>Tiket</span>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <label className="inline-flex items-center gap-2 font-bold text-slate-700">
                    <input type="checkbox" checked={form.data.tiket_pesawat} onChange={(e) => togglePesawat(e.target.checked)} className={check} />
                    <PlaneTakeoff size={15} className="text-sky-600" />
                    Request tiket pesawat
                </label>
                {form.data.tiket_pesawat && (
                    <label className="inline-flex items-center gap-2 text-slate-600">
                        <input type="checkbox" checked={form.data.pesawat_pp} onChange={(e) => togglePp(e.target.checked)} className={check} />
                        Pulang-pergi (PP)
                    </label>
                )}
                {form.data.jenis === 'dinas' && (
                    <label className="inline-flex items-center gap-2 font-bold text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.tiket_kereta}
                            onChange={(e) => form.setData('tiket_kereta', e.target.checked)}
                            className={check}
                        />
                        <TrainFront size={15} className="text-amber-600" />
                        Request tiket kereta
                    </label>
                )}
            </div>
            {form.data.tiket_pesawat && form.data.pesawat_pp && (
                <p className="flex items-center gap-1.5 text-[11px] font-bold text-sky-700">
                    <PlaneTakeoff size={13} />
                    Penerbangan pergi{form.data.tanggal_awal ? ` · ${fmtDate(form.data.tanggal_awal)}` : ''}
                </p>
            )}
            {form.data.tiket_pesawat && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <BandaraPicker
                        list={bandara}
                        value={form.data.bandara_asal}
                        onChange={(v) => form.setData('bandara_asal', v)}
                        label="Bandara keberangkatan"
                        error={error('bandara_asal')}
                    />
                    <BandaraPicker
                        list={bandara}
                        value={form.data.bandara_tujuan}
                        onChange={(v) => form.setData('bandara_tujuan', v)}
                        label="Bandara tujuan"
                        arrival
                        error={error('bandara_tujuan')}
                    />
                </div>
            )}
            {form.data.tiket_pesawat && form.data.pesawat_pp && (
                <div className="space-y-3 rounded-xl bg-sky-50/60 p-3 ring-1 ring-sky-100">
                    <p className="flex items-center gap-1.5 text-[11px] font-bold text-sky-700">
                        <PlaneLanding size={13} />
                        Penerbangan pulang
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2 sm:max-w-48">
                            <span className={label}>Tgl Pulang *</span>
                            <DateInput
                                value={form.data.pulang_tanggal}
                                onChange={(v) => form.setData('pulang_tanggal', v)}
                                min={form.data.tanggal_awal || undefined}
                                className={input}
                                aria-label="Tanggal penerbangan pulang"
                            />
                            {err('pulang_tanggal')}
                        </div>
                        <BandaraPicker
                            list={bandara}
                            value={form.data.pulang_bandara_asal}
                            onChange={(v) => form.setData('pulang_bandara_asal', v)}
                            label="Bandara keberangkatan"
                            error={error('pulang_bandara_asal')}
                        />
                        <BandaraPicker
                            list={bandara}
                            value={form.data.pulang_bandara_tujuan}
                            onChange={(v) => form.setData('pulang_bandara_tujuan', v)}
                            label="Bandara tujuan"
                            arrival
                            error={error('pulang_bandara_tujuan')}
                        />
                    </div>
                </div>
            )}
            {kereta && (
                <p className="flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
                    <Info size={13} className="mt-px shrink-0" />
                    <span>
                        Tulis <b>stasiun asal → stasiun tujuan</b> kereta (dan jam keberangkatan bila perlu) di kolom <b>Keterangan</b>, mis. "Gambir
                        → Bandung, 21.11 pagi".
                    </span>
                </p>
            )}
        </div>
    );

    // Dinas only: advance money with the amount asked.
    const uangMuka = form.data.jenis === 'dinas' && (
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3">
            <label className="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                <input
                    type="checkbox"
                    checked={form.data.uang_muka}
                    onChange={(e) => form.setData('uang_muka', e.target.checked)}
                    className={check}
                />
                <Banknote size={15} className="text-emerald-600" />
                Pengajuan uang muka dinas
            </label>
            {form.data.uang_muka && (
                <div className="max-w-xs">
                    <label className={label} htmlFor="trip-uang-muka">
                        Jumlah yang diajukan *
                    </label>
                    <div className="relative">
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm font-bold text-slate-400">Rp</span>
                        <MoneyInput
                            value={form.data.uang_muka_nominal}
                            onChange={(v) => form.setData('uang_muka_nominal', v)}
                            placeholder="0"
                            aria-label="Jumlah uang muka"
                            className={`${input} pl-10`}
                        />
                    </div>
                    {err('uang_muka_nominal')}
                </div>
            )}
        </div>
    );

    const saldo = cuti?.tahunan.ada ? (cuti.tahunan.saldo ?? 0) - (cuti.tahunan.hutang ?? 0) - cuti.tahunan.menunggu : null;
    const cutiSection = form.data.jenis === 'cuti' && (
        <div className="space-y-3 rounded-2xl border border-violet-200 bg-violet-50/30 p-3">
            <div className="grid gap-3 sm:grid-cols-[220px_1fr] [&>*]:min-w-0">
                <div>
                    <label className={label} htmlFor="trip-kategori">
                        Jenis cuti *
                    </label>
                    <select
                        id="trip-kategori"
                        value={form.data.kategori_cuti}
                        onChange={(e) => form.setData('kategori_cuti', e.target.value as KategoriCuti | '')}
                        className={input}
                    >
                        <option value="">Pilih jenis cuti…</option>
                        {(Object.keys(KATEGORI_LABEL) as KategoriCuti[]).map((k) => (
                            <option key={k} value={k} disabled={k === 'besar' && !!cuti && !besarOpen && trip?.kategori_cuti !== 'besar'}>
                                {k === 'lainnya' ? 'Cuti Lainnya (tidak memotong)' : KATEGORI_LABEL[k]}
                                {k === 'lapangan' && cuti?.lapangan ? ` (${cuti.lapangan.total} hari)` : ''}
                                {k === 'besar' && besarOpen ? ` (sisa ${besarOpen.sisa} hari)` : ''}
                            </option>
                        ))}
                    </select>
                    {err('kategori_cuti')}
                </div>
                <div className="flex items-start gap-2 text-xs text-slate-600">
                    <CalendarHeart size={15} className="mt-0.5 shrink-0 text-violet-600" />
                    {!person ? (
                        <span className="text-slate-400">Isi NRP untuk melihat saldo cuti.</span>
                    ) : !cuti ? (
                        <span className="text-slate-400">Memuat saldo cuti…</span>
                    ) : (
                        <span className="space-y-0.5">
                            <span className="block">
                                Sisa cuti tahunan:{' '}
                                <b className={saldo !== null && saldo <= 0 ? 'text-rose-600' : 'text-emerald-700'}>{saldo ?? '—'} hari</b>
                                {cuti.tahunan.menunggu ? ` (setelah ${cuti.tahunan.menunggu} hari menunggu)` : ''}
                                {!cuti.tahunan.ada && <span className="text-amber-700"> · {cuti.tahunan.alasan}</span>}
                            </span>
                            {form.data.kategori_cuti === 'lapangan' &&
                                (cuti.lapangan ? (
                                    <span className="block">
                                        Cuti lapangan: {cuti.lapangan.cuti} hari + {cuti.lapangan.berangkat} berangkat + {cuti.lapangan.kembali}{' '}
                                        kembali = {cuti.lapangan.total} hari.
                                    </span>
                                ) : (
                                    <span className="block text-rose-600">Cuti lapangan belum diatur untuk lokasi ini.</span>
                                ))}
                            {lama !== null && lama > 0 && potong && (
                                <span className="block font-bold text-slate-700">
                                    {lama} hari → {potong.besar ? `${potong.besar} hari dari Cuti Besar, ` : ''}
                                    {potong.tahunan ? (
                                        <span className={saldo !== null && potong.tahunan > saldo ? 'text-rose-600' : 'text-violet-700'}>
                                            {potong.tahunan} hari memotong cuti tahunan
                                            {saldo !== null && potong.tahunan > saldo
                                                ? ` (kurang ${potong.tahunan - Math.max(0, saldo)} hari, jadi hutang)`
                                                : ''}
                                        </span>
                                    ) : (
                                        'tidak memotong cuti tahunan'
                                    )}
                                </span>
                            )}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );

    const lampiranField = (
        <LampiranPicker
            value={lampiran}
            onChange={setLampiran}
            limits={limits}
            label={`Form ${form.data.jenis === 'cuti' ? 'cuti' : 'dinas'} yang sudah disetujui`}
            hint="Foto / scan form yang sudah ditandatangani atasan."
            error={lampiranError}
        />
    );

    const jenisToggle = (
        <div>
            <span className={label}>Jenis</span>
            <div className="flex rounded-xl bg-slate-100 p-1">
                {(['dinas', 'cuti'] as const).map((j) => (
                    <button
                        key={j}
                        type="button"
                        onClick={() => form.setData('jenis', j)}
                        className={`flex-1 rounded-lg px-3 py-1 text-sm font-bold transition-all ${
                            form.data.jenis === j
                                ? j === 'dinas'
                                    ? 'bg-sky-600 text-white shadow-sm'
                                    : 'bg-violet-600 text-white shadow-sm'
                                : 'text-slate-500'
                        }`}
                    >
                        {j === 'dinas' ? 'Dinas' : 'Cuti'}
                    </button>
                ))}
            </div>
            {err('jenis')}
        </div>
    );

    const fields = (
        <>
            <div className={layout === 'row' ? 'min-w-0' : ''}>
                <label className={label} htmlFor="trip-nrp">
                    NRP *
                </label>
                <input
                    id="trip-nrp"
                    list="trip-people"
                    value={form.data.nrp}
                    onChange={(e) => form.setData('nrp', e.target.value.replace(/\s.*$/, ''))}
                    placeholder="Ketik NRP / nama"
                    autoComplete="off"
                    readOnly={!!self}
                    className={`${input} font-mono tabular-nums`}
                />
                <datalist id="trip-people">
                    {people.map((p) => (
                        <option key={p.id} value={p.nrp}>
                            {p.name}
                            {p.jabatan ? ` — ${p.jabatan}` : ''}
                        </option>
                    ))}
                </datalist>
                {person ? (
                    <p className="mt-1 truncate text-[11px] font-bold text-emerald-700" title={`${person.name} · ${person.jabatan ?? ''}`}>
                        {person.name}
                        <span className="font-normal text-slate-400"> · {person.jabatan ?? 'Belum ditempatkan'}</span>
                    </p>
                ) : form.data.nrp.trim() ? (
                    <p className="mt-1 text-[11px] text-amber-600">NRP tidak ditemukan di karyawan aktif.</p>
                ) : null}
                {err('karyawan_id')}
            </div>
            <div>
                <span className={label}>Tgl Awal *</span>
                <DateInput
                    value={form.data.tanggal_awal}
                    onChange={(v) => form.setData('tanggal_awal', v)}
                    className={input}
                    aria-label="Tanggal awal"
                />
                {err('tanggal_awal')}
            </div>
            <div>
                <label className={label} htmlFor="trip-asal">
                    Kota Asal *
                </label>
                <input
                    id="trip-asal"
                    list="trip-cities"
                    value={form.data.kota_asal}
                    onChange={(e) => form.setData('kota_asal', e.target.value)}
                    className={input}
                />
                {err('kota_asal')}
            </div>
            <div>
                <span className={label}>Tgl Akhir *</span>
                <DateInput
                    value={form.data.tanggal_akhir}
                    onChange={(v) => form.setData('tanggal_akhir', v)}
                    min={form.data.tanggal_awal || undefined}
                    className={input}
                    aria-label="Tanggal akhir"
                />
                {lama !== null && lama > 0 && <p className="mt-1 text-[11px] text-slate-400">{lama} hari</p>}
                {err('tanggal_akhir')}
            </div>
            <div>
                <label className={label} htmlFor="trip-tujuan">
                    Kota Tujuan *
                </label>
                <input
                    id="trip-tujuan"
                    list="trip-cities"
                    value={form.data.kota_tujuan}
                    onChange={(e) => form.setData('kota_tujuan', e.target.value)}
                    className={input}
                />
                {err('kota_tujuan')}
            </div>
            <datalist id="trip-cities">
                {cities.map((c) => (
                    <option key={c} value={c} />
                ))}
            </datalist>
        </>
    );

    const button = (
        <button
            type="button"
            onClick={submit}
            disabled={form.processing}
            className="inline-flex h-[38px] items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 text-sm font-bold whitespace-nowrap text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
        >
            {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
            {self ? 'Ajukan ke HR' : trip ? 'Simpan perubahan' : 'Simpan'}
        </button>
    );

    const keterangan = (
        <div>
            <label className={label} htmlFor="trip-ket">
                Keterangan{kereta && ' * (stasiun kereta)'}
            </label>
            <input
                id="trip-ket"
                value={form.data.keterangan}
                onChange={(e) => form.setData('keterangan', e.target.value)}
                placeholder={kereta ? 'Stasiun asal → tujuan, mis. Gambir → Bandung, 21.11 pagi' : 'Opsional — mis. keperluan, no. surat tugas'}
                className={input}
            />
            {err('keterangan')}
        </div>
    );

    if (layout === 'stack') {
        return (
            <div className="space-y-4">
                {jenisToggle}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 [&>*]:min-w-0">{fields}</div>
                {cutiSection}
                {tiket}
                {uangMuka}
                {lampiranField}
                {keterangan}
                <div className="flex justify-end">{button}</div>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {/* One line on wide screens; wraps into a grid on narrower ones. */}
            <div className="grid grid-cols-1 items-start gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[150px_minmax(180px,1.3fr)_150px_1fr_150px_1fr]">
                {jenisToggle}
                {fields}
            </div>
            <div className="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
                <div className="space-y-3">
                    {cutiSection}
                    {tiket}
                    {uangMuka}
                </div>
                {lampiranField}
            </div>
            <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_auto]">
                {keterangan}
                {button}
            </div>
        </div>
    );
}
