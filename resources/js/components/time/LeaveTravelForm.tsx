import { useEffect, useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Banknote, CalendarHeart, Info, Loader2, PlaneLanding, PlaneTakeoff, Save, TrainFront } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { AirportPicker, bandaraForCity, type Airport } from '@/components/time/AirportPicker';
import { AttachmentPicker } from '@/components/attachment/AttachmentPicker';
import { MoneyInput } from '@/components/compensation/MoneyInput';
import { rupiah } from '@/lib/compensation';
import { daysBetween } from '@/lib/time';
import { fmtDate } from '@/lib/format';
import { draftPayload, emptyDraft, type LampiranFile, type LampiranLimits } from '@/lib/attachment';
import { KATEGORI_LABEL, type CutiInfo, type KategoriCuti } from '@/lib/leave';

export interface TripPerson {
    id: number;
    employee_number: string;
    name: string;
    position: string | null;
    work_location: string | null;
}

export type TripJenis = 'business_trip' | 'leave';

/** A trip as listed on Pengajuan Dinas. */
export interface TripData {
    id: number;
    employee_id: number;
    employee_number: string;
    name: string;
    position: string | null;
    work_location: string | null;
    national_id_number: string | null;
    /** Point of hire: the lokasi penerimaan in the NRP. */
    point_of_hire: string | null;
    employee_class: string | null;
    type: TripJenis;
    start_date: string;
    end_date: string;
    origin_city: string;
    destination_city: string;
    description: string | null;
    cash_advance_status: string | null;
    status: 'pending' | 'approved' | 'rejected';
    source: 'hr' | 'self_service';
    verification_notes: string | null;
    needs_flight: boolean;
    departure_airport: string | null;
    arrival_airport: string | null;
    is_round_trip: boolean;
    return_date: string | null;
    return_departure_airport: string | null;
    return_arrival_airport: string | null;
    needs_train: boolean;
    needs_cash_advance: boolean;
    cash_advance_amount: number | null;
    /** Lumpsum of a Cuti Lapangan (Pengaturan Kompensasi: site × POH × klasifikasi). */
    field_leave_allowance: number | null;
    tickets_issued: Record<'outbound' | 'return', { at: string; by: string | null } | null>;
    attachment: LampiranFile[];
    leave_category: KategoriCuti | null;
    leave_days: number | null;
    annual_leave_deducted: number;
    long_service_leave_deducted: number;
    old: number;
    travel: 'soon' | 'ongoing' | 'end_date';
    day_number: number;
}

const input = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const label = 'mb-1 block text-[11px] font-bold text-slate-500';

/**
 * NRP, Tgl Awal, Kota Asal, Tgl Akhir, Kota Tujuan (+ keterangan) of a trip or leave, the
 * flight / train tickets asked with it, and the signed cuti / dinas form (required).
 * New entries go to Cuti & Dinas; an existing one is updated in place.
 */
