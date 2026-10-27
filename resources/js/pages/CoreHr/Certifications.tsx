import { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Download, Loader2, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { AttendanceHeader, Field, Modal, ghostButton, inputClass, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { AttachmentButton, AttachmentPicker } from '@/components/attachment/AttachmentPicker';
import { DateInput } from '@/components/DateInput';
import { draftPayload, emptyDraft, type LampiranLimits } from '@/lib/attachment';
import { CERT_TONE, daysText, type CertificationRow } from '@/lib/certifications';
import { fmtDate } from '@/lib/format';

interface Type {
    id: number;
    code: string;
    name: string;
    description: string | null;
    expires: boolean;
    is_active: boolean;
}

interface Props {
    rows: CertificationRow[];
    counts: Record<CertificationRow['status'], number>;
    filters: { status?: string; type?: number; location?: number; q?: string };
    types: Type[];
    employees: { id: number; label: string }[];
    locations: { id: number; name: string }[];
    attachment_limits: LampiranLimits;
    can_edit: boolean;
}

const BASE = '/core-hr/certifications';
const LABEL: Record<CertificationRow['status'], string> = { expired: 'Kedaluwarsa', soon: '≤ 30 hari', upcoming: '31–90 hari', valid: 'Berlaku', lifetime: 'Tanpa masa berlaku' };

/** Sertifikasi & Izin Kerja: who holds which licence or certificate until when; what runs out first on top. */
export default function Certifications({ rows, counts, filters, types, employees, locations, attachment_limits, can_edit }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [editing, setEditing] = useState<CertificationRow | 'new' | null>(null);
    const [addingType, setAddingType] = useState(false);
    const go = (change: Partial<Props['filters']>) => router.get(BASE, { ...filters, ...change } as never, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Sertifikasi & Izin" />
            <AttendanceHeader title="Sertifikasi & Izin Kerja" subtitle="STR/SIP, SIMPER/KIMPER, SKK, K3 … dengan pengingat 90/60/30 hari sebelum habis">
                <a href={`${BASE}/export`} className={secondaryButton}>
                    <Download size={15} /> Excel
                </a>
                {can_edit && (
                    <>
                        <button onClick={() => setAddingType(true)} className={secondaryButton}>
                            <Plus size={15} /> Jenis
                        </button>
                        <button onClick={() => setEditing('new')} className={primaryButton}>
                            <Plus size={15} /> Sertifikat
                        </button>
                    </>
                )}
            </AttendanceHeader>
            <main className="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-6">
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    {(Object.keys(LABEL) as CertificationRow['status'][]).map((s) => (
                        <button
                            key={s}
                            onClick={() => go({ status: filters.status === s ? undefined : s })}
                            className={`rounded-2xl border p-4 text-left ${filters.status === s ? 'border-brand-300 bg-brand-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
                        >
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${CERT_TONE[s]}`}>{LABEL[s]}</span>
                            <p className="mt-2 text-2xl font-black text-slate-800 tabular-nums">{counts[s] ?? 0}</p>
                        </button>
                    ))}
                </div>
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({ q: q || undefined });
                            }}
                            className="relative"
                        >
                            <Search size={14} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Cari nama / NRP / nomor" className={`${smallInput} w-60 pl-8`} />
                        </form>
                        <select value={filters.type ?? ''} onChange={(e) => go({ type: e.target.value ? Number(e.target.value) : undefined })} className={smallInput} aria-label="Jenis">
                            <option value="">Semua jenis</option>
                            {types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.code} · {t.name}
                                </option>
                            ))}
                        </select>
                        {locations.length > 1 && (
                            <select value={filters.location ?? ''} onChange={(e) => go({ location: e.target.value ? Number(e.target.value) : undefined })} className={smallInput} aria-label="Lokasi">
                                <option value="">Semua lokasi</option>
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <span className="ml-auto text-xs text-slate-400">{rows.length} sertifikat</span>
                    </div>
                    {rows.length === 0 ? (
                        <p className="py-14 text-center text-sm text-slate-400">Belum ada sertifikat yang cocok.</p>
                    ) : (
                        <div className="max-h-[65vh] overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-slate-50 text-left text-xs font-bold text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2.5">Karyawan</th>
                                        <th className="px-4 py-2.5">Sertifikat</th>
                                        <th className="px-4 py-2.5">Nomor</th>
                                        <th className="px-4 py-2.5">Berlaku sampai</th>
                                        <th className="px-4 py-2.5">Status</th>
                                        <th className="px-4 py-2.5">Berkas</th>
                                        <th className="px-4 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((r) => (
                                        <tr key={r.id} className="border-t border-slate-100">
                                            <td className="px-4 py-2">
                                                <span className="font-semibold text-slate-800">{r.name}</span>
                                                <span className="block text-xs text-slate-400">
                                                    {r.employee_number} · {r.position}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2">
                                                <span className="font-semibold text-slate-700">{r.type_code}</span>
                                                <span className="block text-xs text-slate-400">{r.issuer}</span>
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs text-slate-600">{r.number}</td>
                                            <td className="px-4 py-2 text-slate-600">{r.expires_at ? fmtDate(r.expires_at) : '–'}</td>
                                            <td className="px-4 py-2">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap ${CERT_TONE[r.status]}`}>{daysText(r.days_left)}</span>
                                            </td>
                                            <td className="px-4 py-2">
                                                <AttachmentButton files={r.attachments} title={`${r.type_code} ${r.name}`} noun="hal." />
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {can_edit && (
                                                    <span className="inline-flex gap-1">
                                                        <button onClick={() => setEditing(r)} className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Ubah">
                                                            <Pencil size={14} />
                                                        </button>
                                                        <button
                                                            onClick={() => confirm('Hapus sertifikat ini?') && router.delete(`${BASE}/${r.id}`, { preserveScroll: true })}
                                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                                                            title="Hapus"
                                                        >
                                                            <Trash2 size={14} />
                                                        </button>
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </main>
            {editing && (
                <CertificationForm row={editing === 'new' ? null : editing} types={types} employees={employees} limits={attachment_limits} onClose={() => setEditing(null)} />
            )}
            {addingType && <TypeForm onClose={() => setAddingType(false)} />}
        </>
    );
}

function CertificationForm({ row, types, employees, limits, onClose }: { row: CertificationRow | null; types: Type[]; employees: Props['employees']; limits: LampiranLimits; onClose: () => void }) {
    const [data, setData] = useState({
        employee_id: row?.employee_id ?? 0,
        certification_type_id: row?.type_id ?? types.find((t) => t.is_active)?.id ?? 0,
        number: row?.number ?? '',
        issuer: row?.issuer ?? '',
        issued_at: row?.issued_at ?? '',
        expires_at: row?.expires_at ?? '',
        notes: row?.notes ?? '',
    });
    const [files, setFiles] = useState(emptyDraft(row?.attachments ?? []));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [q, setQ] = useState('');
    const found = useMemo(() => employees.filter((e) => e.label.toLowerCase().includes(q.toLowerCase())).slice(0, 50), [employees, q]);
    const chosen = employees.find((e) => e.id === data.employee_id);
    const set = <K extends keyof typeof data>(k: K, v: (typeof data)[K]) => setData((d) => ({ ...d, [k]: v }));
    const save = () =>
        router.post(row ? `${BASE}/${row.id}` : BASE, { ...data, issued_at: data.issued_at || null, expires_at: data.expires_at || null, ...draftPayload(files) } as never, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: setErrors,
            onSuccess: onClose,
        });

    return (
        <Modal
            title={row ? 'Ubah sertifikat' : 'Catat sertifikat'}
            onClose={onClose}
            width="max-w-2xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy || !data.employee_id} className={primaryButton}>
                        {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Karyawan">
                    {chosen && !q ? (
                        <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                            {chosen.label}
                            <button onClick={() => set('employee_id', 0)} className="text-xs font-bold text-brand-600">
                                Ganti
                            </button>
                        </div>
                    ) : (
                        <>
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ketik nama atau NRP" className={inputClass} autoFocus />
                            {q && (
                                <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200">
                                    {found.map((e) => (
                                        <button key={e.id} onClick={() => (set('employee_id', e.id), setQ(''))} className="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50">
                                            {e.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Jenis">
                        <select value={data.certification_type_id} onChange={(e) => set('certification_type_id', Number(e.target.value))} className={inputClass}>
                            {types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.code} · {t.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Nomor">
                        <input value={data.number} onChange={(e) => set('number', e.target.value)} className={inputClass} />
                    </Field>
                    <Field label="Diterbitkan oleh">
                        <input value={data.issuer} onChange={(e) => set('issuer', e.target.value)} className={inputClass} />
                    </Field>
                    <div />
                    <Field label="Tanggal terbit">
                        <DateInput value={data.issued_at} onChange={(v) => set('issued_at', v)} />
                    </Field>
                    <Field label="Berlaku sampai" hint="Kosongkan bila berlaku seumur hidup.">
                        <DateInput value={data.expires_at} onChange={(v) => set('expires_at', v)} />
                        {errors.expires_at && <p className="mt-1 text-xs font-semibold text-rose-600">{errors.expires_at}</p>}
                    </Field>
                </div>
                <AttachmentPicker value={files} onChange={setFiles} limits={limits} label="Scan sertifikat" hint="Foto atau PDF." error={errors.attachment} />
                <Field label="Catatan">
                    <input value={data.notes} onChange={(e) => set('notes', e.target.value)} className={inputClass} />
                </Field>
            </div>
        </Modal>
    );
}

function TypeForm({ onClose }: { onClose: () => void }) {
    const form = useForm({ code: '', name: '', description: '', expires: true });

    return (
        <Modal
            title="Jenis sertifikat baru"
            onClose={onClose}
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={() => form.post(`${BASE}/types`, { preserveScroll: true, onSuccess: onClose })} disabled={!form.data.code || !form.data.name} className={primaryButton}>
                        <Check size={15} /> Simpan
                    </button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-[120px_1fr]">
                    <Field label="Kode">
                        <input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className={inputClass} />
                    </Field>
                    <Field label="Nama">
                        <input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={inputClass} />
                    </Field>
                </div>
                {form.errors.code && <p className="text-xs font-semibold text-rose-600">{form.errors.code}</p>}
                <Field label="Keterangan">
                    <input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} className={inputClass} />
                </Field>
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" checked={form.data.expires} onChange={(e) => form.setData('expires', e.target.checked)} /> Punya masa berlaku
                </label>
            </div>
        </Modal>
    );
}
