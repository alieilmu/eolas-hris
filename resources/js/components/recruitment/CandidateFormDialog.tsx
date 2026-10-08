import { useForm } from '@inertiajs/react';
import { FileUp, Loader2, Save } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { Modal, ghostButton, inputClass, primaryButton } from '@/components/attendance/ui';
import type { CandidateDetail } from '@/lib/recruitment';

/** A candidate typed in by HR (referral, walk-in), or the details of one on the board. */
export function CandidateFormDialog({
    candidate,
    stages,
    stageId,
    posisiOptions,
    onClose,
    onSaved,
}: {
    candidate?: CandidateDetail;
    stages: { id: number; title: string }[];
    stageId?: number;
    posisiOptions: string[];
    onClose: () => void;
    onSaved?: () => void;
}) {
    const form = useForm({
        stage_id: String(stageId ?? stages[0]?.id ?? ''),
        name: candidate?.name ?? '',
        email: candidate?.email ?? '',
        no_hp: candidate?.no_hp ?? '',
        position: candidate?.position ?? '',
        portfolio_url: candidate?.portfolio_url ?? '',
        applied_date: candidate?.applied_date ?? new Date().toISOString().slice(0, 10),
        cv: null as File | null,
    });
    const err = (k: keyof typeof form.data) => form.errors[k] && <span className="mt-1 block text-xs font-bold text-rose-600">{form.errors[k]}</span>;

    const submit = () => {
        const options = {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        };
        if (candidate) {
            form.transform((d) => ({ ...d, _method: 'put' }));
            form.post(`/recruitment/kandidat/${candidate.id}`, options);
        } else {
            form.post('/recruitment/kandidat', options);
        }
    };

    return (
        <Modal
            title={candidate ? `Ubah Data ${candidate.name}` : 'Tambah Kandidat'}
            subtitle={candidate ? undefined : 'Untuk kandidat yang tidak melamar lewat form (referensi, walk-in).'}
            onClose={onClose}
            width="max-w-xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={submit} disabled={form.processing} className={primaryButton}>
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                        Simpan
                    </button>
                </>
            }
        >
            <form
                className="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <label className="block sm:col-span-2">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Nama lengkap *</span>
                    <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus className={inputClass} />
                    {err('name')}
                </label>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Posisi *</span>
                    <input
                        value={form.data.position}
                        onChange={(e) => form.setData('position', e.target.value)}
                        list="posisi-options"
                        className={inputClass}
                    />
                    <datalist id="posisi-options">
                        {posisiOptions.map((p) => (
                            <option key={p} value={p} />
                        ))}
                    </datalist>
                    {err('position')}
                </label>
                {!candidate ? (
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-bold text-slate-500">Masuk ke tahap</span>
                        <select value={form.data.stage_id} onChange={(e) => form.setData('stage_id', e.target.value)} className={inputClass}>
                            {stages.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.title}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : (
                    <div />
                )}
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Email</span>
                    <input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={inputClass} />
                    {err('email')}
                </label>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Nomor HP</span>
                    <input value={form.data.no_hp} onChange={(e) => form.setData('no_hp', e.target.value)} inputMode="tel" className={inputClass} />
                    {err('no_hp')}
                </label>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Tanggal melamar *</span>
                    <DateInput value={form.data.applied_date} onChange={(v) => form.setData('applied_date', v)} className={inputClass} />
                    {err('applied_date')}
                </label>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Portofolio / LinkedIn</span>
                    <input
                        value={form.data.portfolio_url}
                        onChange={(e) => form.setData('portfolio_url', e.target.value)}
                        placeholder="https://"
                        className={inputClass}
                    />
                    {err('portfolio_url')}
                </label>
                <label className="block sm:col-span-2">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">CV {candidate?.cv && '(ganti)'}</span>
                    <span className="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-500 hover:border-brand-300">
                        <FileUp size={18} className="text-slate-400" />
                        <span className="min-w-0 flex-1 truncate">
                            {form.data.cv?.name ?? candidate?.cv?.nama ?? 'Pilih file PDF / Word / gambar'}
                        </span>
                        <input
                            type="file"
                            accept=".pdf,.doc,.docx,image/*"
                            className="hidden"
                            onChange={(e) => form.setData('cv', e.target.files?.[0] ?? null)}
                        />
                    </span>
                    {err('cv')}
                </label>
            </form>
        </Modal>
    );
}
