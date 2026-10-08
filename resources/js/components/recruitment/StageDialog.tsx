import { useForm } from '@inertiajs/react';
import { Check, Loader2, Save } from 'lucide-react';
import { Modal, ghostButton, inputClass, primaryButton } from '@/components/attendance/ui';

/** A new stage, or the name and colour of one. */
export function StageDialog({
    stage,
    colors,
    onClose,
}: {
    stage: { id: number; title: string; color: string } | null;
    colors: string[];
    onClose: () => void;
}) {
    const form = useForm({ title: stage?.title ?? '', color: stage?.color ?? colors[(Math.random() * colors.length) | 0] });
    const submit = () => {
        const options = { preserveScroll: true, preserveState: true, onSuccess: onClose };
        if (stage) form.put(`/recruitment/tahap/${stage.id}`, options);
        else form.post('/recruitment/tahap', options);
    };

    return (
        <Modal
            title={stage ? 'Ubah Tahap' : 'Tambah Tahap'}
            subtitle={stage ? 'Nama tahap di kartu kandidatnya ikut berubah.' : 'Tahap baru ditaruh paling kanan; geser lewat menu tahap.'}
            onClose={onClose}
            width="max-w-md"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={form.processing || !form.data.title.trim()} className={primaryButton}>
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                        Simpan
                    </button>
                </>
            }
        >
            <form
                className="space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Nama tahap</span>
                    <input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        maxLength={60}
                        autoFocus
                        placeholder="mis. Interview User"
                        className={inputClass}
                    />
                    {form.errors.title && <span className="mt-1 block text-xs font-bold text-rose-600">{form.errors.title}</span>}
                </label>
                <div>
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Warna</span>
                    <div className="flex flex-wrap gap-2">
                        {colors.map((c) => (
                            <button
                                key={c}
                                type="button"
                                onClick={() => form.setData('color', c)}
                                aria-label={`Warna ${c}`}
                                className="flex h-8 w-8 items-center justify-center rounded-full ring-offset-2 transition-all"
                                style={{ backgroundColor: c, boxShadow: form.data.color === c ? `0 0 0 2px white, 0 0 0 4px ${c}` : undefined }}
                            >
                                {form.data.color === c && <Check size={15} className="text-white" />}
                            </button>
                        ))}
                    </div>
                </div>
            </form>
        </Modal>
    );
}
