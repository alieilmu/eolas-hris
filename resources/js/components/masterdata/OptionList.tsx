import { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, ClipboardPaste, Lock, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useDirtyForm } from './DirtyGuard';

export interface OptionValue {
    id: number;
    nilai: string;
    /** Relied on by the program (e.g. "Menikah"): cannot be renamed or removed. */
    sistem: boolean;
    /** How many records hold this value. */
    usage: number;
}

export interface OptionCategory {
    kategori: string;
    label: string;
    values: OptionValue[];
}

const BASE = '/manpower/master-data/opsi';
const visit = { preserveScroll: true, preserveState: true } as const;
const inputClass = 'rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/**
 * One choice list: reorder, rename (follows through to the records using it), add one value or many
 * at once (pasted from an Excel column), and remove unused values.
 */
export function OptionList({
    category,
    usageNoun = 'karyawan',
}: {
    category: OptionCategory;
    /** What uses the values, e.g. "jabatan". */ usageNoun?: string;
}) {
    const [newValue, setNewValue] = useState('');
    const [pasting, setPasting] = useState(false);
    const [pasted, setPasted] = useState('');
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editValue, setEditValue] = useState('');
    const [error, setError] = useState<string | null>(null);

    const onError = (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.');

    /** Resolves true once saved, false when refused (the error is shown above the list). */
    const send = (method: 'post' | 'put', url: string, nilai: string, onSuccess: () => void) =>
        new Promise<boolean>((resolve) => {
            setError(null);
            router[method](
                url,
                { nilai },
                {
                    ...visit,
                    onSuccess: () => {
                        onSuccess();
                        resolve(true);
                    },
                    onError: (errors) => {
                        onError(errors);
                        resolve(false);
                    },
                },
            );
        });

    const add = () =>
        newValue.trim() ? send('post', `${BASE}/${category.kategori}`, newValue.trim(), () => setNewValue('')) : Promise.resolve(true);

    const editingOption = category.values.find((o) => o.id === editingId);

    const rename = (option: OptionValue) => {
        const value = editValue.trim();
        if (
            value &&
            value !== option.nilai &&
            option.usage > 0 &&
            !confirm(`Ganti "${option.nilai}" menjadi "${value}"? ${option.usage} data ${usageNoun} yang memakainya ikut diperbarui.`)
        ) {
            return Promise.resolve(false);
        }
        return send('put', `${BASE}/${option.id}`, value, () => setEditingId(null));
    };

    // Unsaved typing holds back navigation (see DirtyGuard).
    useDirtyForm(`opsi-${category.kategori}#new`, newValue.trim() !== '', {
        label: `nilai baru di ${category.label}`,
        save: add,
        discard: () => setNewValue(''),
    });
    useDirtyForm(`opsi-${category.kategori}#edit`, !!editingOption && editValue.trim() !== editingOption.nilai, {
        label: `perubahan di ${category.label}`,
        save: () => (editingOption ? rename(editingOption) : Promise.resolve(true)),
        discard: () => setEditingId(null),
    });

    const remove = (option: OptionValue) => {
        if (!confirm(`Hapus "${option.nilai}" dari ${category.label}?`)) return;
        setError(null);
        router.delete(`${BASE}/${option.id}`, { ...visit, onError });
    };

    const move = (option: OptionValue, arah: 'naik' | 'turun') => router.post(`${BASE}/${option.id}/geser`, { arah }, visit);

    const pasteMany = () => {
        setError(null);
        router.post(
            `${BASE}/${category.kategori}/banyak`,
            { nilai_banyak: pasted },
            {
                ...visit,
                onSuccess: () => {
                    setPasted('');
                    setPasting(false);
                },
                onError,
            },
        );
    };
    const pastedCount = pasted.split(/\r?\n/).filter((l) => l.trim()).length;

    return (
        <div className="space-y-3">
            <div className="flex justify-end">
                <button
                    onClick={() => setPasting((v) => !v)}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50"
                >
                    <ClipboardPaste size={14} />
                    Tempel banyak
                </button>
            </div>
            {pasting && (
                <div className="space-y-2 rounded-2xl border border-slate-200 bg-slate-50/60 p-3">
                    <p className="text-xs text-slate-500">
                        Tempel satu nilai per baris (mis. salin satu kolom dari Excel). Nilai yang sudah ada dilewati.
                    </p>
                    <textarea
                        value={pasted}
                        onChange={(e) => setPasted(e.target.value)}
                        rows={8}
                        className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-brand-500"
                        placeholder={'ACCOUNTING\nASSET MANAGEMENT\nPRODUCTION'}
                    />
                    <div className="flex justify-end gap-2">
                        <button
                            onClick={() => setPasting(false)}
                            className="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100"
                        >
                            Batal
                        </button>
                        <button
                            onClick={pasteMany}
                            disabled={!pastedCount}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                        >
                            <Plus size={14} />
                            Tambahkan {pastedCount || ''} nilai
                        </button>
                    </div>
                </div>
            )}
            {error && (
                <div className="flex items-start justify-between gap-3 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                    <span>{error}</span>
                    <button onClick={() => setError(null)} className="shrink-0 opacity-60 hover:opacity-100" aria-label="Tutup">
                        <X size={16} />
                    </button>
                </div>
            )}

            <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200">
                {category.values.map((option, i) => {
                    const editing = editingId === option.id;
                    const removeBlocked = option.sistem
                        ? 'Dipakai aturan program — tidak bisa dihapus'
                        : option.usage > 0
                          ? `Masih dipakai ${option.usage} data ${usageNoun}`
                          : null;

                    return (
                        <li key={option.id} className="flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50/60">
                            <div className="flex flex-col">
                                <button
                                    onClick={() => move(option, 'naik')}
                                    disabled={i === 0}
                                    title="Naikkan"
                                    className="text-slate-300 hover:text-slate-600 disabled:opacity-30"
                                >
                                    <ArrowUp size={13} />
                                </button>
                                <button
                                    onClick={() => move(option, 'turun')}
                                    disabled={i === category.values.length - 1}
                                    title="Turunkan"
                                    className="text-slate-300 hover:text-slate-600 disabled:opacity-30"
                                >
                                    <ArrowDown size={13} />
                                </button>
                            </div>

                            <div className="min-w-0 flex-1">
                                {editing ? (
                                    <input
                                        value={editValue}
                                        onChange={(e) => setEditValue(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && rename(option)}
                                        className={`${inputClass} w-full max-w-xs`}
                                        autoFocus
                                    />
                                ) : (
                                    <span className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                        {option.nilai}
                                        {option.sistem && (
                                            <span title="Dipakai aturan program" className="text-slate-300">
                                                <Lock size={12} />
                                            </span>
                                        )}
                                    </span>
                                )}
                            </div>

                            <span className="text-[11px] text-slate-400 tabular-nums">
                                {option.usage > 0 ? `${option.usage} ${usageNoun}` : 'belum dipakai'}
                            </span>

                            <div className="flex gap-1">
                                {editing ? (
                                    <>
                                        <button
                                            onClick={() => rename(option)}
                                            title="Simpan"
                                            className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50"
                                        >
                                            <Check size={15} />
                                        </button>
                                        <button
                                            onClick={() => setEditingId(null)}
                                            title="Batal"
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"
                                        >
                                            <X size={15} />
                                        </button>
                                    </>
                                ) : (
                                    <>
                                        <button
                                            onClick={() => {
                                                setEditingId(option.id);
                                                setEditValue(option.nilai);
                                                setError(null);
                                            }}
                                            disabled={option.sistem}
                                            title={option.sistem ? 'Dipakai aturan program — tidak bisa diganti' : 'Ganti nama'}
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                                        >
                                            <Pencil size={15} />
                                        </button>
                                        <button
                                            onClick={() => remove(option)}
                                            disabled={!!removeBlocked}
                                            title={removeBlocked ?? 'Hapus'}
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    </>
                                )}
                            </div>
                        </li>
                    );
                })}

                <li className="flex items-center gap-2 bg-slate-50/60 px-4 py-2.5">
                    <input
                        value={newValue}
                        onChange={(e) => setNewValue(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && add()}
                        placeholder={`Nilai baru untuk ${category.label}`}
                        className={`${inputClass} flex-1`}
                    />
                    <button
                        onClick={add}
                        disabled={!newValue.trim()}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                        <Plus size={14} />
                        Tambah
                    </button>
                </li>
            </ul>
        </div>
    );
}
