import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronDown, FileUp, Loader2, Undo2, X } from 'lucide-react';
import { Modal, ghostButton, primaryButton } from '@/components/attendance/ui';
import { postForm } from '@/lib/http';
import { FIELD_LABEL, rupiahShort, type MppRow, type Revisi, type Totals } from '@/lib/mpp';

const visit = { preserveScroll: true, preserveState: true } as const;

/** Tiles, Plan vs Actual per departemen, the category summary and the positions most short. */
export function MppDashboard({
    groups,
    total,
    kategori,
    belum,
    bulanCount,
}: {
    groups: { departemen: string; rows: MppRow[]; total: Totals }[];
    total: Totals;
    kategori: ({ kategori: string; label: string } & Totals)[];
    belum: number;
    bulanCount: number;
}) {
    const max = Math.max(1, ...groups.map((g) => Math.max(g.total.plan, g.total.actual)));
    const kurang = groups
        .flatMap((g) => g.rows)
        .filter((r) => r.gap < 0)
        .sort((a, b) => a.gap - b.gap)
        .slice(0, 5);
    const tiles = [
        { label: 'Plan', value: total.plan, sub: 'kebutuhan orang' },
        { label: 'Actual', value: total.actual, sub: belum ? `+ ${belum} belum ditempatkan` : 'karyawan aktif di jabatan' },
        { label: 'Kekurangan', value: total.kurang, sub: 'perlu dipenuhi', tone: total.kurang ? 'text-rose-600' : 'text-slate-800' },
        { label: 'Kelebihan', value: total.lebih, sub: 'di atas plan', tone: total.lebih ? 'text-amber-600' : 'text-slate-800' },
        { label: 'Estimasi biaya', value: rupiahShort(total.biaya), sub: `/ bulan · ${rupiahShort(total.biaya * bulanCount)} / periode` },
    ];

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5 [&>*]:min-w-0">
                {tiles.map((t) => (
                    <div key={t.label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">{t.label}</p>
                        <p className={`mt-1 truncate text-2xl font-black tabular-nums ${t.tone ?? 'text-slate-800'}`}>{t.value}</p>
                        <p className="truncate text-[11px] text-slate-400">{t.sub}</p>
                    </div>
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] [&>*]:min-w-0">
                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1">
                        <h3 className="text-sm font-bold text-slate-800">Plan vs Actual per departemen</h3>
                        <span className="flex items-center gap-1.5 text-[11px] text-slate-500">
                            <span className="h-2.5 w-4 rounded-sm bg-brand-500" /> Actual
                        </span>
                        <span className="flex items-center gap-1.5 text-[11px] text-slate-500">
                            <span className="h-3 w-0.5 bg-slate-800" /> Plan
                        </span>
                    </div>
                    <div className="space-y-2">
                        {groups.map((g) => (
                            <div
                                key={g.departemen}
                                className="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)_4.5rem] items-center gap-3"
                                title={`${g.departemen}: plan ${g.total.plan}, actual ${g.total.actual}`}
                            >
                                <span className="truncate text-xs font-bold text-slate-600">{g.departemen}</span>
                                <div className="relative h-5 rounded-r bg-slate-100">
                                    <div className="h-full rounded-r bg-brand-500" style={{ width: `${(g.total.actual / max) * 100}%` }} />
                                    <div
                                        className="absolute -top-0.5 -bottom-0.5 w-0.5 bg-slate-800"
                                        style={{ left: `calc(${(g.total.plan / max) * 100}% - 1px)` }}
                                    />
                                </div>
                                <span className="text-right text-xs tabular-nums">
                                    <b className="text-slate-800">{g.total.actual}</b>
                                    <span className="text-slate-400"> / {g.total.plan}</span>
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
                <div className="space-y-4">
                    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Kategori</th>
                                    <th className="px-3 py-2 text-right">Plan</th>
                                    <th className="px-3 py-2 text-right">Actual</th>
                                    <th className="px-3 py-2 text-right">Dev</th>
                                    <th className="px-3 py-2 text-right">Kurang</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 tabular-nums">
                                {kategori.map((k) => (
                                    <tr key={k.kategori}>
                                        <td className="px-3 py-2 font-bold text-slate-700">{k.label}</td>
                                        <td className="px-3 py-2 text-right">{k.plan}</td>
                                        <td className="px-3 py-2 text-right">{k.actual}</td>
                                        <td
                                            className={`px-3 py-2 text-right font-bold ${k.actual - k.plan < 0 ? 'text-rose-600' : 'text-slate-700'}`}
                                        >
                                            {k.actual - k.plan}
                                        </td>
                                        <td className="px-3 py-2 text-right">{k.kurang || '·'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                    <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 className="mb-2 text-sm font-bold text-slate-800">Paling kurang orang</h3>
                        {kurang.length === 0 ? (
                            <p className="text-xs text-slate-400">Tidak ada jabatan yang kurang orang.</p>
                        ) : (
                            <ul className="space-y-1.5">
                                {kurang.map((r) => (
                                    <li key={r.jabatan_id} className="flex items-center gap-2 text-xs">
                                        <span className="w-9 shrink-0 rounded-md bg-rose-50 py-0.5 text-center font-black text-rose-700">
                                            {r.gap}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate font-bold text-slate-700">{r.jabatan}</span>
                                        <span className="shrink-0 text-slate-400">
                                            {r.actual}/{r.plan}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </div>
    );
}

/** A revision waiting for an approver: what it changes, and the decision. */
export function RevisiMenunggu({ r, canApprove, userId }: { r: Revisi; canApprove: boolean; userId: number }) {
    const [catatan, setCatatan] = useState('');
    const [busy, setBusy] = useState(false);
    const decide = (keputusan: 'setujui' | 'tolak') => {
        setBusy(true);
        router.post(`/manpower/mpp/revisi/${r.id}/verifikasi`, { keputusan, catatan }, { ...visit, onFinish: () => setBusy(false) });
    };

    return (
        <section className="rounded-2xl border border-amber-200 bg-amber-50/70 p-4">
            <p className="flex flex-wrap items-center gap-2 text-sm text-amber-900">
                <AlertTriangle size={16} />
                <b>Revisi menunggu persetujuan</b>
                <span className="text-amber-800">
                    dari {r.pembuat ?? '-'} · {r.waktu ? new Date(r.waktu).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : ''}
                </span>
            </p>
            {r.catatan && <p className="mt-1 text-xs text-amber-800">Catatan: {r.catatan}</p>}
            <Perubahan r={r} />
            <div className="mt-3 flex flex-wrap items-center gap-2">
                {canApprove && (
                    <>
                        <input
                            value={catatan}
                            onChange={(e) => setCatatan(e.target.value)}
                            maxLength={255}
                            placeholder="Catatan (wajib bila ditolak)"
                            className="min-w-0 flex-1 rounded-lg border border-amber-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-amber-300"
                        />
                        <button
                            onClick={() => decide('tolak')}
                            disabled={busy || !catatan.trim()}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                        >
                            <X size={14} /> Tolak
                        </button>
                        <button
                            onClick={() => decide('setujui')}
                            disabled={busy}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {busy ? <Loader2 size={14} className="animate-spin" /> : <Check size={14} />} Setujui
                        </button>
                    </>
                )}
                {(r.created_by === userId || canApprove) && (
                    <button
                        onClick={() => confirm('Batalkan revisi ini?') && router.post(`/manpower/mpp/revisi/${r.id}/batal`, {}, visit)}
                        className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-white"
                    >
                        <Undo2 size={14} /> Batalkan
                    </button>
                )}
                {!canApprove && <span className="text-xs text-amber-800">Plan resmi belum berubah sampai revisi ini disetujui.</span>}
            </div>
        </section>
    );
}

function Perubahan({ r }: { r: Revisi }) {
    return (
        <div className="mt-2 max-h-56 overflow-y-auto rounded-xl border border-amber-100 bg-white">
            <table className="w-full text-xs">
                <tbody className="divide-y divide-slate-100">
                    {r.perubahan.flatMap((p) =>
                        p.fields.map((f, i) => (
                            <tr key={`${p.jabatan_id}-${f.field}`}>
                                <td className="px-3 py-1.5 font-bold text-slate-700">{i === 0 ? p.jabatan : ''}</td>
                                <td className="px-3 py-1.5 text-slate-500">{FIELD_LABEL[f.field] ?? f.field}</td>
                                <td className="px-3 py-1.5 text-right whitespace-nowrap tabular-nums">
                                    <span className="text-slate-400 line-through">{String(f.before)}</span> →{' '}
                                    <b className="text-slate-800">{String(f.after)}</b>
                                </td>
                            </tr>
                        )),
                    )}
                </tbody>
            </table>
        </div>
    );
}

/** Decided revisions and changes saved straight away. */
export function Riwayat({ items }: { items: Revisi[] }) {
    const [open, setOpen] = useState<number | null>(null);
    if (items.length === 0) return <p className="text-xs text-slate-400">Belum ada perubahan di periode ini.</p>;
    const tone: Record<Revisi['status'], string> = {
        diajukan: 'bg-amber-50 text-amber-700',
        disetujui: 'bg-emerald-50 text-emerald-700',
        ditolak: 'bg-rose-50 text-rose-600',
        dibatalkan: 'bg-slate-100 text-slate-500',
    };
    return (
        <ul className="divide-y divide-slate-100">
            {items.map((r) => (
                <li key={r.id} className="py-2">
                    <button onClick={() => setOpen(open === r.id ? null : r.id)} className="flex w-full items-center gap-2 text-left text-xs">
                        <span className={`rounded-full px-2 py-0.5 font-bold ${tone[r.status]}`}>{r.langsung ? 'Diubah langsung' : r.status}</span>
                        <span className="min-w-0 flex-1 truncate text-slate-600">
                            {r.perubahan.length} jabatan · {r.pembuat ?? '-'}
                            {r.penyetuju && !r.langsung ? ` · diputuskan ${r.penyetuju}` : ''}
                            {r.catatan ? ` · ${r.catatan}` : ''}
                        </span>
                        <span className="shrink-0 text-slate-400">
                            {r.waktu ? new Date(r.waktu).toLocaleDateString('id-ID', { dateStyle: 'medium' }) : ''}
                        </span>
                        <ChevronDown size={14} className={`shrink-0 text-slate-400 transition-transform ${open === r.id ? 'rotate-180' : ''}`} />
                    </button>
                    {open === r.id && (
                        <>
                            {r.catatan_verifikasi && <p className="mt-1 text-xs text-slate-500">Catatan keputusan: {r.catatan_verifikasi}</p>}
                            <Perubahan r={r} />
                        </>
                    )}
                </li>
            ))}
        </ul>
    );
}

interface Preview {
    cocok: number;
    tidak_cocok: { baris: number; jabatan: string; departemen: string | null }[];
    perubahan: Revisi['perubahan'];
}

/** Excel in, previewed (matched positions, unknown ones, the changes) before it is saved or sent. */
export function ImportDialog({ planId, canApprove, onClose }: { planId: number; canApprove: boolean; onClose: () => void }) {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const check = async (f: File) => {
        setFile(f);
        setPreview(null);
        setError(null);
        setBusy(true);
        const body = new FormData();
        body.append('file', f);
        try {
            setPreview(await postForm<Preview>(`/manpower/mpp/${planId}/import`, body));
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const save = () => {
        if (!file) return;
        setBusy(true);
        router.post(
            `/manpower/mpp/${planId}/import`,
            { file, simpan: 1 },
            { forceFormData: true, preserveScroll: true, onSuccess: onClose, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Modal
            title="Import MPP dari Excel"
            subtitle="Format sheet MPP (judul di baris 2–4, Kategori di kolom A, Jabatan di kolom B). Jabatan dicocokkan dengan nama jabatan di lokasi ini."
            onClose={onClose}
            width="max-w-2xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button onClick={save} disabled={busy || !preview || preview.perubahan.length === 0} className={primaryButton}>
                        {busy && preview ? <Loader2 size={16} className="animate-spin" /> : <Check size={16} />}
                        {canApprove ? 'Simpan ke plan' : 'Ajukan sebagai revisi'}
                    </button>
                </>
            }
        >
            <label className="flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 hover:border-brand-300">
                <FileUp size={22} className="text-slate-400" />
                <span className="min-w-0 flex-1 truncate text-sm font-bold text-slate-600">{file?.name ?? 'Pilih file .xlsx'}</span>
                {busy && !preview && <Loader2 size={16} className="animate-spin text-slate-400" />}
                <input type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => e.target.files?.[0] && check(e.target.files[0])} />
            </label>
            {error && <p className="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700">{error}</p>}
            {preview && (
                <div className="mt-4 space-y-3 text-sm">
                    <p>
                        <b className="text-emerald-700">{preview.cocok} jabatan cocok</b> · <b>{preview.perubahan.length}</b> berubah
                        {preview.tidak_cocok.length > 0 && (
                            <b className="text-amber-700"> · {preview.tidak_cocok.length} tidak ditemukan di lokasi ini</b>
                        )}
                    </p>
                    {preview.tidak_cocok.length > 0 && (
                        <div className="max-h-36 overflow-y-auto rounded-xl border border-amber-100 bg-amber-50/60 px-3 py-2 text-xs text-amber-900">
                            {preview.tidak_cocok.map((m) => (
                                <p key={m.baris}>
                                    Baris {m.baris}: {m.jabatan}
                                    {m.departemen ? ` (${m.departemen})` : ''}
                                </p>
                            ))}
                            <p className="mt-1 text-amber-700">
                                Tambahkan jabatannya di Struktur Organisasi, atau samakan nama jabatannya, lalu import lagi.
                            </p>
                        </div>
                    )}
                    {preview.perubahan.length > 0 && (
                        <Perubahan
                            r={{
                                id: 0,
                                status: 'diajukan',
                                langsung: false,
                                catatan: null,
                                catatan_verifikasi: null,
                                pembuat: null,
                                created_by: null,
                                penyetuju: null,
                                waktu: null,
                                diputuskan: null,
                                perubahan: preview.perubahan,
                            }}
                        />
                    )}
                </div>
            )}
        </Modal>
    );
}
