import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Clock, Loader2, Plus, RotateCcw, Send, Trash2, UserPen, X } from 'lucide-react';
import { FieldInput } from '@/components/profile/FieldInput';
import { PerubahanTable } from '@/components/profile/PerubahanTable';
import { LampiranButton, LampiranPicker } from '@/components/lampiran/LampiranPicker';
import { DateInput } from '@/components/DateInput';
import { draftPayload, emptyDraft, type LampiranLimits } from '@/lib/lampiran';
import {
    fmtDateTime,
    same,
    showValue,
    type AnakData,
    type FieldInfo,
    type FieldValue,
    type PengajuanRow,
    type ProfileSection,
} from '@/lib/dataUpdate';

interface Props {
    karyawan: { nrp: string; name: string; jabatan: string | null; work_location: string | null };
    profile: Record<string, FieldValue>;
    anak: (AnakData & { id: number })[];
    sections: ProfileSection[];
    options: { pendidikan_anak: string[]; status_bekerja_anak: string[]; status_menikah_anak: string[] };
    menunggu: PengajuanRow | null;
    riwayat: PengajuanRow[];
    lampiran_limits: LampiranLimits;
}

type AnakForm = AnakData & { key: string; hapus?: boolean };

const STATUS = {
    diajukan: { label: 'Menunggu HR', className: 'bg-amber-50 text-amber-700 ring-amber-200' },
    disetujui: { label: 'Disetujui', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    ditolak: { label: 'Ditolak', className: 'bg-rose-50 text-rose-700 ring-rose-200' },
} as const;

const ANAK_KEYS = ['nama', 'tanggal_lahir', 'pendidikan', 'status_bekerja', 'status_menikah'] as const;
const small = 'w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

/**
 * Portal Karyawan → Data Saya: the employee's own data. Changed fields are marked;
 * "Kirim ke HR" sends only what changed, and the data changes once HR approves it.
 */
export default function PortalData({ karyawan, profile, anak, sections, options, menunggu, riwayat, lampiran_limits }: Props) {
    const fieldInfo = useMemo<FieldInfo>(() => {
        const info: FieldInfo = {};
        for (const s of sections) for (const f of s.fields ?? []) info[f.key] = { label: f.label, type: f.type, section: s.title };
        return info;
    }, [sections]);

    const initialAnak = (): AnakForm[] => anak.map((a) => ({ ...a, key: `a${a.id}` }));
    const [values, setValues] = useState<Record<string, FieldValue>>(profile);
    const [children, setChildren] = useState<AnakForm[]>(initialAnak);
    const [catatan, setCatatan] = useState('');
    const [lampiran, setLampiran] = useState(() => emptyDraft());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [sending, setSending] = useState(false);

    const changedKeys = Object.keys(values).filter((k) => !same(values[k], profile[k]));
    const anakChanges = children.filter((c) => {
        if (!c.id) return !c.hapus && !!c.nama?.trim();
        if (c.hapus) return true;
        const before = anak.find((a) => a.id === c.id)!;
        return ANAK_KEYS.some((k) => !same(c[k], before[k]));
    }).length;
    const total = changedKeys.length + anakChanges;
    const locked = !!menunggu;

    const reset = () => {
        setValues(profile);
        setChildren(initialAnak());
        setErrors({});
    };

    const submit = () => {
        const data: Record<string, unknown> = {
            ...Object.fromEntries(changedKeys.map((k) => [k, values[k] ?? ''])),
            anak: children.map(({ key, ...c }) => ({ ...c, id: c.id ?? '', hapus: c.hapus ? 1 : 0 })),
            catatan: catatan || '',
            ...draftPayload(lampiran),
        };
        router.post('/portal/data', data as never, {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => setSending(true),
            onFinish: () => setSending(false),
            onError: (e) => setErrors(e),
            onSuccess: () => {
                setErrors({});
                setCatatan('');
                setLampiran(emptyDraft());
            },
        });
    };

    const cancel = () => {
        if (menunggu && confirm('Batalkan pengajuan update data ini?')) router.delete(`/portal/data/${menunggu.id}`, { preserveScroll: true });
    };

    const anakSection = (
        <div className="space-y-2">
            {children.map((c, i) => (
                <div
                    key={c.key}
                    className={`rounded-2xl border p-3 ${c.hapus ? 'border-rose-200 bg-rose-50/40 opacity-70' : !c.id ? 'border-emerald-200 bg-emerald-50/30' : 'border-slate-200'}`}
                >
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-xs font-black text-slate-500">
                            {c.id ? `Anak ke-${i + 1}` : 'Anak baru'}
                            {c.hapus && <span className="ml-2 text-rose-600">· akan dihapus</span>}
                        </span>
                        {!locked && (
                            <button
                                type="button"
                                onClick={() =>
                                    setChildren((list) =>
                                        c.id
                                            ? list.map((x) => (x.key === c.key ? { ...x, hapus: !x.hapus } : x))
                                            : list.filter((x) => x.key !== c.key),
                                    )
                                }
                                title={c.hapus ? 'Batal hapus' : 'Hapus anak ini'}
                                className="rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                            >
                                {c.hapus ? <RotateCcw size={14} /> : c.id ? <Trash2 size={14} /> : <X size={14} />}
                            </button>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-6">
                        <input
                            value={c.nama ?? ''}
                            onChange={(e) => setChildren((l) => l.map((x) => (x.key === c.key ? { ...x, nama: e.target.value } : x)))}
                            placeholder="Nama anak"
                            disabled={locked || c.hapus}
                            className={`${small} col-span-2`}
                            aria-label="Nama anak"
                        />
                        <DateInput
                            value={c.tanggal_lahir ?? ''}
                            onChange={(v) => setChildren((l) => l.map((x) => (x.key === c.key ? { ...x, tanggal_lahir: v || null } : x)))}
                            disabled={locked || c.hapus}
                            className={small}
                            aria-label="Tanggal lahir anak"
                        />
                        {(
                            [
                                ['pendidikan', options.pendidikan_anak, 'Pendidikan'],
                                ['status_bekerja', options.status_bekerja_anak, 'Status bekerja'],
                                ['status_menikah', options.status_menikah_anak, 'Status menikah'],
                            ] as const
                        ).map(([k, list, label]) => (
                            <select
                                key={k}
                                value={c[k] ?? ''}
                                onChange={(e) => setChildren((l) => l.map((x) => (x.key === c.key ? { ...x, [k]: e.target.value || null } : x)))}
                                disabled={locked || c.hapus}
                                className={small}
                                aria-label={label}
                            >
                                <option value="">{label}...</option>
                                {list.map((o) => (
                                    <option key={o}>{o}</option>
                                ))}
                            </select>
                        ))}
                    </div>
                </div>
            ))}
            {!locked && (
                <button
                    type="button"
                    onClick={() =>
                        setChildren((l) => [
                            ...l,
                            {
                                key: `n${Date.now()}`,
                                id: null,
                                nama: '',
                                tanggal_lahir: null,
                                pendidikan: null,
                                status_bekerja: null,
                                status_menikah: null,
                            },
                        ])
                    }
                    className="flex items-center gap-1.5 rounded-xl border border-dashed border-slate-300 px-3 py-2 text-xs font-bold text-brand-600 hover:bg-brand-50"
                >
                    <Plus size={14} /> Tambah anak
                </button>
            )}
            {errors.anak && <p className="text-xs text-rose-600">{errors.anak}</p>}
        </div>
    );

    return (
        <>
            <Head title="Data Saya" />
            <div className="space-y-5 pb-28">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <Link href="/portal" className="mb-2 inline-flex items-center gap-1 text-xs font-bold text-slate-400 hover:text-brand-600">
                        <ArrowLeft size={13} /> Beranda
                    </Link>
                    <h1 className="flex items-center gap-2 text-xl font-black text-slate-800">
                        <UserPen size={20} className="text-brand-500" />
                        Data Saya
                    </h1>
                    <p className="mt-0.5 text-sm text-slate-500">
                        {karyawan.name} · {karyawan.nrp} · {karyawan.jabatan ?? 'Belum ditempatkan'}
                    </p>
                    <p className="mt-2 text-xs text-slate-400">
                        Ubah data yang perlu diperbarui, lalu kirim ke HR. Data baru berlaku setelah disetujui. Identitas, penempatan, kontrak dan
                        golongan diubah oleh HR.
                    </p>
                </section>

                {menunggu && (
                    <section className="space-y-3 rounded-3xl border border-amber-200 bg-amber-50/50 p-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <Clock size={16} className="text-amber-600" />
                            <h2 className="text-sm font-bold text-amber-800">Pengajuan menunggu verifikasi HR</h2>
                            <span className="text-xs text-amber-700">
                                dikirim {fmtDateTime(menunggu.created_at)} · {menunggu.jumlah} perubahan
                            </span>
                            <span className="ml-auto flex items-center gap-2">
                                {menunggu.lampiran.length > 0 && <LampiranButton files={menunggu.lampiran} title="Dokumen pendukung" />}
                                <button
                                    onClick={cancel}
                                    className="rounded-xl border border-rose-200 bg-white px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50"
                                >
                                    Batalkan
                                </button>
                            </span>
                        </div>
                        <div className="rounded-2xl bg-white">
                            <PerubahanTable row={menunggu} fields={fieldInfo} />
                        </div>
                        {menunggu.catatan && <p className="text-xs text-slate-600">Catatan Anda: {menunggu.catatan}</p>}
                    </section>
                )}

                {sections.map((s) => (
                    <section key={s.id} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="mb-3 text-sm font-bold text-slate-700">{s.title.replace(/^\d+\.\s*/, '')}</h2>
                        {s.kind === 'anak' ? (
                            anakSection
                        ) : (
                            <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                                {s.fields?.map((f) => {
                                    const changed = !same(values[f.key], profile[f.key]);
                                    return (
                                        <div key={f.key} className={f.type === 'textarea' ? 'sm:col-span-2' : ''}>
                                            <label className="mb-1 block text-[11px] font-bold text-slate-500">{f.label}</label>
                                            <FieldInput
                                                field={f}
                                                value={values[f.key]}
                                                onChange={(v) => setValues((cur) => ({ ...cur, [f.key]: v }))}
                                                changed={changed}
                                                invalid={!!errors[f.key]}
                                                disabled={locked}
                                            />
                                            {changed && (
                                                <p className="mt-0.5 text-[10px] text-amber-700">Sebelumnya: {showValue(profile[f.key], f.type)}</p>
                                            )}
                                            {errors[f.key] && <p className="mt-0.5 text-[11px] text-rose-600">{errors[f.key]}</p>}
                                            {f.hint && !changed && !errors[f.key] && <p className="mt-0.5 text-[10px] text-slate-400">{f.hint}</p>}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>
                ))}

                {riwayat.length > 0 && (
                    <section className="rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <h2 className="flex items-center gap-2 border-b border-slate-100 px-5 py-3.5 text-sm font-bold text-slate-700">
                            <Clock size={15} className="text-brand-500" />
                            Riwayat pengajuan
                        </h2>
                        <ul className="divide-y divide-slate-100">
                            {riwayat.map((r) => (
                                <History key={r.id} row={r} fields={fieldInfo} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {!locked && total > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 shadow-2xl backdrop-blur">
                    <div className="mx-auto grid max-w-5xl gap-3 px-4 py-3 md:grid-cols-[1fr_1fr_auto] md:items-end">
                        <div>
                            <p className="mb-1 text-[11px] font-bold text-slate-500">
                                <b className="text-amber-700">{total} perubahan</b> · catatan untuk HR (opsional)
                            </p>
                            <input
                                value={catatan}
                                onChange={(e) => setCatatan(e.target.value)}
                                maxLength={500}
                                placeholder="mis. ganti nomor rekening, KTP baru"
                                className={small}
                            />
                            {errors.perubahan && <p className="mt-1 text-[11px] text-rose-600">{errors.perubahan}</p>}
                        </div>
                        <details className="text-xs">
                            <summary className="cursor-pointer font-bold text-brand-600">Lampirkan dokumen pendukung (opsional)</summary>
                            <div className="mt-2">
                                <LampiranPicker
                                    value={lampiran}
                                    onChange={setLampiran}
                                    limits={lampiran_limits}
                                    label="KTP / KK / buku nikah / ijazah / buku rekening"
                                />
                            </div>
                        </details>
                        <div className="flex gap-2">
                            <button
                                onClick={reset}
                                className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-500 hover:bg-slate-50"
                            >
                                Batal
                            </button>
                            <button
                                onClick={submit}
                                disabled={sending}
                                className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50"
                            >
                                {sending ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                                Kirim ke HR
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function History({ row, fields }: { row: PengajuanRow; fields: FieldInfo }) {
    const [open, setOpen] = useState(false);
    const s = STATUS[row.status];
    return (
        <li className="px-5 py-3">
            <button onClick={() => setOpen((o) => !o)} className="flex w-full items-center gap-3 text-left">
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-bold text-slate-700">{row.jumlah} perubahan data</span>
                    <span className="block text-[11px] text-slate-400">
                        dikirim {fmtDateTime(row.created_at)}
                        {row.verified_at && ` · diputuskan ${fmtDateTime(row.verified_at)}`}
                    </span>
                    {row.catatan_verifikasi && <span className="block text-[11px] text-rose-600">Catatan HR: {row.catatan_verifikasi}</span>}
                </span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset ${s.className}`}>{s.label}</span>
            </button>
            {open && (
                <div className="mt-3">
                    <PerubahanTable row={row} fields={fields} />
                </div>
            )}
        </li>
    );
}
