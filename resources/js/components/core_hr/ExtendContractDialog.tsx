import { useState } from 'react';
import { router } from '@inertiajs/react';
import { CalendarPlus, Loader2, X } from 'lucide-react';
import { addMonthsIso, endDateForMonths, isValidIsoDate } from '@/lib/contractDates';
import type { PkwtListItem } from '@/types/core_hr';
import { DateInput } from '@/components/DateInput';
import { fmtDate } from '@/lib/format';

type Mode = 'month' | 'date';

const MONTH_PRESETS = [3, 6, 12, 24];
const MAX_PKWT_PERIODS = 6;
const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI'];
const pkwtLabel = (n: number) => `PKWT ${ROMAN[n - 1] ?? n}`;

const fmt = fmtDate;

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/** Adds the next contract period, either by a number of months (end date derived) or by explicit dates. */
export function ExtendContractDialog({ item, onClose }: { item: PkwtListItem; onClose: () => void }) {
    const [mode, setMode] = useState<Mode>('month');
    const [start, setStart] = useState(item.next_start);
    const [months, setMonths] = useState(12);
    const [manualEnd, setManualEnd] = useState(() => endDateForMonths(item.next_start, 12));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const atLimit = item.period_count >= MAX_PKWT_PERIODS;

    const startValid = isValidIsoDate(start);
    const monthsValid = Number.isInteger(months) && months >= 1 && months <= 120;
    // In "jumlah bulan" mode the end date is always derived, never typed.
    const end = mode === 'month' ? (startValid && monthsValid ? endDateForMonths(start, months) : '') : manualEnd;
    const endValid = isValidIsoDate(end);
    const orderOk = startValid && endValid && end >= start;

    const problem = !startValid
        ? 'Tanggal awal belum valid.'
        : mode === 'month' && !monthsValid
          ? 'Jumlah bulan harus antara 1 dan 120.'
          : !endValid
            ? 'Tanggal akhir belum valid.'
            : !orderOk
              ? 'Tanggal akhir tidak boleh lebih awal dari tanggal awal.'
              : null;

    const overlapsPrevious = startValid && start <= item.end_date;

    const submit = () => {
        if (problem || atLimit) return;
        router.post(
            `/core-hr/employees/${item.employee_id}/contract`,
            { start_date: start, end_date: end },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: onClose,
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan perpanjangan PKWT.'),
            },
        );
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
            <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">Perpanjang PKWT</h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {item.name} • NRP {item.employee_number}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="space-y-5 p-6">
                    <div className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                        Kontrak terakhir: <span className="font-bold text-slate-700">{item.period_label}</span>, {fmt(item.start_date)} s/d{' '}
                        {fmt(item.end_date)}. Perpanjangan ini akan tercatat sebagai{' '}
                        <span className="font-bold text-slate-700">{pkwtLabel(item.period_count + 1)}</span>.
                    </div>

                    {atLimit && (
                        <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                            Sudah mencapai {MAX_PKWT_PERIODS} periode PKWT. Karyawan ini perlu diangkat sebagai pegawai tetap (Pengangkatan Pegawai di
                            profil).
                        </div>
                    )}

                    {error && <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</div>}

                    <div className="flex gap-1 rounded-xl bg-slate-100 p-1">
                        {(
                            [
                                ['month', 'Berdasarkan jumlah bulan'],
                                ['date', 'Berdasarkan tanggal'],
                            ] as [Mode, string][]
                        ).map(([id, label]) => (
                            <button
                                key={id}
                                onClick={() => setMode(id)}
                                className={`flex-1 rounded-lg px-3 py-1.5 text-xs font-bold transition-all ${
                                    mode === id ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-500">Tanggal Awal *</label>
                        <DateInput value={start} onChange={setStart} className={inputClass} />
                        {overlapsPrevious && (
                            <p className="mt-1.5 text-[11px] text-amber-600">
                                Tanggal awal tidak lebih dari akhir kontrak sebelumnya ({fmt(item.end_date)}) — periodenya akan tumpang
                                tindih.
                            </p>
                        )}
                    </div>

                    {mode === 'month' ? (
                        <div>
                            <label className="mb-1.5 block text-xs font-bold text-slate-500">Jumlah Bulan *</label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="number"
                                    min={1}
                                    max={120}
                                    value={Number.isNaN(months) ? '' : months}
                                    onChange={(e) => setMonths(e.target.value === '' ? NaN : Number(e.target.value))}
                                    className={`${inputClass} w-24`}
                                />
                                {MONTH_PRESETS.map((m) => (
                                    <button
                                        key={m}
                                        onClick={() => setMonths(m)}
                                        className={`rounded-lg border px-3 py-2 text-xs font-bold transition-all ${
                                            months === m
                                                ? 'border-brand-600 bg-brand-600 text-white'
                                                : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'
                                        }`}
                                    >
                                        {m} bln
                                    </button>
                                ))}
                            </div>
                            <div className="mt-3 rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm">
                                <span className="text-xs text-slate-500">Tanggal akhir otomatis:</span>{' '}
                                <span className="font-bold text-brand-700">{endValid ? fmt(end) : '—'}</span>
                                {startValid && monthsValid && (
                                    <p className="mt-0.5 text-[11px] text-slate-400">
                                        {fmt(start)} + {months} bulan (sampai {fmt(addMonthsIso(start, months))}, dikurangi 1 hari)
                                    </p>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div>
                            <label className="mb-1.5 block text-xs font-bold text-slate-500">Tanggal Akhir *</label>
                            <DateInput value={manualEnd} onChange={setManualEnd} className={inputClass} />
                        </div>
                    )}

                    {problem && <p className="text-xs text-rose-500">{problem}</p>}
                </div>

                <div className="flex justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100">
                        Batal
                    </button>
                    <button
                        onClick={submit}
                        disabled={saving || !!problem || atLimit}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white transition-all hover:bg-brand-700 disabled:opacity-50"
                    >
                        {saving ? <Loader2 className="animate-spin" size={16} /> : <CalendarPlus size={16} />}
                        Simpan Perpanjangan
                    </button>
                </div>
            </div>
        </div>
    );
}
