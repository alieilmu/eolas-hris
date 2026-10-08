import { useId, useMemo, useRef, useState } from 'react';
import { Banknote, PlaneLanding, PlaneTakeoff, TrainFront, X } from 'lucide-react';
import { rupiah } from '@/lib/comben';
import { KATEGORI_LABEL, type KategoriCuti } from '@/lib/cuti';
import { fmtDate } from '@/lib/format';

export interface Bandara {
    kode: string;
    nama: string;
    kota: string;
    provinsi: string;
}

const fold = (s: string) => s.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');

/** "Jakarta / Tangerang" → ["jakarta", "tangerang"]. */
const cities = (b: Bandara) => fold(b.kota).split(/\s*\/\s*/);

/**
 * Airports matching a typed code, city, airport name or province: an exact code first,
 * then a city that is exactly what was typed ("Tanjung" → TJG before "Tanjung Redeb"),
 * then cities starting with it, then names and provinces containing it.
 */
export function searchBandara(list: Bandara[], query: string, limit = 8): Bandara[] {
    const q = fold(query.trim());
    if (!q) return list.slice(0, limit);
    const score = (b: Bandara) => {
        if (fold(b.kode) === q) return 0;
        if (cities(b).includes(q)) return 1;
        if (cities(b).some((k) => k.startsWith(q))) return 2;
        if (fold(b.nama).includes(q)) return 3;
        if (fold(b.kota).includes(q)) return 4;
        if (fold(b.provinsi).includes(q)) return 5;
        return 9;
    };
    return list
        .map((b) => [b, score(b)] as const)
        .filter(([, n]) => n < 9)
        .sort((a, b) => a[1] - b[1])
        .slice(0, limit)
        .map(([b]) => b);
}

/** The airport serving a city typed in the trip ("Balikpapan" → BPN), if any. */
export function bandaraForCity(list: Bandara[], kota: string): string {
    const q = fold(kota.trim());
    if (q.length < 3) return '';
    const exact = list.find((b) => cities(b).includes(q));
    return (exact ?? list.find((b) => cities(b).some((k) => k.startsWith(q))))?.kode ?? '';
}

/** A searchable airport field: type a city, airport name or IATA code, pick with the mouse or ↑ ↓ Enter. */
export function BandaraPicker({
    list,
    value,
    onChange,
    label,
    arrival,
    error,
}: {
    list: Bandara[];
    value: string;
    onChange: (kode: string) => void;
    label: string;
    arrival?: boolean;
    error?: string;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const field = useRef<HTMLInputElement>(null);
    const selected = list.find((b) => b.kode === value);
    const results = useMemo(() => searchBandara(list, query), [list, query]);
    const Icon = arrival ? PlaneLanding : PlaneTakeoff;

    const pick = (b: Bandara) => {
        onChange(b.kode);
        setQuery('');
        setOpen(false);
    };

    return (
        <div className="relative">
            <label htmlFor={id} className="mb-1 block text-[11px] font-bold text-slate-500">
                {label} *
            </label>
            <div className="relative">
                <Icon size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sky-600" />
                <input
                    id={id}
                    ref={field}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={`${id}-list`}
                    autoComplete="off"
                    value={open ? query : selected ? `${selected.kode} · ${selected.kota}` : ''}
                    placeholder="Cari kota / bandara / kode"
                    onFocus={() => {
                        setOpen(true);
                        setActive(0);
                    }}
                    onBlur={() => setTimeout(() => setOpen(false), 120)}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setActive(0);
                        setOpen(true);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown') setActive((a) => Math.min(results.length - 1, a + 1));
                        else if (e.key === 'ArrowUp') setActive((a) => Math.max(0, a - 1));
                        else if (e.key === 'Enter' && open && results[active]) pick(results[active]);
                        else if (e.key === 'Escape' && open) setOpen(false);
                        else return;
                        e.preventDefault();
                        e.stopPropagation();
                    }}
                    className={`w-full rounded-xl border bg-slate-50 py-2 pr-8 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500 ${error ? 'border-rose-300' : 'border-slate-200'}`}
                />
                {value && !open && (
                    <button
                        type="button"
                        onClick={() => {
                            onChange('');
                            field.current?.focus();
                        }}
                        title="Kosongkan"
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-slate-400 hover:text-rose-600"
                    >
                        <X size={14} />
                    </button>
                )}
            </div>
            {selected && !open && <p className="mt-1 truncate text-[11px] text-slate-500">{selected.nama}</p>}
            {error && <p className="mt-1 text-[11px] text-rose-500">{error}</p>}
            {open && (
                <ul
                    id={`${id}-list`}
                    role="listbox"
                    className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl"
                >
                    {results.length ? (
                        results.map((b, i) => (
                            <li
                                key={b.kode}
                                role="option"
                                aria-selected={b.kode === value}
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    pick(b);
                                }}
                                onMouseEnter={() => setActive(i)}
                                className={`flex cursor-pointer items-start gap-2.5 px-3 py-1.5 ${i === active ? 'bg-brand-50' : ''}`}
                            >
                                <span className="mt-0.5 w-9 shrink-0 rounded bg-sky-100 py-0.5 text-center font-mono text-[11px] font-black text-sky-800">
                                    {b.kode}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-bold text-slate-700">{b.kota}</span>
                                    <span className="block truncate text-[11px] text-slate-400">
                                        {b.nama} · {b.provinsi}
                                    </span>
                                </span>
                            </li>
                        ))
                    ) : (
                        <li className="px-3 py-2 text-xs text-slate-400">Tidak ada bandara untuk “{query}”.</li>
                    )}
                </ul>
            )}
        </div>
    );
}

