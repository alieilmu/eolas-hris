import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2, LogOut, Trash2, X } from 'lucide-react';
import { todayIso } from '@/lib/contractDates';
import { DateInput } from '@/components/DateInput';

const HAPUS_DATA = 'Hapus data';

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

interface Props {
    employee: { id: number; employee_number: string; name: string };
    reasonOptions: string[];
    onClose: () => void;
}

/** Record an exit (reasons 1–8, the record is kept) or, as option 9, delete a record entered by mistake. */
export function TerminateEmployeeDialog({ employee, reasonOptions, onClose }: Props) {
    const form = useForm({ reason: '', date: todayIso(), description: '' });
    const [confirmNrp, setConfirmNrp] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const isDelete = form.data.reason === HAPUS_DATA;
    const canSubmit = isDelete ? confirmNrp.trim() === employee.employee_number : !!form.data.reason && !!form.data.date;
    const busy = form.processing || deleting;
    const error = deleteError ?? form.errors.reason ?? form.errors.date ?? form.errors.description;

    const submit = () => {
        if (!canSubmit || busy) return;

        if (isDelete) {
            router.delete(`/core-hr/employees/${employee.id}`, {
                data: { employee_number_confirmation: confirmNrp.trim() },
                preserveScroll: true,
                onStart: () => setDeleting(true),
                onFinish: () => setDeleting(false),
                onSuccess: onClose,
                onError: (errors) => setDeleteError(Object.values(errors)[0] ?? 'Gagal menghapus.'),
            });
            return;
        }

        form.post(`/core-hr/employees/${employee.id}/termination`, { preserveScroll: true, onSuccess: onClose });
    };

    const optionClass = (value: string, danger = false) =>
        `rounded-xl border px-3 py-2 text-left text-xs font-bold transition-all ${
            form.data.reason === value
                ? danger
                    ? 'border-rose-600 bg-rose-600 text-white'
                    : 'border-brand-600 bg-brand-600 text-white'
                : danger
                  ? 'border-rose-200 bg-white text-rose-600 hover:bg-rose-50'
                  : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
        }`;

    const choose = (value: string) => {
        form.setData('reason', value);
        form.clearErrors();
        setDeleteError(null);
    };

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
            <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex items-center justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">Karyawan Keluar</h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {employee.name} • NRP {employee.employee_number}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="space-y-5 p-6">
                    {error && <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</div>}

                    <div>
                        <label className="mb-2 block text-xs font-bold text-slate-500">Alasan Keluar *</label>
                        <div className="grid grid-cols-2 gap-2">
                            {reasonOptions.map((a, i) => (
                                <button key={a} onClick={() => choose(a)} className={optionClass(a)}>
                                    <span className="mr-1 opacity-60">{i + 1}.</span>
                                    {a}
                                </button>
                            ))}
                        </div>
                        <div className="mt-3 border-t border-dashed border-slate-200 pt-3">
                            <button onClick={() => choose(HAPUS_DATA)} className={`${optionClass(HAPUS_DATA, true)} w-full`}>
                                <span className="mr-1 opacity-60">{reasonOptions.length + 1}.</span>
                                {HAPUS_DATA} — hapus permanen dari database
                            </button>
                        </div>
                    </div>

                    {isDelete ? (
                        <div className="space-y-3">
                            <div className="flex items-start gap-2 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-xs text-rose-700">
                                <AlertTriangle size={16} className="mt-0.5 shrink-0" />
                                <span>
                                    Data karyawan, profil lengkap, riwayat PKWT, dan data anaknya akan <b>dihapus permanen</b> dan tidak muncul di
                                    daftar Karyawan Keluar. Gunakan hanya untuk data yang salah input — untuk karyawan yang memang berhenti, pilih
                                    salah satu alasan di atas.
                                </span>
                            </div>
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">
                                    Ketik NRP <span className="text-slate-700">{employee.employee_number}</span> untuk konfirmasi
                                </label>
                                <input
                                    type="text"
                                    value={confirmNrp}
                                    onChange={(e) => setConfirmNrp(e.target.value)}
                                    className={inputClass}
                                    autoFocus
                                />
                            </div>
                        </div>
                    ) : (
                        <>
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">Tanggal Keluar *</label>
                                <DateInput value={form.data.date} onChange={(v) => form.setData('date', v)} className={inputClass} />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">Keterangan</label>
                                <textarea
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    rows={2}
                                    placeholder="Opsional — mis. nomor surat, tujuan mutasi"
                                    className={`${inputClass} resize-none`}
                                />
                            </div>
                        </>
                    )}
                </div>

                <div className="flex justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100">
                        Batal
                    </button>
                    <button
                        onClick={submit}
                        disabled={busy || !canSubmit}
                        className={`flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold text-white transition-all disabled:opacity-50 ${
                            isDelete ? 'bg-rose-600 hover:bg-rose-700' : 'bg-brand-600 hover:bg-brand-700'
                        }`}
                    >
                        {busy ? <Loader2 className="animate-spin" size={16} /> : isDelete ? <Trash2 size={16} /> : <LogOut size={16} />}
                        {isDelete ? 'Hapus Permanen' : 'Simpan Karyawan Keluar'}
                    </button>
                </div>
            </div>
        </div>
    );
}
