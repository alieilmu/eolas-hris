import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Loader2, Save, Upload } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { Field, Modal, ghostButton, inputClass, primaryButton } from '@/components/time/ui';
import { JENIS_CELL, SHEET_PATH, describePattern, patternCodeOn, type Pattern, type PeriodInfo, type PeriodOption, type ShiftCode } from '@/lib/time';
import { fmtDate } from '@/lib/format';
import { postForm } from '@/lib/http';

/** Which rows a bulk fill goes to. */
export type Target = 'selected' | 'visible';

function TargetChoice({ value, onChange, selected, visible }: { value: Target; onChange: (t: Target) => void; selected: number; visible: number }) {
    const option = (t: Target, label: string, count: number) => (
        <label
            className={`flex flex-1 cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm ${
                value === t ? 'border-brand-500 bg-brand-50 font-bold text-brand-700' : 'border-slate-200 text-slate-600'
            } ${count ? '' : 'cursor-not-allowed opacity-40'}`}
        >
            <input type="radio" checked={value === t} disabled={!count} onChange={() => onChange(t)} className="accent-brand-600" />
            {label} ({count})
        </label>
    );
    return (
        <Field label="Untuk karyawan">
            <div className="flex gap-2">
                {option('selected', 'Yang dicentang', selected)}
                {option('visible', 'Semua yang tampil', visible)}
            </div>
        </Field>
    );
}

function DayRange({
    period,
    from,
    to,
    onFrom,
    onTo,
}: {
    period: PeriodInfo;
    from: string;
    to: string;
    onFrom: (v: string) => void;
    onTo: (v: string) => void;
}) {
    const first = period.days[0].date;
    const last = period.days[period.days.length - 1].date;
    return (
        <div className="grid grid-cols-2 gap-3">
            <Field label="Dari tanggal">
                <DateInput value={from} onChange={onFrom} min={first} max={last} className={inputClass} />
            </Field>
            <Field label="Sampai tanggal">
                <DateInput value={to} onChange={onTo} min={first} max={last} className={inputClass} />
            </Field>
        </div>
    );
}

const inPeriod = (period: PeriodInfo, iso: string) => !!iso && iso >= period.days[0].date && iso <= period.days[period.days.length - 1].date;

export interface FillRequest {
    fromDay: number;
    toDay: number;
    target: Target;
    overwrite: boolean;
}

