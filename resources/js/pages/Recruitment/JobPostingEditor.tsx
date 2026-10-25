import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Check, Copy, ExternalLink, Eye, Loader2, Plus, Save, Trash2 } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { inputClass, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { ApplicationField } from '@/components/recruitment/ApplicationField';
import type { FieldType } from '@/lib/recruitment';

interface EditField {
    id: number | null;
    /** For React only: new questions have no id yet. */
    key: string;
    label: string;
    type: FieldType;
    field_key: string;
    required: boolean;
    options: string[];
    placeholder: string;
}

interface Props {
    form: {
        id: number;
        title: string;
        description: string;
        position_title: string;
        location: string;
        closing_date: string;
        is_open: boolean;
        url: string;
        applicants_count: number;
        fields: Omit<EditField, 'key'>[];
    };
    types: Record<FieldType, string>;
    field_key: Record<string, string>;
    field_key_types: Record<string, FieldType[]>;
    max_file: string;
}

let seq = 0;
const newKey = () => `n${++seq}`;

/** Pengaturan Form → one form: the vacancy's details and its questions, with a preview. */
export default function JobPostingEditor({ form: initial, types, field_key, field_key_types, max_file }: Props) {
    const form = useForm({
        title: initial.title,
        description: initial.description,
        position_title: initial.position_title,
        location: initial.location,
        closing_date: initial.closing_date,
        is_open: initial.is_open,
        fields: initial.fields.map((f) => ({ ...f, key: `f${f.id}` })) as EditField[],
    });
    const [preview, setPreview] = useState(false);
    const [copied, setCopied] = useState(false);
    const d = form.data;

    const setField = (i: number, patch: Partial<EditField>) =>
        form.setData(
            'fields',
            d.fields.map((f, n) => (n === i ? { ...f, ...patch } : f)),
        );
    const move = (i: number, by: -1 | 1) => {
        const list = [...d.fields];
        [list[i], list[i + by]] = [list[i + by], list[i]];
        form.setData('fields', list);
    };
    const add = () =>
        form.setData('fields', [
            ...d.fields,
            { id: null, key: newKey(), label: '', type: 'text', field_key: '', required: false, options: [], placeholder: '' },
        ]);
    const remove = (i: number) =>
        form.setData(
            'fields',
            d.fields.filter((_, n) => n !== i),
        );

    // A "kunci" belongs to one question; the others offer what is still free.
    const used = new Set(d.fields.map((f) => f.field_key).filter(Boolean));
    const warnings = d.fields.flatMap((f) =>
        f.field_key && !field_key_types[f.field_key]?.includes(f.type)
            ? [`"${f.label || 'Pertanyaan'}" dipakai sebagai ${field_key[f.field_key]}, tapi tipenya ${types[f.type]}.`]
            : [],
    );
    if (!used.has('name')) warnings.unshift('Tandai satu pertanyaan sebagai "Nama pelamar".');

    const save = () => {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        form.transform((data) => ({ ...data, fields: data.fields.map(({ key, ...f }) => ({ ...f, field_key: f.field_key || null })) }));
        form.put(`/recruitment/job-postings/${initial.id}`, { preserveScroll: true });
    };

    const fieldErrors = Object.entries(form.errors).filter(([k]) => k.startsWith('fields'));

    return (
        <>
            <Head title={`Form: ${d.title}`} />
            <header className="flex min-h-16 shrink-0 flex-wrap items-center gap-2 border-b-[3px] border-accent-400 bg-white px-4 py-2 sm:px-6">
                <Link href="/recruitment/job-postings" aria-label="Kembali ke daftar form" className="rounded-lg p-2 text-slate-400 hover:bg-slate-100">
                    <ArrowLeft size={18} />
                </Link>
                <div className="min-w-0 flex-1">
                    <h1 className="truncate text-lg font-bold text-slate-800">{d.title || 'Form tanpa judul'}</h1>
                    <p className="text-[11px] text-slate-400">
                        {initial.applicants_count} lamaran masuk · pertanyaan yang diubah tidak mengubah jawaban yang sudah dikirim
                    </p>
                </div>
                <button
                    onClick={() =>
                        navigator.clipboard?.writeText(initial.url).then(() => {
                            setCopied(true);
                            setTimeout(() => setCopied(false), 1800);
                        })
                    }
                    className={secondaryButton}
                >
                    {copied ? <Check size={16} /> : <Copy size={16} />} Tautan
                </button>
                <a href={initial.url} target="_blank" rel="noreferrer" className={secondaryButton}>
                    <ExternalLink size={16} /> Buka
                </a>
                <button onClick={() => setPreview(!preview)} className={`${secondaryButton} xl:hidden`}>
                    <Eye size={16} /> {preview ? 'Edit' : 'Pratinjau'}
                </button>
                <button onClick={save} disabled={form.processing} className={primaryButton}>
                    {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Simpan
                </button>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                <div className="mx-auto grid max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_420px] [&>*]:min-w-0">
                    <div className={`space-y-4 ${preview ? 'max-xl:hidden' : ''}`}>
                        {(fieldErrors.length > 0 || warnings.length > 0) && (
                            <div className="space-y-1 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                {[...fieldErrors.map(([, v]) => v), ...(fieldErrors.length ? [] : warnings)]
                                    .flatMap((m) => String(m).split('\n'))
                                    .map((m, i) => (
                                        <p key={i} className="flex gap-2">
                                            <AlertTriangle size={15} className="mt-0.5 shrink-0" /> {m}
                                        </p>
                                    ))}
                            </div>
                        )}

                        <section className="space-y-4 rounded-3xl border border-slate-200 border-t-8 border-t-brand-600 bg-white p-5 shadow-sm">
                            <input
                                value={d.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Judul lowongan, mis. Operator Alat Berat"
                                className="w-full border-0 border-b border-transparent p-0 pb-1 text-2xl font-black text-slate-900 outline-none placeholder:text-slate-300 focus:border-brand-300"
                            />
                            {form.errors.title && <p className="text-xs font-bold text-rose-600">{form.errors.title}</p>}
                            <div className="grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Posisi</span>
                                    <input
                                        value={d.position_title}
                                        onChange={(e) => form.setData('position_title', e.target.value)}
                                        placeholder="mis. Operator Dump Truck"
                                        className={inputClass}
                                    />
                                </label>
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Lokasi kerja</span>
                                    <input
                                        value={d.location}
                                        onChange={(e) => form.setData('location', e.target.value)}
                                        placeholder="mis. Site Murung Raya"
                                        className={inputClass}
                                    />
                                </label>
                                <label className="block">
                                    <span className="mb-1 block text-[11px] font-bold text-slate-500">Batas lamaran</span>
                                    <DateInput value={d.closing_date} onChange={(v) => form.setData('closing_date', v)} className={inputClass} />
                                </label>
                            </div>
                            <label className="block">
                                <span className="mb-1 block text-[11px] font-bold text-slate-500">Deskripsi / kualifikasi</span>
                                <textarea
                                    value={d.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={4}
                                    placeholder="Tanggung jawab, kualifikasi, benefit…"
                                    className={`${inputClass} resize-y`}
                                />
                            </label>
                            <label className="flex items-center gap-3 rounded-2xl bg-slate-50 px-3 py-2.5">
                                <input
                                    type="checkbox"
                                    checked={d.is_open}
                                    onChange={(e) => form.setData('is_open', e.target.checked)}
                                    className="h-4 w-4 accent-emerald-600"
                                />
                                <span className="text-sm">
                                    <b className="text-slate-700">Buka form</b>
                                    <span className="text-slate-500"> — tampil di halaman lowongan dan bisa diisi pelamar</span>
                                </span>
                            </label>
                        </section>

                        {d.fields.map((f, i) => (
                            <section key={f.key} className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                                <div className="flex flex-wrap gap-3">
                                    <input
                                        value={f.label}
                                        onChange={(e) => setField(i, { label: e.target.value })}
                                        placeholder="Pertanyaan"
                                        aria-label={`Pertanyaan ${i + 1}`}
                                        className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-800 outline-none focus:bg-white focus:ring-2 focus:ring-brand-500"
                                    />
                                    <select
                                        value={f.type}
                                        onChange={(e) => setField(i, { type: e.target.value as FieldType })}
                                        aria-label="Tipe jawaban"
                                        className={`${smallInput} w-48`}
                                    >
                                        {Object.entries(types).map(([v, label]) => (
                                            <option key={v} value={v}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                {f.type === 'select' && (
                                    <label className="mt-3 block">
                                        <span className="mb-1 block text-[11px] font-bold text-slate-500">Pilihan (satu per baris)</span>
                                        <textarea
                                            value={f.options.join('\n')}
                                            onChange={(e) => setField(i, { options: e.target.value.split('\n') })}
                                            rows={Math.max(3, f.options.length + 1)}
                                            className={`${inputClass} resize-y`}
                                        />
                                    </label>
                                )}
                                {f.type !== 'file' && f.type !== 'date' && (
                                    <input
                                        value={f.placeholder}
                                        onChange={(e) => setField(i, { placeholder: e.target.value })}
                                        placeholder="Teks bantuan di dalam kolom (opsional)"
                                        className={`${smallInput} mt-3 w-full`}
                                    />
                                )}
                                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-slate-100 pt-3 text-xs">
                                    <label className="flex items-center gap-2 font-bold text-slate-500">
                                        Dipakai sebagai
                                        <select value={f.field_key} onChange={(e) => setField(i, { field_key: e.target.value })} className={smallInput}>
                                            <option value="">—</option>
                                            {Object.entries(field_key).map(([v, label]) => (
                                                <option key={v} value={v} disabled={used.has(v) && f.field_key !== v}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="flex items-center gap-2 font-bold text-slate-500">
                                        <input
                                            type="checkbox"
                                            checked={f.required}
                                            onChange={(e) => setField(i, { required: e.target.checked })}
                                            className="accent-brand-600"
                                        />
                                        Wajib diisi
                                    </label>
                                    <div className="ml-auto flex gap-1">
                                        <IconButton label="Naik" onClick={() => move(i, -1)} disabled={i === 0} icon={ArrowUp} />
                                        <IconButton label="Turun" onClick={() => move(i, 1)} disabled={i === d.fields.length - 1} icon={ArrowDown} />
                                        <IconButton label="Hapus pertanyaan" onClick={() => remove(i)} icon={Trash2} danger />
                                    </div>
                                </div>
                            </section>
                        ))}

                        <button
                            onClick={add}
                            className="flex w-full items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-200 py-4 font-bold text-slate-400 hover:border-brand-300 hover:bg-white hover:text-brand-600"
                        >
                            <Plus size={18} /> Tambah pertanyaan
                        </button>
                    </div>

                    <aside className={`space-y-3 xl:sticky xl:top-0 xl:self-start ${preview ? '' : 'max-xl:hidden'}`}>
                        <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">Pratinjau untuk pelamar</p>
                        <div className="max-h-[calc(100vh-10rem)] space-y-3 overflow-y-auto rounded-3xl bg-slate-100 p-3">
                            <div className="rounded-3xl border-t-8 border-brand-600 bg-white p-5">
                                <p className="text-xl font-black text-slate-900">{d.title || 'Judul lowongan'}</p>
                                <p className="mt-1 text-xs text-slate-500">{[d.position_title, d.location].filter(Boolean).join(' · ')}</p>
                                {d.description && <p className="mt-2 line-clamp-4 text-xs whitespace-pre-line text-slate-500">{d.description}</p>}
                            </div>
                            {d.fields.map((f, i) => (
                                <ApplicationField
                                    key={f.key}
                                    field={{
                                        id: i,
                                        label: f.label,
                                        type: f.type,
                                        required: f.required,
                                        options: f.options.filter((o) => o.trim()),
                                        placeholder: f.placeholder || null,
                                    }}
                                    maxFile={max_file}
                                    preview
                                />
                            ))}
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

function IconButton({
    label,
    onClick,
    disabled,
    icon: Icon,
    danger,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    icon: typeof Plus;
    danger?: boolean;
}) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            title={label}
            aria-label={label}
            className={`rounded-lg p-1.5 text-slate-400 disabled:opacity-30 ${danger ? 'hover:bg-rose-50 hover:text-rose-600' : 'hover:bg-slate-100 hover:text-slate-600'}`}
        >
            <Icon size={15} />
        </button>
    );
}
