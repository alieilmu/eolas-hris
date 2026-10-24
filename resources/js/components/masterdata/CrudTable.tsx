import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Check, Loader2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { fmtDate } from '@/lib/format';
import { useDirtyForm } from './DirtyGuard';

export interface Option {
    value: string | number;
    label: string;
    /** Shown in the table instead of the label (e.g. without the indentation of a tree picker). */
    display?: string;
}

export interface Field {
    key: string;
    label: string;
    type?: 'text' | 'select' | 'date' | 'time';
    options?: Option[];
    placeholder?: string;
    /** Tailwind width class for the input, e.g. "w-20". */
    width?: string;
    /** Allow an empty choice in a select. */
    nullable?: boolean;
}

type FormData = Record<string, string>;

interface Props<T extends { id: number }> {
    /** What the list holds, e.g. "Jabatan" — used in the unsaved-changes warning. */
    title: string;
    rows: T[];
    fields: Field[];
    /** Resource URL: POST to it, PUT/DELETE to `${baseUrl}/${id}`. */
    baseUrl: string;
    toForm: (row: T) => FormData;
    emptyForm: FormData;
    /** Read-only columns after the fields (e.g. how often a code is used). */
    extraColumns?: { label: string; render: (row: T) => ReactNode }[];
    /** Why a row cannot be deleted (shown instead of allowing it), or null. */
    deleteBlockedReason?: (row: T) => string | null;
    describe: (row: T) => string;
    emptyText?: string;
}

const inputClass = 'w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const visit = { preserveScroll: true, preserveState: true } as const;
const firstError = (errors: Record<string, string>) => Object.values(errors)[0] ?? 'Gagal menyimpan.';

