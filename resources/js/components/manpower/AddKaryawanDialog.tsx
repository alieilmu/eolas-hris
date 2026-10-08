import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, Loader2, Save, Wand2, X } from 'lucide-react';
import { DateInput } from '@/components/DateInput';
import { PenempatanFields } from '@/components/penempatan/PenempatanFields';
import { decodeNrp, statusAsal, type NrpCodes } from '@/lib/nrp';
import { emptyPlacement, type PenempatanOptions, type Placement } from '@/lib/penempatan';

type DialogProps = {
    penempatan_options?: PenempatanOptions;
    nrp_codes?: NrpCodes & { max_urut: Record<string, number> };
};

/** A position chosen in the organisation chart ("Kelola" → Karyawan baru). */
export interface FixedJabatan {
    id: number;
    name: string;
    work_location_id: number | null;
}

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/**
 * New employee with the essentials: NRP, name, and the placement the position
 * is composed from (Work Location → SubGroup → unit). Everything else is
 * filled in on the profile page. Recruitment uses it to hire a candidate
 * (its own submit URL, the candidate's name and email, and a start date).
 */
export function AddKaryawanDialog({
    onClose,
    fixedJabatan,
    submitUrl = '/manpower/karyawan',
    initial,
    title = 'Tambah Karyawan',
    tanggalMasuk = false,
    onSaved,
}: {
    onClose: () => void;
    fixedJabatan?: FixedJabatan;
    submitUrl?: string;
    initial?: { name?: string; email?: string | null };
    title?: string;
    /** Ask the start date (written to the profile). */
    tanggalMasuk?: boolean;
    onSaved?: () => void;
}) {
    const { penempatan_options: options, nrp_codes: codes } = usePage<DialogProps>().props;
    const form = useForm({
        nrp: '',
        name: initial?.name ?? '',
        email: initial?.email ?? '',
        tanggal_masuk: new Date().toISOString().slice(0, 10),
        jabatan_id: fixedJabatan ? String(fixedJabatan.id) : '',
        ...emptyPlacement(),
        work_location_id: fixedJabatan?.work_location_id ? String(fixedJabatan.work_location_id) : '',
    });

    // NRP builder: jenis + lokasi + tahun masuk -> next free number.
    const [jenis, setJenis] = useState('');
    const [lokasi, setLokasi] = useState('');
    const [tahun, setTahun] = useState(String(new Date().getFullYear()));

    // Placement choices and NRP codes are only fetched when the dialog opens.
    useEffect(() => {
        router.reload({ only: ['penempatan_options', 'nrp_codes'] });
    }, []);

    useEffect(() => {
        if (codes && !jenis) setJenis(codes.jenis[0]?.kode ?? '');
    }, [codes, jenis]);

    const suggestion = (() => {
        if (!codes || !jenis || !lokasi || !/^\d{4}$/.test(tahun)) return null;
        const prefix = `${jenis}${lokasi}${tahun.slice(2)}`;
        const next = (codes.max_urut[prefix] ?? 0) + 1;
        return next > 999 ? null : `${prefix}${String(next).padStart(3, '0')}`;
    })();

    const decoded = codes && form.data.nrp.trim() ? decodeNrp(form.data.nrp, codes) : null;
    const workLocationId = form.data.work_location_id ? Number(form.data.work_location_id) : null;
    const asal = decoded && !decoded.error ? statusAsal(decoded.lokasi, workLocationId) : null;

    const placement: Placement = {
        work_location_id: form.data.work_location_id,
        sub_location_id: form.data.sub_location_id,
        sub_group_id: form.data.sub_group_id,
        bod_id: form.data.bod_id,
        divisi_id: form.data.divisi_id,
        departemen_id: form.data.departemen_id,
        section_id: form.data.section_id,
        bidang: form.data.bidang,
    };

    const submit = () =>
        form.post(submitUrl, {
            preserveScroll: true,
            onSuccess: () => {
                onSaved?.();
                onClose();
            },
        });

    const fieldError = (key: 'nrp' | 'name' | 'email') => form.errors[key] && <p className="mt-1 text-[11px] text-rose-600">{form.errors[key]}</p>;
    const placementError = (form.errors as Record<string, string>).penempatan ?? form.errors.work_location_id ?? form.errors.sub_group_id;

    const smallSelect = 'rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs outline-none focus:ring-2 focus:ring-brand-500';

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm">
            <div className="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex shrink-0 items-center justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">{title}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">Data lainnya dilengkapi lewat ikon profil di baris karyawan.</p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <form
                    className="flex min-h-0 flex-col"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <div className="space-y-4 overflow-y-auto p-6">
                        <div className="space-y-2 rounded-2xl border border-slate-100 bg-slate-50 p-3">
                            <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">Susun NRP</p>
                            {codes ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <select
                                        value={jenis}
                                        onChange={(e) => setJenis(e.target.value)}
                                        className={smallSelect}
                                        title="Digit 1: jenis karyawan"
                                    >
                                        {codes.jenis.map((j) => (
                                            <option key={j.kode} value={j.kode}>
                                                {j.kode} — {j.nama}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        value={lokasi}
                                        onChange={(e) => setLokasi(e.target.value)}
                                        className={smallSelect}
                                        title="Digit 2–3: lokasi penerimaan"
                                    >
                                        <option value="">Lokasi penerimaan...</option>
                                        {codes.lokasi.map((l) => (
                                            <option key={l.kode} value={l.kode}>
                                                {l.kode} — {l.nama}
                                            </option>
                                        ))}
                                    </select>
                                    <input
                                        value={tahun}
                                        onChange={(e) => setTahun(e.target.value)}
                                        maxLength={4}
                                        title="Digit 4–5: tahun masuk"
                                        className={`${smallSelect} w-16`}
                                    />
                                    <button
                                        type="button"
                                        disabled={!suggestion}
                                        onClick={() => suggestion && form.setData('nrp', suggestion)}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-40"
                                    >
                                        <Wand2 size={13} />
                                        {suggestion ? `Pakai ${suggestion}` : 'Pilih lokasi'}
                                    </button>
                                </div>
                            ) : (
                                <p className="text-xs text-slate-400">Memuat kode NRP...</p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">NRP *</label>
                                <input
                                    type="text"
                                    value={form.data.nrp}
                                    onChange={(e) => form.setData('nrp', e.target.value)}
                                    maxLength={8}
                                    className={`${inputClass} font-mono tracking-widest`}
                                    autoFocus
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">Nama *</label>
                                <input
                                    type="text"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    className={inputClass}
                                />
                                {fieldError('name')}
                            </div>
                        </div>
                        {form.errors.nrp ? (
                            fieldError('nrp')
                        ) : decoded ? (
                            <p className={`-mt-2 text-[11px] ${decoded.error ? 'text-rose-600' : 'text-emerald-700'}`}>
                                {decoded.error ?? `${decoded.jenis?.nama} • diterima di ${decoded.lokasi?.nama} • masuk ${decoded.tahun}`}
                            </p>
                        ) : null}
                        {tanggalMasuk && (
                            <div>
                                <label className="mb-1.5 block text-xs font-bold text-slate-500">Tanggal Masuk *</label>
                                <DateInput
                                    value={form.data.tanggal_masuk}
                                    onChange={(v) => form.setData('tanggal_masuk', v)}
                                    className={inputClass}
                                />
                                {(form.errors as Record<string, string>).tanggal_masuk && (
                                    <p className="mt-1 text-[11px] text-rose-600">{(form.errors as Record<string, string>).tanggal_masuk}</p>
                                )}
                            </div>
                        )}
                        <div>
                            <label className="mb-1.5 block text-xs font-bold text-slate-500">Email Kantor</label>
                            <input
                                type="text"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                className={inputClass}
                            />
                            {fieldError('email')}
                        </div>

                        <div className="border-t border-slate-100 pt-4">
                            <p className="mb-2 text-[11px] font-bold tracking-wide text-slate-500 uppercase">Penempatan</p>
                            {fixedJabatan ? (
                                <div className="space-y-3">
                                    <p className="flex items-center gap-2 rounded-xl border border-brand-100 bg-brand-50/60 px-3 py-2.5 text-sm">
                                        <BadgeCheck size={16} className="text-brand-600" />
                                        <span className="text-slate-500">Jabatan:</span>
                                        <b className="text-brand-800">{fixedJabatan.name}</b>
                                    </p>
                                    {/* Positions made before work locations belonged to positions need one chosen. */}
                                    {!fixedJabatan.work_location_id && options && (
                                        <select
                                            value={form.data.work_location_id}
                                            onChange={(e) => form.setData('work_location_id', e.target.value)}
                                            className={inputClass}
                                        >
                                            <option value="">Pilih Work Location...</option>
                                            {options.work_locations.map((w) => (
                                                <option key={w.id} value={w.id}>
                                                    {w.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {placementError && <p className="text-xs font-bold text-rose-600">{placementError}</p>}
                                </div>
                            ) : options ? (
                                <PenempatanFields
                                    value={placement}
                                    onChange={(p) => form.setData((data) => ({ ...data, ...p }))}
                                    options={options}
                                    error={placementError}
                                />
                            ) : (
                                <p className="text-xs text-slate-400">Memuat pilihan penempatan...</p>
                            )}
                            {asal && (
                                <p className="mt-2 text-[11px] text-slate-500">
                                    Status asal otomatis: <b className={asal === 'Lokal' ? 'text-emerald-700' : 'text-amber-700'}>{asal}</b>
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="flex shrink-0 justify-end gap-3 border-t border-slate-100 px-6 py-4">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-100"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing || !!decoded?.error}
                            className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white transition-all hover:bg-brand-700 disabled:opacity-50"
                        >
                            {form.processing ? <Loader2 className="animate-spin" size={16} /> : <Save size={16} />}
                            Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
