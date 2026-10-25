import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowUp, Check, Loader2, Plus, Trash2 } from 'lucide-react';
import { SEGMENT_LABEL, describeFormat, formatLength, segmentPositions, type NrpFormat, type Segment, type SegmentType } from '@/lib/employee_number';

export interface FormatProps extends NrpFormat {
    mismatch: { category: string[]; point_of_hire: string[]; employees: number };
    example: string;
    describe: string;
}

const input = 'rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const COLORS: Record<SegmentType, string> = {
    text: 'bg-violet-500',
    category: 'bg-brand-600',
    point_of_hire: 'bg-emerald-600',
    year: 'bg-amber-500',
    sequence: 'bg-slate-500',
};
const DEFAULT_LENGTH: Record<Exclude<SegmentType, 'text'>, number> = { category: 1, point_of_hire: 2, year: 2, sequence: 3 };

/** An example NRP of a format, filled with "1"s for codes, a year and the first nomor urut. */
function example(format: NrpFormat): string {
    if (format.mode === 'free') return 'EMP-0001';
    return format.segments
        .map((s) => {
            if (s.type === 'text') return (s.value ?? '').toUpperCase();
            if (s.type === 'year') return String(new Date().getFullYear()).slice(-(s.length ?? 2));
            if (s.type === 'sequence') return '1'.padStart(s.length ?? 3, '0');
            return 'A'.repeat(s.length ?? 1);
        })
        .join('');
}

