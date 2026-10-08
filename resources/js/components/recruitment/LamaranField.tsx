import { FileUp } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { fileSize, type PublicField } from '@/lib/recruitment';

const input =
    'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-brand-500 focus:bg-white focus:ring-4 focus:ring-brand-500/10';

/** One question of the public application form (also the editor's preview, then without handlers). */
export function LamaranField({
    field,
    value,
    file,
    error,
    maxFile,
    onChange,
    onFile,
    preview,
}: {
    field: PublicField;
    value?: string;
    file?: File | null;
    error?: string;
    maxFile: string;
    onChange?: (v: string) => void;
    onFile?: (f: File | null) => void;
    preview?: boolean;
}) {
    const id = `q${field.id}`;
    const set = (v: string) => onChange?.(v);

    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <label htmlFor={id} className="mb-3 block text-sm font-bold text-slate-700">
                {field.label || <span className="text-slate-300">Pertanyaan</span>}
                {field.required && <span className="ml-0.5 text-rose-500">*</span>}
            </label>
            {field.type === 'textarea' ? (
                <textarea
                    id={id}
                    value={value ?? ''}
                    onChange={(e) => set(e.target.value)}
                    placeholder={field.placeholder ?? ''}
                    readOnly={preview}
                    rows={4}
                    className={`${input} resize-y`}
                />
            ) : field.type === 'select' ? (
                <select id={id} value={value ?? ''} onChange={(e) => set(e.target.value)} disabled={preview} className={`${input} cursor-pointer`}>
                    <option value="">{field.placeholder || 'Pilih salah satu…'}</option>
                    {field.options.map((o) => (
                        <option key={o} value={o}>
                            {o}
                        </option>
                    ))}
                </select>
            ) : field.type === 'date' ? (
                <DateInput value={value ?? ''} onChange={set} disabled={preview} className={input} aria-label={field.label} />
            ) : field.type === 'file' ? (
                <label
                    htmlFor={preview ? undefined : id}
                    className={`flex flex-col items-center justify-center rounded-2xl border-2 border-dashed px-4 py-7 text-center transition ${
                        file ? 'border-brand-300 bg-brand-50/40' : 'border-slate-200 bg-slate-50 hover:border-brand-300'
                    } ${preview ? '' : 'cursor-pointer'}`}
                >
                    <FileUp size={28} className={file ? 'text-brand-500' : 'text-slate-300'} />
                    <span className="mt-2 max-w-full truncate text-sm font-bold text-slate-600">{file ? file.name : 'Pilih file'}</span>
                    <span className="text-xs text-slate-400">{file ? fileSize(file.size) : `PDF, Word, JPG atau PNG · maks. ${maxFile}`}</span>
                    {!preview && (
                        <input
                            id={id}
                            type="file"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                            className="sr-only"
                            onChange={(e) => onFile?.(e.target.files?.[0] ?? null)}
                        />
                    )}
                </label>
            ) : (
                <input
                    id={id}
                    type={
                        field.type === 'tel'
                            ? 'tel'
                            : field.type === 'number'
                              ? 'number'
                              : field.type === 'email'
                                ? 'email'
                                : field.type === 'url'
                                  ? 'url'
                                  : 'text'
                    }
                    inputMode={field.type === 'tel' ? 'tel' : undefined}
                    value={value ?? ''}
                    onChange={(e) => set(e.target.value)}
                    placeholder={field.placeholder ?? (field.type === 'url' ? 'https://' : field.type === 'tel' ? '0812 3456 7890' : '')}
                    readOnly={preview}
                    className={input}
                />
            )}
            {error && <p className="mt-2 text-xs font-bold text-rose-600">{error}</p>}
        </div>
    );
}