/** Roster: fill days from a repeating pattern (e.g. 10:2) starting on a chosen date. */
export function PatternDialog({
    period,
    patterns,
    codes,
    selectedCount,
    visibleCount,
    onApply,
    onClose,
}: {
    period: PeriodInfo;
    patterns: Pattern[];
    codes: ShiftCode[];
    selectedCount: number;
    visibleCount: number;
    onApply: (pattern: Pattern, startIso: string, fill: FillRequest) => void;
    onClose: () => void;
}) {
    const [patternId, setPatternId] = useState(patterns[0]?.id ?? 0);
    const [start, setStart] = useState(period.days[0].date);
    const [from, setFrom] = useState(period.days[0].date);
    const [to, setTo] = useState(period.days[period.days.length - 1].date);
    const [target, setTarget] = useState<Target>(selectedCount ? 'selected' : 'visible');
    const [overwrite, setOverwrite] = useState(true);
    const pattern = patterns.find((p) => p.id === patternId);
    const codeById = new Map(codes.map((c) => [c.id, c]));
    const problem = !pattern
        ? 'Pilih pola.'
        : !start
          ? 'Isi tanggal mulai pola.'
          : !inPeriod(period, from) || !inPeriod(period, to)
            ? `Rentang harus di dalam ${period.label}.`
            : from > to
              ? 'Tanggal akhir sebelum tanggal awal.'
              : null;

    return (
        <Modal
            title="Isi dengan Pola Roster"
            subtitle="Hasilnya masuk ke tabel sebagai perubahan; tekan Simpan untuk menyimpan."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button
                        disabled={!!problem}
                        onClick={() =>
                            pattern && onApply(pattern, start, { fromDay: Number(from.slice(8)), toDay: Number(to.slice(8)), target, overwrite })
                        }
                        className={primaryButton}
                    >
                        Terapkan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                {patterns.length === 0 ? (
                    <p className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Belum ada Pola Roster. Tambahkan di Pengaturan Waktu & Kehadiran.
                    </p>
                ) : (
                    <Field label="Pola">
                        <div className="space-y-1.5">
                            {patterns.map((p) => (
                                <label
                                    key={p.id}
                                    className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2 ${
                                        p.id === patternId ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        checked={p.id === patternId}
                                        onChange={() => setPatternId(p.id)}
                                        className="accent-brand-600"
                                    />
                                    <span className="w-36 shrink-0 text-sm font-bold text-slate-700">{p.name}</span>
                                    <span className="text-xs text-slate-500">{describePattern(p, codes)}</span>
                                </label>
                            ))}
                        </div>
                    </Field>
                )}
                <Field label="Hari pertama pola jatuh pada" hint="Pola berulang maju dan mundur dari tanggal ini.">
                    <DateInput value={start} onChange={setStart} className={inputClass} />
                </Field>
                {pattern && start && (
                    <div className="flex flex-wrap gap-1">
                        {period.days.slice(0, 14).map((d) => {
                            const code = codeById.get(patternCodeOn(pattern, start, d.date) ?? 0);
                            return (
                                <span
                                    key={d.day}
                                    title={fmtDate(d.date)}
                                    className={`flex h-8 w-9 flex-col items-center justify-center rounded-md text-[10px] font-bold ${code ? JENIS_CELL[code.type] : 'bg-slate-50'}`}
                                >
                                    <span className="text-[8px] font-medium opacity-60">{d.day}</span>
                                    {code?.code}
                                </span>
                            );
                        })}
                        <span className="self-center text-[11px] text-slate-400">… contoh 14 hari pertama</span>
                    </div>
                )}
                <DayRange period={period} from={from} to={to} onFrom={setFrom} onTo={setTo} />
                <TargetChoice value={target} onChange={setTarget} selected={selectedCount} visible={visibleCount} />
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" checked={overwrite} onChange={(e) => setOverwrite(e.target.checked)} className="accent-brand-600" />
                    Timpa hari yang sudah terisi
                </label>
                {problem && <p className="text-xs text-rose-500">{problem}</p>}
            </div>
        </Modal>
    );
}

/** Absensi: copy the roster into the chosen days. */
export function CopyRosterDialog({
    period,
    lastDay,
    selectedCount,
    visibleCount,
    onApply,
    onClose,
}: {
    period: PeriodInfo;
    lastDay: number;
    selectedCount: number;
    visibleCount: number;
    onApply: (fill: FillRequest) => void;
    onClose: () => void;
}) {
    const [from, setFrom] = useState(period.days[0].date);
    const [to, setTo] = useState(period.days[Math.max(lastDay, 1) - 1].date);
    const [target, setTarget] = useState<Target>(selectedCount ? 'selected' : 'visible');
    const [overwrite, setOverwrite] = useState(false);
    const problem =
        !inPeriod(period, from) || !inPeriod(period, to)
            ? `Rentang harus di dalam ${period.label}.`
            : from > to
              ? 'Tanggal akhir sebelum tanggal awal.'
              : null;

    return (
        <Modal
            title="Isi Absensi dari Roster"
            subtitle="Kode Roster disalin ke Absensi; hari yang berbeda lalu dikoreksi per sel. Tekan Simpan untuk menyimpan."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button
                        disabled={!!problem}
                        onClick={() => onApply({ fromDay: Number(from.slice(8)), toDay: Number(to.slice(8)), target, overwrite })}
                        className={primaryButton}
                    >
                        Salin
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <DayRange period={period} from={from} to={to} onFrom={setFrom} onTo={setTo} />
                <TargetChoice value={target} onChange={setTarget} selected={selectedCount} visible={visibleCount} />
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" checked={overwrite} onChange={(e) => setOverwrite(e.target.checked)} className="accent-brand-600" />
                    Timpa hari yang sudah terisi (termasuk dari Cuti &amp; Dinas)
                </label>
                {problem && <p className="text-xs text-rose-500">{problem}</p>}
            </div>
        </Modal>
    );
}

export interface ImportedChange {
    employee_id: number;
    date: string;
    shift_code_id: number | null;
}

interface ImportResult {
    changes: ImportedChange[];
    errors: { sheet: string; row: number; employee_number: string; message: string }[];
    rows: number;
    months: { value: string; label: string; cells: number }[];
}

/** A stretch of months: "dari" … "sampai" ("2026-10"). */
export interface MonthSpan {
    from: string;
    until: string;
}

const MAX_MONTHS = 12;
const monthIndex = (v: string) => Number(v.slice(0, 4)) * 12 + Number(v.slice(5, 7)) - 1;
const monthValue = (i: number) => `${Math.floor(i / 12)}-${String((i % 12) + 1).padStart(2, '0')}`;
export const spanLength = (s: MonthSpan) => monthIndex(s.until) - monthIndex(s.from) + 1;

/** Month range with shortcuts: this month, this quarter, this year. */
function MonthRange({
    period,
    periods,
    value,
    onChange,
}: {
    period: PeriodInfo;
    periods: PeriodOption[];
    value: MonthSpan;
    onChange: (s: MonthSpan) => void;
}) {
    const options = [...periods].sort((a, b) => a.value.localeCompare(b.value));
    const year = Number(period.value.slice(0, 4));
    const q = Math.floor((Number(period.value.slice(5, 7)) - 1) / 3);
    const quarter = { from: monthValue(year * 12 + q * 3), until: monthValue(year * 12 + q * 3 + 2) };
    const shortcuts = [
        { label: period.label, span: { from: period.value, until: period.value } },
        { label: `Kuartal ${q + 1} ${year}`, span: quarter },
        { label: `Tahun ${year}`, span: { from: `${year}-01`, until: `${year}-12` } },
    ];
    const length = spanLength(value);
    const problem = length < 1 ? 'Bulan akhir sebelum bulan awal.' : length > MAX_MONTHS ? `Paling banyak ${MAX_MONTHS} bulan.` : null;
    const select = (key: keyof MonthSpan) => (
        <select
            value={value[key]}
            onChange={(e) => onChange({ ...value, [key]: e.target.value })}
            className={inputClass}
            aria-label={key === 'from' ? 'Dari bulan' : 'Sampai bulan'}
        >
            {options.map((p) => (
                <option key={p.value} value={p.value}>
                    {p.label}
                </option>
            ))}
        </select>
    );

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5">
                {shortcuts.map((s) => {
                    const active = s.span.from === value.from && s.span.until === value.until;
                    return (
                        <button
                            key={s.label}
                            type="button"
                            onClick={() => onChange(s.span)}
                            className={`rounded-lg border px-2.5 py-1 text-xs font-bold ${active ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}
                        >
                            {s.label}
                        </button>
                    );
                })}
            </div>
            <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                {select('from')}
                <span className="text-xs text-slate-400">s/d</span>
                {select('until')}
            </div>
            <p className={`text-[11px] ${problem ? 'text-rose-500' : 'text-slate-400'}`}>
                {problem ?? `${length} bulan · semua bulan dalam satu sheet`}
            </p>
        </div>
    );
}

/**
 * Reads a filled file (one month, a quarter, a year). The open month alone goes into the table as
 * changes to check and save there; several months are saved at once after this preview.
 */
export function ImportDialog({
    type,
    period,
    periods,
    templateUrl,
    hasDraft,
    onApply,
    onClose,
}: {
    type: 'schedule' | 'attendance';
    period: PeriodInfo;
    periods: PeriodOption[];
    templateUrl: (span: MonthSpan) => string;
    /** Unsaved cells in the table: saving another import first would mix them up. */
    hasDraft: boolean;
    onApply: (changes: ImportedChange[]) => void;
    onClose: () => void;
}) {
    const [span, setSpan] = useState<MonthSpan>({ from: period.value, until: period.value });
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<ImportResult | null>(null);
    const title = type === 'schedule' ? 'Roster' : 'Absensi';

    const read = async () => {
        if (!file) return;
        setBusy(true);
        setError(null);
        const body = new FormData();
        body.append('file', file);
        try {
            setResult(await postForm<ImportResult>(`${SHEET_PATH[type]}/import`, body));
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    const saveAll = () => {
        if (!file) return;
        router.post(`${SHEET_PATH[type]}/import/save`, { file } as never, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onClose,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.'),
        });
    };

    // Only the open month: check it in the table first. Anything else: save straight away.
    const onlyThisMonth = !!result && result.months.length === 1 && result.months[0].value === period.value;
    const cells = result?.changes.length ?? 0;
    const spanLabel =
        result && result.months.length
            ? `${result.months[0].label}${result.months.length > 1 ? ` – ${result.months[result.months.length - 1].label}` : ''}`
            : '';

    return (
        <Modal
            title={`Import ${title}`}
            subtitle="Satu file boleh berisi beberapa bulan dalam satu sheet, mis. satu kuartal atau satu tahun."
            onClose={onClose}
            width="max-w-2xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    {!result ? (
                        <button disabled={!file || busy} onClick={read} className={primaryButton}>
                            {busy ? <Loader2 size={16} className="animate-spin" /> : <Upload size={16} />}
                            Baca file
                        </button>
                    ) : onlyThisMonth ? (
                        <button disabled={!cells} onClick={() => onApply(result.changes)} className={primaryButton}>
                            <CheckCircle2 size={16} />
                            Masukkan {cells} sel ke tabel
                        </button>
                    ) : (
                        <button disabled={!cells || busy || hasDraft} onClick={saveAll} className={primaryButton}>
                            {busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                            Simpan {cells} sel ({spanLabel})
                        </button>
                    )}
                </>
            }
        >
            <div className="space-y-4">
                <div className="space-y-2 rounded-xl border border-slate-200 p-4">
                    <p className="flex items-center gap-2 text-sm font-bold text-slate-700">
                        <FileSpreadsheet size={18} className="text-emerald-600" />
                        Unduh template
                    </p>
                    <MonthRange period={period} periods={periods} value={span} onChange={setSpan} />
                    <a
                        href={spanLength(span) >= 1 && spanLength(span) <= MAX_MONTHS ? templateUrl(span) : undefined}
                        className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-600 hover:underline"
                    >
                        <Download size={15} />
                        Unduh template (berisi data saat ini, sesuai filter)
                    </a>
                </div>

                <Field label="File Excel (.xlsx)">
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        onChange={(e) => {
                            setFile(e.target.files?.[0] ?? null);
                            setResult(null);
                            setError(null);
                        }}
                        className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-bold file:text-brand-700 hover:file:bg-brand-100"
                    />
                </Field>

                {error && <p className="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</p>}

                {result && (
                    <div className="space-y-3">
                        <div className="grid grid-cols-3 gap-2">
                            {[
                                { label: 'Baris dibaca', value: result.rows, tone: 'text-slate-700' },
                                { label: 'Sel siap masuk', value: cells, tone: 'text-emerald-700' },
                                { label: 'Baris bermasalah', value: result.errors.filter((e) => e.row > 0).length, tone: 'text-rose-600' },
                            ].map((c) => (
                                <div key={c.label} className="rounded-xl border border-slate-200 px-3 py-2.5">
                                    <p className="text-[11px] font-bold text-slate-400">{c.label}</p>
                                    <p className={`text-xl font-black tabular-nums ${c.tone}`}>{c.value.toLocaleString('id-ID')}</p>
                                </div>
                            ))}
                        </div>
                        {result.months.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {result.months.map((m) => (
                                    <span
                                        key={m.value}
                                        className={`rounded-lg px-2 py-1 text-[11px] font-bold ${m.value === period.value ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'}`}
                                    >
                                        {m.label}: {m.cells.toLocaleString('id-ID')} sel
                                    </span>
                                ))}
                            </div>
                        )}
                        {result.months.length > 0 &&
                            (onlyThisMonth ? (
                                <p className="text-xs text-slate-500">
                                    File berisi bulan yang sedang dibuka: datanya masuk ke tabel (mode Edit) untuk diperiksa, lalu Simpan.
                                </p>
                            ) : (
                                <p className="flex items-start gap-2 text-xs text-amber-700">
                                    <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                                    {hasDraft
                                        ? 'Simpan atau batalkan dulu perubahan di tabel, lalu import lagi.'
                                        : `File berisi ${result.months.length} bulan, jadi langsung disimpan (menimpa hari yang terisi di file).`}
                                </p>
                            ))}
                        {result.errors.length > 0 && (
                            <div className="max-h-56 overflow-y-auto rounded-xl border border-rose-100">
                                <table className="w-full text-left text-xs">
                                    <thead className="sticky top-0 bg-rose-50 text-[10px] font-bold text-rose-500 uppercase">
                                        <tr>
                                            <th className="px-3 py-2">Sheet</th>
                                            <th className="px-3 py-2">Baris</th>
                                            <th className="px-3 py-2">NRP</th>
                                            <th className="px-3 py-2">Masalah</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-rose-50">
                                        {result.errors.map((e, i) => (
                                            <tr key={i}>
                                                <td className="px-3 py-2 whitespace-nowrap">{e.sheet || '-'}</td>
                                                <td className="px-3 py-2 tabular-nums">{e.row || '-'}</td>
                                                <td className="px-3 py-2 tabular-nums">{e.employee_number || '-'}</td>
                                                <td className="px-3 py-2 text-rose-700">{e.message}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </Modal>
    );
}

/** Asks the months, Kode Shift or DWS, and the layout before downloading. */
export function ExportDialog({
    type,
    period,
    periods,
    exportUrl,
    onClose,
}: {
    type: 'schedule' | 'attendance';
    period: PeriodInfo;
    periods: PeriodOption[];
    exportUrl: (format: string, shape: string, span: MonthSpan) => string;
    onClose: () => void;
}) {
    const [span, setSpan] = useState<MonthSpan>({ from: period.value, until: period.value });
    const [format, setFormat] = useState<'code' | 'daily_work_schedule'>('code');
    const [shape, setBentuk] = useState<'monthly' | 'daily'>('monthly');
    const valid = spanLength(span) >= 1 && spanLength(span) <= MAX_MONTHS;
    const choice = <T extends string>(value: T, current: T, set: (v: T) => void, label: string, hint: string) => (
        <label
            className={`flex flex-1 cursor-pointer gap-2 rounded-xl border px-3 py-2.5 ${current === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'}`}
        >
            <input type="radio" checked={current === value} onChange={() => set(value)} className="mt-0.5 accent-brand-600" />
            <span>
                <b className="block text-sm text-slate-700">{label}</b>
                <span className="text-[11px] text-slate-400">{hint}</span>
            </span>
        </label>
    );

    return (
        <Modal
            title={`Export ${type === 'schedule' ? 'Roster' : 'Absensi'}`}
            subtitle="Mengikuti filter yang sedang dipakai."
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <a
                        href={valid ? exportUrl(format, shape, span) : undefined}
                        aria-disabled={!valid}
                        onClick={() => valid && setTimeout(onClose, 300)}
                        className={`${primaryButton} ${valid ? '' : 'pointer-events-none opacity-50'}`}
                    >
                        <Download size={16} />
                        Unduh Excel
                    </a>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Periode">
                    <MonthRange period={period} periods={periods} value={span} onChange={setSpan} />
                </Field>
                <Field label="Isi sel">
                    <div className="flex gap-2">
                        {choice('code', format, setFormat, 'Kode Shift', 'Kode yang dipakai di aplikasi, mis. D, N, OFF')}
                        {choice('daily_work_schedule', format, setFormat, 'DWS', 'Terjemahan Kode Shift ke DWS (Master Data)')}
                    </div>
                </Field>
                <Field label="Bentuk">
                    <div className="flex gap-2">
                        {choice(
                            'monthly',
                            shape,
                            setBentuk,
                            'Tabel',
                            'Satu baris per karyawan, kolom per tanggal (beberapa bulan tetap satu sheet)',
                        )}
                        {choice('daily', shape, setBentuk, 'Daftar', 'Satu baris per karyawan per tanggal (NRP, Tanggal, Kode)')}
                    </div>
                </Field>
            </div>
        </Modal>
    );
}
