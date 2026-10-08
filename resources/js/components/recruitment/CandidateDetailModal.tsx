import { useCallback, useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    BadgeCheck,
    Ban,
    CalendarDays,
    ExternalLink,
    FileText,
    History,
    Loader2,
    Mail,
    MessageSquareText,
    Pencil,
    Phone,
    RotateCcw,
    Send,
    Trash2,
    UserPlus,
    X,
} from 'lucide-react';
import { AddKaryawanDialog } from '@/components/manpower/AddKaryawanDialog';
import { CandidateFormDialog } from '@/components/recruitment/CandidateFormDialog';
import { Jawaban } from '@/components/recruitment/Jawaban';
import { fmtDate } from '@/lib/format';
import { deleteJson, postJson } from '@/lib/http';
import { tint, type CandidateDetail, type Stage } from '@/lib/recruitment';

const visit = { preserveScroll: true, preserveState: true } as const;

/** Everything about one candidate, with what HR can do from here. */
export function CandidateDetailModal({
    candidateId,
    stages,
    posisiOptions,
    canEdit,
    canKaryawan,
    onClose,
}: {
    candidateId: number;
    stages: Stage[];
    posisiOptions: string[];
    canEdit: boolean;
    canKaryawan: boolean;
    onClose: () => void;
}) {
    const [c, setC] = useState<CandidateDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [dialog, setDialog] = useState<'edit' | 'karyawan' | 'tolak' | null>(null);
    const [alasan, setAlasan] = useState('');

    const load = useCallback(() => {
        fetch(`/recruitment/kandidat/${candidateId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Data kandidat tidak bisa dimuat.'))))
            .then(setC)
            .catch((e: Error) => setError(e.message));
    }, [candidateId]);
    useEffect(load, [load]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && !dialog && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [dialog, onClose]);

    const refresh = () => {
        load();
        router.reload({ only: ['stages', 'jumlah', 'posisi_options'] });
    };

    const moveTo = (stageId: number) => {
        const target = stages.find((s) => s.id === stageId);
        if (!c || !target || stageId === c.stage.id) return;
        router.post(
            '/recruitment/kandidat/pindah',
            { candidate_id: c.id, stage_id: stageId, ordered_ids: [...target.candidates.map((x) => x.id).filter((id) => id !== c.id), c.id] },
            { ...visit, only: ['stages', 'jumlah'], onSuccess: load },
        );
    };

    const addNote = async () => {
        if (!c || !note.trim()) return;
        setSaving(true);
        try {
            const saved = await postJson<CandidateDetail['notes'][number]>(`/recruitment/kandidat/${c.id}/catatan`, { content: note });
            setC({ ...c, notes: [saved, ...c.notes] });
            setNote('');
            router.reload({ only: ['stages'] });
        } catch (e) {
            alert((e as Error).message);
        } finally {
            setSaving(false);
        }
    };

    const removeNote = async (id: number) => {
        if (!c || !confirm('Hapus catatan ini?')) return;
        await deleteJson(`/recruitment/catatan/${id}`).catch((e: Error) => alert(e.message));
        setC({ ...c, notes: c.notes.filter((n) => n.id !== id) });
    };

    const reject = (tolak: boolean) =>
        c &&
        router.post(
            `/recruitment/kandidat/${c.id}/tolak`,
            { tolak, alasan: tolak ? alasan : null },
            {
                ...visit,
                onSuccess: () => {
                    setDialog(null);
                    setAlasan('');
                    refresh();
                },
            },
        );

    const destroy = () =>
        c &&
        confirm(`Hapus ${c.name} dari Recruitment Tracking?${c.form ? ' Lamarannya kembali ke daftar Pelamar.' : ''}`) &&
        router.delete(`/recruitment/kandidat/${c.id}`, { ...visit, onSuccess: onClose });

    const wa = c?.no_hp ? `https://wa.me/${c.no_hp.replace(/\D/g, '').replace(/^0/, '62')}` : null;

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-slate-900/40 backdrop-blur-sm sm:items-center sm:p-6"
            onMouseDown={onClose}
        >
            <div
                className="flex max-h-[94dvh] w-full max-w-5xl min-w-0 flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-h-full sm:rounded-3xl"
                onMouseDown={(e) => e.stopPropagation()}
                role="dialog"
                aria-label={c?.name ?? 'Detail kandidat'}
            >
                {!c ? (
                    <div className="flex items-center justify-center gap-2 p-16 text-sm text-slate-400">
                        {error ?? (
                            <>
                                <Loader2 size={16} className="animate-spin" /> Memuat…
                            </>
                        )}
                    </div>
                ) : (
                    <>
                        {/* Header */}
                        <div className="flex flex-wrap items-start gap-3 border-b border-slate-100 px-4 py-4 sm:gap-4 sm:px-6 sm:py-5">
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-xl font-black text-brand-600 uppercase">
                                {c.name.charAt(0)}
                            </div>
                            <div className="min-w-0 flex-1">
                                <h2 className="truncate text-lg font-bold text-slate-800 sm:text-xl">{c.name}</h2>
                                <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                    <span>{c.position}</span>
                                    <span className="rounded-full px-2.5 py-0.5 text-[11px] font-bold" style={tint(c.stage.color)}>
                                        {c.stage.title}
                                    </span>
                                    {c.ditolak && (
                                        <span className="rounded-full bg-rose-50 px-2.5 py-0.5 text-[11px] font-bold text-rose-600">
                                            Tidak dilanjutkan
                                        </span>
                                    )}
                                    {c.karyawan && (
                                        <Link
                                            href={`/manpower/karyawan/${c.karyawan.id}/profil`}
                                            className="flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 hover:underline"
                                        >
                                            <BadgeCheck size={13} /> Karyawan · NRP {c.karyawan.nrp}
                                        </Link>
                                    )}
                                </div>
                            </div>
                            <button onClick={onClose} aria-label="Tutup" className="rounded-xl p-2 text-slate-400 hover:bg-slate-100">
                                <X size={20} />
                            </button>
                        </div>

                        {canEdit && (
                            <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 sm:px-6">
                                {!c.karyawan && !c.ditolak && (
                                    <label className="flex items-center gap-2 text-xs font-bold text-slate-500">
                                        Pindah ke
                                        <select
                                            value={c.stage.id}
                                            onChange={(e) => moveTo(Number(e.target.value))}
                                            className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-brand-500"
                                        >
                                            {stages.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    {s.title}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                <div className="ml-auto flex flex-wrap gap-1.5">
                                    {!c.karyawan && !c.ditolak && c.tahap_terakhir && canKaryawan && (
                                        <button
                                            onClick={() => setDialog('karyawan')}
                                            className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700"
                                        >
                                            <UserPlus size={14} /> Jadikan Karyawan
                                        </button>
                                    )}
                                    <button
                                        onClick={() => setDialog('edit')}
                                        className="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-white"
                                    >
                                        <Pencil size={14} /> Ubah
                                    </button>
                                    {!c.karyawan &&
                                        (c.ditolak ? (
                                            <button
                                                onClick={() => reject(false)}
                                                className="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-white"
                                            >
                                                <RotateCcw size={14} /> Lanjutkan kembali
                                            </button>
                                        ) : (
                                            <button
                                                onClick={() => setDialog('tolak')}
                                                className="flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-white"
                                            >
                                                <Ban size={14} /> Tidak dilanjutkan
                                            </button>
                                        ))}
                                    {!c.karyawan && (
                                        <button
                                            onClick={destroy}
                                            title="Hapus kandidat"
                                            aria-label="Hapus kandidat"
                                            className="rounded-xl p-1.5 text-slate-400 hover:bg-white hover:text-rose-600"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    )}
                                </div>
                                {dialog === 'tolak' && (
                                    <div className="flex w-full flex-wrap items-center gap-2 rounded-xl border border-rose-100 bg-white p-2">
                                        <input
                                            value={alasan}
                                            onChange={(e) => setAlasan(e.target.value)}
                                            autoFocus
                                            maxLength={255}
                                            placeholder="Alasan, mis. tidak lolos psikotes"
                                            className="min-w-0 flex-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-rose-300"
                                        />
                                        <button
                                            onClick={() => setDialog(null)}
                                            className="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-100"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            onClick={() => reject(true)}
                                            disabled={!alasan.trim()}
                                            className="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-50"
                                        >
                                            Simpan
                                        </button>
                                    </div>
                                )}
                            </div>
                        )}
                        {c.ditolak && (
                            <p className="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-700 sm:px-6">
                                Tidak dilanjutkan sejak {fmtDate(c.ditolak.tanggal)}
                                {c.ditolak.alasan ? ` — ${c.ditolak.alasan}` : ''}.
                            </p>
                        )}

                        <div className="grid min-h-0 flex-1 gap-5 overflow-y-auto p-4 sm:p-6 md:grid-cols-[minmax(0,1fr)_280px] [&>*]:min-w-0">
                            <div className="space-y-6">
                                <section className="grid gap-3 sm:grid-cols-2">
                                    <Info icon={Mail} label="Email" value={c.email} href={c.email ? `mailto:${c.email}` : undefined} />
                                    <Info icon={Phone} label="Nomor HP" value={c.no_hp} href={wa ?? undefined} hint={wa ? 'WhatsApp' : undefined} />
                                    <Info icon={CalendarDays} label="Tanggal melamar" value={fmtDate(c.applied_date)} />
                                    <Info
                                        icon={ExternalLink}
                                        label="Portofolio"
                                        value={c.portfolio_url ? 'Buka tautan' : null}
                                        href={c.portfolio_url ?? undefined}
                                    />
                                </section>

                                {c.jawaban.length > 0 && (
                                    <section>
                                        <h3 className="mb-2 text-xs font-bold tracking-widest text-slate-500 uppercase">
                                            Jawaban lamaran · {c.form}
                                        </h3>
                                        <Jawaban items={c.jawaban} />
                                    </section>
                                )}

                                <section>
                                    <h3 className="mb-2 flex items-center gap-2 text-xs font-bold tracking-widest text-slate-500 uppercase">
                                        <MessageSquareText size={14} /> Catatan rekruter ({c.notes.length})
                                    </h3>
                                    {canEdit && (
                                        <div className="mb-3 rounded-2xl border border-slate-200 p-2 focus-within:ring-2 focus-within:ring-brand-500">
                                            <textarea
                                                value={note}
                                                onChange={(e) => setNote(e.target.value)}
                                                placeholder="Hasil interview, nilai psikotes, kesan user..."
                                                className="min-h-20 w-full resize-y bg-transparent p-2 text-sm outline-none"
                                            />
                                            <div className="flex justify-end">
                                                <button
                                                    onClick={addNote}
                                                    disabled={saving || !note.trim()}
                                                    className="flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                                >
                                                    {saving ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />} Simpan catatan
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                    <div className="space-y-2">
                                        {c.notes.map((n) => (
                                            <div key={n.id} className="group rounded-2xl border border-brand-100 bg-brand-50/50 p-3">
                                                <div className="mb-1 flex items-center gap-2 text-[11px]">
                                                    <b className="text-brand-700">{n.author}</b>
                                                    <span className="text-slate-400">
                                                        {new Date(n.waktu).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}
                                                    </span>
                                                    {canEdit && (
                                                        <button
                                                            onClick={() => removeNote(n.id)}
                                                            aria-label="Hapus catatan"
                                                            className="ml-auto text-slate-300 opacity-0 group-hover:opacity-100 hover:text-rose-600 focus:opacity-100"
                                                        >
                                                            <Trash2 size={13} />
                                                        </button>
                                                    )}
                                                </div>
                                                <p className="text-sm whitespace-pre-line text-slate-700">{n.content}</p>
                                            </div>
                                        ))}
                                        {c.notes.length === 0 && <p className="text-xs text-slate-400">Belum ada catatan.</p>}
                                    </div>
                                </section>
                            </div>

                            <aside className="space-y-4">
                                <div className="rounded-2xl border border-slate-200 p-4">
                                    <p className="mb-2 flex items-center gap-2 text-[11px] font-bold tracking-wide text-slate-500 uppercase">
                                        <FileText size={14} /> CV
                                    </p>
                                    {c.cv ? (
                                        <a
                                            href={c.cv.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2.5 text-sm font-bold text-brand-700 hover:bg-brand-50"
                                        >
                                            <FileText size={16} />
                                            <span className="min-w-0 flex-1 truncate">{c.cv.nama}</span>
                                            <ExternalLink size={14} />
                                        </a>
                                    ) : (
                                        <p className="text-xs text-slate-400">Belum ada CV.{canEdit ? ' Unggah lewat Ubah.' : ''}</p>
                                    )}
                                </div>
                                <div className="rounded-2xl bg-slate-900 p-4 text-white">
                                    <p className="mb-3 flex items-center gap-2 text-[11px] font-bold tracking-wide text-slate-400 uppercase">
                                        <History size={14} /> Riwayat tahap
                                    </p>
                                    <ol className="space-y-3">
                                        {c.history.map((h, i) => (
                                            <li key={i} className="relative pl-4">
                                                <span className="absolute top-1.5 left-0 h-1.5 w-1.5 rounded-full bg-accent-400" />
                                                <p className="text-xs text-slate-200">
                                                    {h.from} → <b className="text-accent-300">{h.to}</b>
                                                </p>
                                                <p className="text-[10px] text-slate-500">
                                                    {new Date(h.waktu).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })} · {h.by}
                                                </p>
                                            </li>
                                        ))}
                                        {c.history.length === 0 && <li className="text-xs text-slate-500 italic">Belum pindah tahap.</li>}
                                    </ol>
                                </div>
                            </aside>
                        </div>
                    </>
                )}
            </div>

            {c && dialog === 'edit' && (
                <div onMouseDown={(e) => e.stopPropagation()}>
                    <CandidateFormDialog
                        candidate={c}
                        stages={stages}
                        posisiOptions={posisiOptions}
                        onClose={() => setDialog(null)}
                        onSaved={refresh}
                    />
                </div>
            )}
            {c && dialog === 'karyawan' && (
                <div onMouseDown={(e) => e.stopPropagation()}>
                    <AddKaryawanDialog
                        title={`Jadikan Karyawan — ${c.name}`}
                        submitUrl={`/recruitment/kandidat/${c.id}/karyawan`}
                        initial={{ name: c.name }}
                        fixedJabatan={c.jabatan ?? undefined}
                        tanggalMasuk
                        onClose={() => setDialog(null)}
                        onSaved={refresh}
                    />
                </div>
            )}
        </div>
    );
}

function Info({
    icon: Icon,
    label,
    value,
    href,
    hint,
}: {
    icon: typeof Mail;
    label: string;
    value: string | null | undefined;
    href?: string;
    hint?: string;
}) {
    const body = (
        <>
            <Icon size={17} className="shrink-0 text-slate-400" />
            <span className="min-w-0">
                <span className="block text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                    {label}
                    {hint && value ? ` · ${hint}` : ''}
                </span>
                <span className={`block truncate text-sm font-medium ${href && value ? 'text-brand-700' : 'text-slate-700'}`}>{value || '-'}</span>
            </span>
        </>
    );
    const cls = 'flex items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3';
    return href && value ? (
        <a href={href} target="_blank" rel="noreferrer" className={`${cls} hover:border-brand-200`}>
            {body}
        </a>
    ) : (
        <div className={cls}>{body}</div>
    );
}