export function LeaveTravelForm({
    people,
    cities,
    airport,
    limits,
    leave_travel,
    onDone,
    layout = 'row',
    self,
    initialType,
}: {
    people: TripPerson[];
    cities: string[];
    /** Airports to search for a flight ticket. */
    airport: Airport[];
    /** Upload limits of the server, for the signed form. */
    limits: LampiranLimits;
    leave_travel?: TripData;
    onDone?: () => void;
    /** The employee portal: the employee's own cuti / dinas, sent to HR for approval. */
    self?: { person: TripPerson; submitUrl: string };
    /** Starts as cuti or dinas (the portal opens it from either button). */
    initialType?: TripJenis;
    /** row: one line on top of Cuti & Dinas; stack: inside a dialog. */
    layout?: 'row' | 'stack';
}) {
    const form = useForm({
        type: (leave_travel?.type ?? initialType ?? 'business_trip') as TripJenis,
        leave_category: (leave_travel?.leave_category ?? '') as KategoriCuti | '',
        employee_number: self?.person.employee_number ?? leave_travel?.employee_number ?? '',
        start_date: leave_travel?.start_date ?? '',
        origin_city: leave_travel?.origin_city ?? '',
        end_date: leave_travel?.end_date ?? '',
        destination_city: leave_travel?.destination_city ?? '',
        description: leave_travel?.description ?? '',
        needs_flight: leave_travel?.needs_flight ?? false,
        departure_airport: leave_travel?.departure_airport ?? '',
        arrival_airport: leave_travel?.arrival_airport ?? '',
        is_round_trip: leave_travel?.is_round_trip ?? false,
        return_date: leave_travel?.return_date ?? '',
        return_departure_airport: leave_travel?.return_departure_airport ?? '',
        return_arrival_airport: leave_travel?.return_arrival_airport ?? '',
        needs_train: leave_travel?.needs_train ?? false,
        needs_cash_advance: leave_travel?.needs_cash_advance ?? false,
        cash_advance_amount: (leave_travel?.cash_advance_amount ?? null) as number | null,
    });
    const [attachment, setLampiran] = useState(() => emptyDraft(leave_travel?.attachment ?? []));
    const byNrp = useMemo(() => new Map(people.map((p) => [p.employee_number, p])), [people]);
    const person =
        self?.person ??
        byNrp.get(form.data.employee_number.trim()) ??
        (leave_travel && leave_travel.employee_number === form.data.employee_number.trim() ? { ...leave_travel, id: leave_travel.employee_id } : undefined);
    const old = form.data.start_date && form.data.end_date ? daysBetween(form.data.start_date, form.data.end_date) + 1 : null;

    // A cuti: the employee's balance, field-leave allowance and Cuti Besar, to show what it will take.
    const [leave, setCuti] = useState<CutiInfo | null>(null);
    const personId = person?.id;
    useEffect(() => {
        if (form.data.type !== 'leave' || !personId) {
            setCuti(null);
            return;
        }
        const controller = new AbortController();
        const query = leave_travel ? `?except=${leave_travel.id}` : '';
        fetch(`${self ? '/self-service/leave-info' : `/time/leave-info/${personId}`}${query}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((r) => (r.ok ? r.json() : null))
            .then(setCuti)
            .catch(() => {});
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.type, personId]);
    const besarOpen = leave?.long_service.find((b) => b.status === 'taken' && b.remaining > 0);
    const deduct = (() => {
        if (!old || old < 1 || !form.data.leave_category) return null;
        switch (form.data.leave_category) {
            case 'annual':
                return { annual: old, long_service: 0 };
            case 'field':
                return leave?.field ? { annual: Math.max(0, old - leave.field.total), long_service: 0 } : null;
            case 'long_service': {
                const long_service = Math.min(old, besarOpen?.remaining ?? 0);
                return { annual: old - long_service, long_service };
            }
            default:
                return { annual: 0, long_service: 0 };
        }
    })();

    const submit = () => {
        form.transform(({ employee_number, ...data }) => ({
            ...data,
            employee_id: person?.id ?? null,
            description: data.description || null,
            ...draftPayload(attachment),
            // Files go as multipart, which PHP only reads on POST: an update is a POST with _method=put.
            ...(leave_travel && !self ? { _method: 'put' } : {}),
        }));
        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                if (!leave_travel) {
                    form.reset();
                    setLampiran(emptyDraft());
                }
                onDone?.();
            },
        };
        if (self) form.post(self.submitUrl, options);
        else if (leave_travel) form.post(`/time/leave-travel/${leave_travel.id}`, options);
        else form.post('/time/leave-travel', options);
    };

    const error = (key: string) => form.errors[key as keyof typeof form.errors];
    const err = (key: string) => (error(key) ? <p className="mt-1 text-[11px] text-rose-500">{error(key)}</p> : null);
    const lampiranError = error('attachment') ?? Object.entries(form.errors).find(([k]) => k.startsWith('attachment.'))?.[1];

    // Ticking the flight fills the airports of the cities already typed.
    const togglePesawat = (on: boolean) =>
        form.setData((d) => ({
            ...d,
            needs_flight: on,
            departure_airport: on && !d.departure_airport ? bandaraForCity(airport, d.origin_city) : d.departure_airport,
            arrival_airport: on && !d.arrival_airport ? bandaraForCity(airport, d.destination_city) : d.arrival_airport,
        }));

    // A round trip: the way back starts as the way there reversed, on the last day.
    const togglePp = (on: boolean) =>
        form.setData((d) => ({
            ...d,
            is_round_trip: on,
            return_date: on && !d.return_date ? d.end_date : d.return_date,
            return_departure_airport: on && !d.return_departure_airport ? d.arrival_airport : d.return_departure_airport,
            return_arrival_airport: on && !d.return_arrival_airport ? d.departure_airport : d.return_arrival_airport,
        }));
    const kereta = form.data.type === 'business_trip' && form.data.needs_train;

    const check = 'h-4 w-4 rounded border-slate-300 accent-brand-600';
    const ticket = (
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3">
            <span className={label}>Tiket</span>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <label className="inline-flex items-center gap-2 font-bold text-slate-700">
                    <input type="checkbox" checked={form.data.needs_flight} onChange={(e) => togglePesawat(e.target.checked)} className={check} />
                    <PlaneTakeoff size={15} className="text-sky-600" />
                    Request tiket pesawat
                </label>
                {form.data.needs_flight && (
                    <label className="inline-flex items-center gap-2 text-slate-600">
                        <input type="checkbox" checked={form.data.is_round_trip} onChange={(e) => togglePp(e.target.checked)} className={check} />
                        Pulang-pergi (PP)
                    </label>
                )}
                {form.data.type === 'business_trip' && (
                    <label className="inline-flex items-center gap-2 font-bold text-slate-700">
                        <input
                            type="checkbox"
                            checked={form.data.needs_train}
                            onChange={(e) => form.setData('needs_train', e.target.checked)}
                            className={check}
                        />
                        <TrainFront size={15} className="text-amber-600" />
                        Request tiket kereta
                    </label>
                )}
            </div>
            {form.data.needs_flight && form.data.is_round_trip && (
                <p className="flex items-center gap-1.5 text-[11px] font-bold text-sky-700">
                    <PlaneTakeoff size={13} />
                    Penerbangan pergi{form.data.start_date ? ` · ${fmtDate(form.data.start_date)}` : ''}
                </p>
            )}
            {form.data.needs_flight && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <AirportPicker
                        list={airport}
                        value={form.data.departure_airport}
                        onChange={(v) => form.setData('departure_airport', v)}
                        label="Bandara keberangkatan"
                        error={error('departure_airport')}
                    />
                    <AirportPicker
                        list={airport}
                        value={form.data.arrival_airport}
                        onChange={(v) => form.setData('arrival_airport', v)}
                        label="Bandara tujuan"
                        arrival
                        error={error('arrival_airport')}
                    />
                </div>
            )}
            {form.data.needs_flight && form.data.is_round_trip && (
                <div className="space-y-3 rounded-xl bg-sky-50/60 p-3 ring-1 ring-sky-100">
                    <p className="flex items-center gap-1.5 text-[11px] font-bold text-sky-700">
                        <PlaneLanding size={13} />
                        Penerbangan pulang
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2 sm:max-w-48">
                            <span className={label}>Tgl Pulang *</span>
                            <DateInput
                                value={form.data.return_date}
                                onChange={(v) => form.setData('return_date', v)}
                                min={form.data.start_date || undefined}
                                className={input}
                                aria-label="Tanggal penerbangan pulang"
                            />
                            {err('return_date')}
                        </div>
                        <AirportPicker
                            list={airport}
                            value={form.data.return_departure_airport}
                            onChange={(v) => form.setData('return_departure_airport', v)}
                            label="Bandara keberangkatan"
                            error={error('return_departure_airport')}
                        />
                        <AirportPicker
                            list={airport}
                            value={form.data.return_arrival_airport}
                            onChange={(v) => form.setData('return_arrival_airport', v)}
                            label="Bandara tujuan"
                            arrival
                            error={error('return_arrival_airport')}
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
    const uangMuka = form.data.type === 'business_trip' && (
        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-3">
            <label className="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                <input
                    type="checkbox"
                    checked={form.data.needs_cash_advance}
                    onChange={(e) => form.setData('needs_cash_advance', e.target.checked)}
                    className={check}
                />
                <Banknote size={15} className="text-emerald-600" />
                Pengajuan uang muka dinas
            </label>
            {form.data.needs_cash_advance && (
                <div className="max-w-xs">
                    <label className={label} htmlFor="leave_travel-cash-muka">
                        Jumlah yang diajukan *
                    </label>
                    <div className="relative">
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm font-bold text-slate-400">Rp</span>
                        <MoneyInput
                            value={form.data.cash_advance_amount}
                            onChange={(v) => form.setData('cash_advance_amount', v)}
                            placeholder="0"
                            aria-label="Jumlah uang muka"
                            className={`${input} pl-10`}
                        />
                    </div>
                    {err('cash_advance_amount')}
                </div>
            )}
        </div>
    );

    const balance = leave?.annual.present ? (leave.annual.balance ?? 0) - (leave.annual.debt ?? 0) - leave.annual.pending : null;
    const cutiSection = form.data.type === 'leave' && (
        <div className="space-y-3 rounded-2xl border border-violet-200 bg-violet-50/30 p-3">
            <div className="grid gap-3 sm:grid-cols-[220px_1fr] [&>*]:min-w-0">
                <div>
                    <label className={label} htmlFor="leave_travel-category">
                        Jenis cuti *
                    </label>
                    <select
                        id="leave_travel-category"
                        value={form.data.leave_category}
                        onChange={(e) => form.setData('leave_category', e.target.value as KategoriCuti | '')}
                        className={input}
                    >
                        <option value="">Pilih jenis cuti…</option>
                        {(Object.keys(KATEGORI_LABEL) as KategoriCuti[]).map((k) => (
                            <option key={k} value={k} disabled={k === 'long_service' && !!leave && !besarOpen && leave_travel?.leave_category !== 'long_service'}>
                                {k === 'other' ? 'Cuti Lainnya (tidak memotong)' : KATEGORI_LABEL[k]}
                                {k === 'field' && leave?.field ? ` (${leave.field.total} hari)` : ''}
                                {k === 'long_service' && besarOpen ? ` (sisa ${besarOpen.remaining} hari)` : ''}
                            </option>
                        ))}
                    </select>
                    {err('leave_category')}
                </div>
                <div className="flex items-start gap-2 text-xs text-slate-600">
                    <CalendarHeart size={15} className="mt-0.5 shrink-0 text-violet-600" />
                    {!person ? (
                        <span className="text-slate-400">Isi NRP untuk melihat saldo cuti.</span>
                    ) : !leave ? (
                        <span className="text-slate-400">Memuat saldo cuti…</span>
                    ) : (
                        <span className="space-y-0.5">
                            <span className="block">
                                Sisa cuti tahunan:{' '}
                                <b className={balance !== null && balance <= 0 ? 'text-rose-600' : 'text-emerald-700'}>{balance ?? '—'} hari</b>
                                {leave.annual.pending ? ` (setelah ${leave.annual.pending} hari menunggu)` : ''}
                                {!leave.annual.present && <span className="text-amber-700"> · {leave.annual.reason}</span>}
                            </span>
                            {form.data.leave_category === 'field' &&
                                (leave.field ? (
                                    <span className="block">
                                        Cuti lapangan: {leave.field.leave} hari + {leave.field.departure} berangkat + {leave.field.back}{' '}
                                        kembali = {leave.field.total} hari.
                                    </span>
                                ) : (
                                    <span className="block text-rose-600">Cuti lapangan belum diatur untuk lokasi ini.</span>
                                ))}
                            {form.data.leave_category === 'field' && (
                                <span className="block">
                                    {leave.field_leave_allowance.amount !== null ? (
                                        <>
                                            Lumpsum: <b className="text-emerald-700">Rp {rupiah(leave.field_leave_allowance.amount)}</b>
                                            <span className="text-slate-400">
                                                {' '}
                                                (POH {leave.field_leave_allowance.point_of_hire} · {leave.field_leave_allowance.employee_class})
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-amber-700">Tanpa lumpsum: {leave.field_leave_allowance.reason}</span>
                                    )}
                                </span>
                            )}
                            {old !== null && old > 0 && deduct && (
                                <span className="block font-bold text-slate-700">
                                    {old} hari → {deduct.long_service ? `${deduct.long_service} hari dari Cuti Besar, ` : ''}
                                    {deduct.annual ? (
                                        <span className={balance !== null && deduct.annual > balance ? 'text-rose-600' : 'text-violet-700'}>
                                            {deduct.annual} hari memotong cuti tahunan
                                            {balance !== null && deduct.annual > balance
                                                ? ` (kurang ${deduct.annual - Math.max(0, balance)} hari, jadi hutang)`
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
        <AttachmentPicker
            value={attachment}
            onChange={setLampiran}
            limits={limits}
            label={`Form ${form.data.type === 'leave' ? 'cuti' : 'dinas'} yang sudah disetujui`}
            hint="Foto / scan form yang sudah ditandatangani atasan."
            error={lampiranError}
        />
    );

    const jenisToggle = (
        <div>
            <span className={label}>Jenis</span>
            <div className="flex rounded-xl bg-slate-100 p-1">
                {(['business_trip', 'leave'] as const).map((j) => (
                    <button
                        key={j}
                        type="button"
                        onClick={() => form.setData('type', j)}
                        className={`flex-1 rounded-lg px-3 py-1 text-sm font-bold transition-all ${
                            form.data.type === j
                                ? j === 'business_trip'
                                    ? 'bg-sky-600 text-white shadow-sm'
                                    : 'bg-violet-600 text-white shadow-sm'
                                : 'text-slate-500'
                        }`}
                    >
                        {j === 'business_trip' ? 'Dinas' : 'Cuti'}
                    </button>
                ))}
            </div>
            {err('type')}
        </div>
    );

    const fields = (
        <>
            <div className={layout === 'row' ? 'min-w-0' : ''}>
                <label className={label} htmlFor="leave_travel-employee_number">
                    NRP *
                </label>
                <input
                    id="leave_travel-employee_number"
                    list="leave_travel-people"
                    value={form.data.employee_number}
                    onChange={(e) => form.setData('employee_number', e.target.value.replace(/\s.*$/, ''))}
                    placeholder="Ketik NRP / nama"
                    autoComplete="off"
                    readOnly={!!self}
                    className={`${input} font-mono tabular-nums`}
                />
                <datalist id="leave_travel-people">
                    {people.map((p) => (
                        <option key={p.id} value={p.employee_number}>
                            {p.name}
                            {p.position ? ` — ${p.position}` : ''}
                        </option>
                    ))}
                </datalist>
                {person ? (
                    <p className="mt-1 truncate text-[11px] font-bold text-emerald-700" title={`${person.name} · ${person.position ?? ''}`}>
                        {person.name}
                        <span className="font-normal text-slate-400"> · {person.position ?? 'Belum ditempatkan'}</span>
                    </p>
                ) : form.data.employee_number.trim() ? (
                    <p className="mt-1 text-[11px] text-amber-600">NRP tidak ditemukan di karyawan aktif.</p>
                ) : null}
                {err('employee_id')}
            </div>
            <div>
                <span className={label}>Tgl Awal *</span>
                <DateInput
                    value={form.data.start_date}
                    onChange={(v) => form.setData('start_date', v)}
                    className={input}
                    aria-label="Tanggal awal"
                />
                {err('start_date')}
            </div>
            <div>
                <label className={label} htmlFor="leave_travel-origin">
                    Kota Asal *
                </label>
                <input
                    id="leave_travel-origin"
                    list="leave_travel-cities"
                    value={form.data.origin_city}
                    onChange={(e) => form.setData('origin_city', e.target.value)}
                    className={input}
                />
                {err('origin_city')}
            </div>
            <div>
                <span className={label}>Tgl Akhir *</span>
                <DateInput
                    value={form.data.end_date}
                    onChange={(v) => form.setData('end_date', v)}
                    min={form.data.start_date || undefined}
                    className={input}
                    aria-label="Tanggal akhir"
                />
                {old !== null && old > 0 && <p className="mt-1 text-[11px] text-slate-400">{old} hari</p>}
                {err('end_date')}
            </div>
            <div>
                <label className={label} htmlFor="leave_travel-destination">
                    Kota Tujuan *
                </label>
                <input
                    id="leave_travel-destination"
                    list="leave_travel-cities"
                    value={form.data.destination_city}
                    onChange={(e) => form.setData('destination_city', e.target.value)}
                    className={input}
                />
                {err('destination_city')}
            </div>
            <datalist id="leave_travel-cities">
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
            {self ? 'Ajukan ke HR' : leave_travel ? 'Simpan perubahan' : 'Simpan'}
        </button>
    );

    const description = (
        <div>
            <label className={label} htmlFor="leave_travel-ket">
                Keterangan{kereta && ' * (stasiun kereta)'}
            </label>
            <input
                id="leave_travel-ket"
                value={form.data.description}
                onChange={(e) => form.setData('description', e.target.value)}
                placeholder={kereta ? 'Stasiun asal → tujuan, mis. Gambir → Bandung, 21.11 pagi' : 'Opsional — mis. keperluan, no. surat tugas'}
                className={input}
            />
            {err('description')}
        </div>
    );

    if (layout === 'stack') {
        return (
            <div className="space-y-4">
                {jenisToggle}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 [&>*]:min-w-0">{fields}</div>
                {cutiSection}
                {ticket}
                {uangMuka}
                {lampiranField}
                {description}
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
                    {ticket}
                    {uangMuka}
                </div>
                {lampiranField}
            </div>
            <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_auto]">
                {description}
                {button}
            </div>
        </div>
    );
}
