import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Loader2, Save, X } from 'lucide-react';
import { PenempatanFields } from '@/components/penempatan/PenempatanFields';
import { placementPayload, type PenempatanOptions, type Placement } from '@/lib/penempatan';

interface Props {
    title: string;
    subtitle?: string;
    initial: Placement;
    /** PUT for a change (Ubah Penempatan), POST for something new (Tambah Jabatan). */
    method: 'put' | 'post';
    url: string;
    submitLabel?: string;
    onClose: () => void;
}

/** Work Location → SubGroup → unit, saved as a placement; the position name is composed by the server. */
export function PenempatanDialog({ title, subtitle, initial, method, url, submitLabel = 'Simpan', onClose }: Props) {
    const options = usePage<{ penempatan_options?: PenempatanOptions }>().props.penempatan_options;
    const [value, setValue] = useState(initial);
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    // The choices are only fetched when the dialog opens (pages that always carry them skip this).
    useEffect(() => {
        if (!options) router.reload({ only: ['penempatan_options'] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const submit = () => {
        setBusy(true);
        setError(undefined);
        router[method](url, placementPayload(value), {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => setError(errors.penempatan ?? Object.values(errors)[0]),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
            <div className="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex shrink-0 items-center justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">{title}</h3>
                        {subtitle && <p className="mt-0.5 text-xs text-slate-400">{subtitle}</p>}
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>
                <div className="overflow-y-auto p-6">
                    {options ? (
                        <PenempatanFields value={value} onChange={setValue} options={options} error={error} />
                    ) : (
                        <p className="text-sm text-slate-400">Memuat pilihan penempatan...</p>
                    )}
                </div>
                <div className="flex shrink-0 justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100">
                        Batal
                    </button>
                    <button
                        onClick={submit}
                        disabled={busy || !options}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                        {busy ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                        {submitLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