/** A master-data list with inline add, edit and delete. */
export function CrudTable<T extends { id: number }>({
    title,
    rows,
    fields,
    baseUrl,
    toForm,
    emptyForm,
    extraColumns = [],
    deleteBlockedReason,
    describe,
    emptyText = 'Belum ada data.',
}: Props<T>) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<FormData>(emptyForm);
    const [newForm, setNewForm] = useState<FormData>(emptyForm);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /** Resolves true once saved, false when the server refused (the error is shown above the table). */
    const run = (method: 'post' | 'put' | 'delete', url: string, data: FormData | undefined, onSuccess: () => void) =>
        new Promise<boolean>((resolve) => {
            setError(null);
            const options = {
                ...visit,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    onSuccess();
                    resolve(true);
                },
                onError: (errors: Record<string, string>) => {
                    setError(firstError(errors));
                    resolve(false);
                },
            };
            if (method === 'delete') router.delete(url, options);
            else router[method](url, data ?? {}, options);
        });

    const editingRow = rows.find((r) => r.id === editingId);
    const saveEdit = () => (editingRow ? run('put', `${baseUrl}/${editingRow.id}`, editForm, () => setEditingId(null)) : Promise.resolve(true));
    const saveNew = () => run('post', baseUrl, newForm, () => setNewForm(emptyForm));

    // Unsaved typing in the edit row or the add row holds back navigation (see DirtyGuard).
    const same = (a: FormData, b: FormData) => JSON.stringify(a) === JSON.stringify(b);
    useDirtyForm(`${baseUrl}#edit`, !!editingRow && !same(editForm, toForm(editingRow)), {
        label: `perubahan di ${title}`,
        save: saveEdit,
        discard: () => setEditingId(null),
    });
    useDirtyForm(`${baseUrl}#new`, !same(newForm, emptyForm), {
        label: `data baru di ${title}`,
        save: saveNew,
        discard: () => setNewForm(emptyForm),
    });

    const renderInput = (field: Field, form: FormData, setForm: (f: FormData) => void) => {
        const set = (value: string) => setForm({ ...form, [field.key]: value });
        if (field.type === 'select') {
            return (
                <select value={form[field.key] ?? ''} onChange={(e) => set(e.target.value)} className={inputClass}>
                    <option value="">{field.nullable ? '— tidak ada —' : 'Pilih...'}</option>
                    {field.options?.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            );
        }
        if (field.type === 'date') return <DateInput value={form[field.key] ?? ''} onChange={set} className={inputClass} />;
        if (field.type === 'time') return <input type="time" value={form[field.key] ?? ''} onChange={(e) => set(e.target.value)} className={inputClass} />;
        return <input value={form[field.key] ?? ''} onChange={(e) => set(e.target.value)} placeholder={field.placeholder} className={inputClass} />;
    };

    const display = (field: Field, row: T) => {
        const value = toForm(row)[field.key];
        if (field.type === 'select') {
            const option = field.options?.find((o) => String(o.value) === value);
            return option ? (option.display ?? option.label) : '-';
        }
        if (field.type === 'date') return fmtDate(value);
        return value || '-';
    };

    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <div className="space-y-3">
            {error && (
                <div className="flex items-start justify-between gap-3 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                    <span>{error}</span>
                    <button onClick={() => setError(null)} className="shrink-0 opacity-60 hover:opacity-100" aria-label="Tutup">
                        <X size={16} />
                    </button>
                </div>
            )}

            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {fields.map((f) => (
                                <th key={f.key} className={`${th} ${f.width ?? ''}`}>
                                    {f.label}
                                </th>
                            ))}
                            {extraColumns.map((c) => (
                                <th key={c.label} className={th}>
                                    {c.label}
                                </th>
                            ))}
                            <th className={`${th} w-24 text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={fields.length + extraColumns.length + 1} className="px-3 py-6 text-center text-sm text-slate-400">
                                    {emptyText}
                                </td>
                            </tr>
                        )}

                        {rows.map((row) => {
                            const editing = editingId === row.id;
                            const blocked = deleteBlockedReason?.(row) ?? null;

                            return (
                                <tr key={row.id} className={editing ? 'bg-brand-50/40' : 'hover:bg-slate-50/60'}>
                                    {fields.map((f) => (
                                        <td key={f.key} className="px-3 py-2 text-slate-700">
                                            {editing ? renderInput(f, editForm, setEditForm) : display(f, row)}
                                        </td>
                                    ))}
                                    {extraColumns.map((c) => (
                                        <td key={c.label} className="px-3 py-2 whitespace-nowrap text-slate-500">
                                            {c.render(row)}
                                        </td>
                                    ))}
                                    <td className="px-3 py-2">
                                        <div className="flex justify-end gap-1">
                                            {editing ? (
                                                <>
                                                    <button
                                                        onClick={saveEdit}
                                                        disabled={busy}
                                                        title="Simpan"
                                                        className="rounded-lg p-1.5 text-emerald-600 hover:bg-emerald-50 disabled:opacity-50"
                                                    >
                                                        {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />}
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
                                                            setEditingId(row.id);
                                                            setEditForm(toForm(row));
                                                            setError(null);
                                                        }}
                                                        title="Ubah"
                                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                    >
                                                        <Pencil size={15} />
                                                    </button>
                                                    <button
                                                        onClick={() => {
                                                            if (confirm(`Hapus ${describe(row)}?`))
                                                                run('delete', `${baseUrl}/${row.id}`, undefined, () => {});
                                                        }}
                                                        disabled={!!blocked}
                                                        title={blocked ?? 'Hapus'}
                                                        className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                                    >
                                                        <Trash2 size={15} />
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}

                        <tr className="bg-slate-50/60">
                            {fields.map((f) => (
                                <td key={f.key} className="px-3 py-2">
                                    {renderInput(f, newForm, setNewForm)}
                                </td>
                            ))}
                            {extraColumns.map((c) => (
                                <td key={c.label} />
                            ))}
                            <td className="px-3 py-2 text-right">
                                <button
                                    onClick={saveNew}
                                    disabled={busy}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                >
                                    <Plus size={14} />
                                    Tambah
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}