/** The kind of cuti and what it takes, "✈ BDJ → CGK · 20.11", the way back of a PP flight, "Kereta" and the uang muka, in a list. */
export function TiketBadges({
    trip,
}: {
    trip: {
        tanggal_awal: string;
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
        kategori_cuti?: KategoriCuti | null;
        lumpsum?: number | null;
        potong_tahunan?: number;
        potong_besar?: number;
    };
}) {
    if (!trip.tiket_pesawat && !trip.tiket_kereta && !trip.uang_muka && !trip.kategori_cuti) return null;
    const badge = 'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-bold whitespace-nowrap ring-1 ring-inset';
    const day = (d: string | null) => (d ? fmtDate(d).slice(0, 5) : '');
    return (
        <span className="mt-1 flex flex-wrap gap-1">
            {trip.kategori_cuti && (
                <span className={`${badge} bg-violet-50 text-violet-700 ring-violet-100`} title="Jenis cuti dan potongannya">
                    {KATEGORI_LABEL[trip.kategori_cuti]}
                    {!!trip.potong_besar && <span className="font-normal">· {trip.potong_besar} hr cuti besar</span>}
                    {!!trip.potong_tahunan && <span className="font-normal">· potong {trip.potong_tahunan} hr tahunan</span>}
                </span>
            )}
            {trip.tiket_pesawat && (
                <span className={`${badge} bg-sky-50 text-sky-700 ring-sky-100`} title="Tiket pesawat pergi">
                    <PlaneTakeoff size={11} />
                    {trip.bandara_asal} → {trip.bandara_tujuan}
                    {trip.pesawat_pp && <span className="font-normal">· {day(trip.tanggal_awal)}</span>}
                </span>
            )}
            {trip.tiket_pesawat && trip.pesawat_pp && (
                <span className={`${badge} bg-sky-50 text-sky-700 ring-sky-100`} title="Tiket pesawat pulang (PP)">
                    <PlaneLanding size={11} />
                    {trip.pulang_bandara_asal} → {trip.pulang_bandara_tujuan}
                    <span className="font-normal">· {day(trip.pulang_tanggal)}</span>
                </span>
            )}
            {trip.tiket_kereta && (
                <span className={`${badge} bg-amber-50 text-amber-700 ring-amber-100`} title="Tiket kereta (stasiun di keterangan)">
                    <TrainFront size={11} />
                    Kereta
                </span>
            )}
            {trip.uang_muka && (
                <span className={`${badge} bg-emerald-50 text-emerald-700 ring-emerald-100`} title="Uang muka dinas yang diajukan">
                    <Banknote size={11} />
                    Uang muka Rp {rupiah(trip.uang_muka_nominal)}
                </span>
            )}
            {!!trip.lumpsum && (
                <span className={`${badge} bg-emerald-50 text-emerald-700 ring-emerald-100`} title="Lumpsum cuti lapangan (site × POH × klasifikasi)">
                    <Banknote size={11} />
                    Lumpsum Rp {rupiah(trip.lumpsum)}
                </span>
            )}
        </span>
    );
}