/** Builds the company's Nomor Induk: fixed-width segments, or free text. */
export function EmployeeNumberFormatEditor({ format, onSaved }: { format: FormatProps; onSaved?: () => void }) {
    const [mode, setMode] = useState(format.mode);
    const [segments, setSegments] = useState<Segment[]>(format.segments);
    const [maxLength, setMaxLength] = useState(format.max_length);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const draft: NrpFormat = { mode, segments, max_length: maxLength };
    const length = formatLength(draft);
    const used = new Set(segments.map((s) => s.type));

    const update = (i: number, patch: Partial<Segment>) => setSegments((all) => all.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const move = (i: number, by: number) =>
        setSegments((all) => {
            const next = [...all];
            [next[i], next[i + by]] = [next[i + by], next[i]];
            return next;
        });
    const add = (type: SegmentType) =>
        setSegments((all) => {
            const segment: Segment = type === 'text' ? { type, value: '' } : { type, length: DEFAULT_LENGTH[type] };
            const seq = all.findIndex((s) => s.type === 'sequence');
            return seq === -1 || type === 'sequence' ? [...all, segment] : [...all.slice(0, seq), segment, ...all.slice(seq)];
        });
    const save = () =>
        router.put('/core-hr/settings/employee-number-format', { mode, segments, max_length: maxLength } as never, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setBusy(true);
                setError(null);
            },
            onFinish: () => setBusy(false),
            onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
            onSuccess: () => onSaved?.(),
        });

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {(
                    [
                        ['pattern', 'Berpola', 'Disusun dari bagian; jenis karyawan, lokasi penerimaan & tahun masuk dibaca dari NRP, nomor berikutnya disarankan otomatis.'],
                        ['free', 'Bebas', 'Nomor apa saja yang unik (mis. hasil sistem lama); tidak ada yang dibaca dari NRP.'],
                    ] as const
                ).map(([value, label, hint]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => setMode(value)}
                        className={`max-w-xs rounded-2xl border px-4 py-3 text-left ${mode === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
                    >
                        <p className={`text-sm font-bold ${mode === value ? 'text-brand-700' : 'text-slate-700'}`}>{label}</p>
                        <p className="mt-0.5 text-[11px] text-slate-500">{hint}</p>
                    </button>
                ))}
            </div>

            {mode === 'free' ? (
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    Panjang maksimal
                    <input type="number" min={3} max={20} value={maxLength} onChange={(e) => setMaxLength(Number(e.target.value))} className={`${input} w-20`} />
                    karakter
                </label>
            ) : (
                <>
                    <div className="space-y-2">
                        {segments.map((s, i) => (
                            <div key={i} className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-black text-white ${COLORS[s.type]}`}>{i + 1}</span>
                                <span className="w-36 text-sm font-semibold text-slate-700">{SEGMENT_LABEL[s.type]}</span>
                                {s.type === 'text' ? (
                                    <input
                                        value={s.value ?? ''}
                                        onChange={(e) => update(i, { value: e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '') })}
                                        placeholder="mis. TY"
                                        maxLength={10}
                                        className={`${input} w-28 font-mono`}
                                        aria-label="Teks tetap"
                                    />
                                ) : s.type === 'year' ? (
                                    <select value={s.length} onChange={(e) => update(i, { length: Number(e.target.value) })} className={input} aria-label="Digit tahun">
                                        <option value={2}>2 digit (24)</option>
                                        <option value={4}>4 digit (2024)</option>
                                    </select>
                                ) : (
                                    <label className="flex items-center gap-1.5 text-xs text-slate-500">
                                        <input
                                            type="number"
                                            min={s.type === 'sequence' ? 2 : 1}
                                            max={s.type === 'sequence' ? 8 : 4}
                                            value={s.length}
                                            onChange={(e) => update(i, { length: Number(e.target.value) })}
                                            className={`${input} w-16`}
                                            aria-label={`Panjang ${SEGMENT_LABEL[s.type]}`}
                                        />
                                        karakter{s.type === 'category' || s.type === 'point_of_hire' ? ' (huruf/angka)' : ''}
                                    </label>
                                )}
                                <div className="ml-auto flex items-center gap-1">
                                    <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" title="Naik">
                                        <ArrowUp size={14} />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={i === segments.length - 1}
                                        onClick={() => move(i, 1)}
                                        className="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30"
                                        title="Turun"
                                    >
                                        <ArrowDown size={14} />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setSegments((all) => all.filter((_, j) => j !== i))}
                                        className="rounded p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                        title="Hapus bagian"
                                    >
                                        <Trash2 size={14} />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(['text', 'category', 'point_of_hire', 'year', 'sequence'] as SegmentType[])
                            .filter((t) => t === 'text' || !used.has(t))
                            .map((t) => (
                                <button key={t} type="button" onClick={() => add(t)} className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-2.5 py-1 text-xs font-bold text-slate-500 hover:border-brand-400 hover:text-brand-600">
                                    <Plus size={12} /> {SEGMENT_LABEL[t]}
                                </button>
                            ))}
                    </div>
                </>
            )}

            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">Hasil</p>
                <p className="mt-1 font-mono text-lg font-bold tracking-widest text-slate-800">{example(draft)}</p>
                <p className="text-xs text-slate-500">
                    {describeFormat(draft)}
                    {length !== null && ` = ${length} karakter`}
                </p>
                {mode === 'pattern' && (
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {segmentPositions(draft).map(({ segment, where }, i) => (
                            <span key={i} className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-bold text-slate-600">
                                <span className={`rounded px-1 text-[10px] font-black text-white ${COLORS[segment.type]}`}>{where}</span>
                                {segment.type === 'text' ? `"${segment.value}"` : SEGMENT_LABEL[segment.type]}
                            </span>
                        ))}
                    </div>
                )}
            </div>

            {(format.mismatch.category.length > 0 || format.mismatch.point_of_hire.length > 0 || format.mismatch.employees > 0) && (
                <div className="space-y-1 rounded-xl bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    <p className="flex items-center gap-1.5 font-bold">
                        <AlertTriangle size={14} /> Belum cocok dengan format tersimpan
                    </p>
                    {format.mismatch.category.length > 0 && <p>Kode jenis karyawan dengan panjang lain: {format.mismatch.category.join(', ')} — ubah di Jenis Karyawan.</p>}
                    {format.mismatch.point_of_hire.length > 0 && <p>Kode lokasi penerimaan dengan panjang lain: {format.mismatch.point_of_hire.join(', ')} — ubah di Lokasi Penerimaan.</p>}
                    {format.mismatch.employees > 0 && (
                        <p>{format.mismatch.employees} karyawan memakai NRP lama yang tidak sesuai format. NRP mereka tetap; hanya data yang dibaca dari NRP yang kosong.</p>
                    )}
                </div>
            )}

            {error && <p className="rounded-xl bg-rose-50 px-4 py-2 text-sm text-rose-600">{error}</p>}
            <button type="button" onClick={save} disabled={busy} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan format
            </button>
        </div>
    );
}
