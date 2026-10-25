import { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Building2, Download, FileUp, History, Loader2, Pencil, Save, Send, X } from 'lucide-react';
import { AttendanceHeader, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { ImportDialog, MppDashboard, RevisiMenunggu, Riwayat } from '@/components/workforce_planning/WorkforcePlanningParts';
import { WorkforcePlanningTable } from '@/components/workforce_planning/WorkforcePlanningTable';
import { fmtDate } from '@/lib/format';
import { rupiahShort, type Editable, type MppRow, type Revisi, type Totals } from '@/lib/workforce_planning';
import { LOCATION_TYPE, label } from '@/lib/labels';

interface Base {
    locations: { id: number; name: string }[];
    all_sites: boolean;
    period: { code: string; label: string; type: string };
    periods: { value: string; label: string; now: boolean }[];
    policy: string;
    can_edit: boolean;
    can_approve: boolean;
    can_recruit: boolean;
}

interface SiteRow extends Totals {
    id: number;
    name: string;
    type: string;
    position: number;
    revision: number;
}

interface LokasiProps extends Base {
    mode: 'location';
    location: { id: number; name: string };
    plan: { id: number; label: string; start_date: string; end_date: string; editable: boolean; from: string | null };
    groups: { department: string; rows: MppRow[]; total: Totals }[];
    total: Totals;
    category: ({ category: string; label: string } & Totals)[];
    unplaced: number;
    period_months: { value: number; label: string }[];
    revisions_pending: Revisi[];
    history: Revisi[];
    grades: { id: number; code: string }[];
    source_options: Record<string, string>;
}

interface SemuaProps extends Base {
    mode: 'all';
    location: null;
    sites: SiteRow[];
}

type Props = LokasiProps | SemuaProps;

/** Man Power Planning: per work location and period, Plan against Actual, with revisions approved by HO. */
export default function WorkforcePlanning(props: Props) {
    const go = (q: { location?: string | number; period?: string }) =>
        router.get(
            '/core-hr/workforce-planning',
            { location: q.location ?? (props.mode === 'all' ? 'all' : props.location.id), period: q.period ?? props.period.code },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Man Power Planning" />
            <AttendanceHeader title="Man Power Planning" subtitle={`${props.period.label} · plan ${props.policy.toLowerCase()}`}>
                <select
                    value={props.mode === 'all' ? 'all' : props.location.id}
                    onChange={(e) => go({ location: e.target.value })}
                    className={`${smallInput} font-bold`}
                    aria-label="Lokasi kerja"
                >
                    {props.all_sites && <option value="all">MPP All Site</option>}
                    {props.locations.map((l) => (
                        <option key={l.id} value={l.id}>
                            MPP {l.name}
                        </option>
                    ))}
                </select>
                <select value={props.period.code} onChange={(e) => go({ period: e.target.value })} className={smallInput} aria-label="Periode">
                    {props.periods.map((p) => (
                        <option key={p.value} value={p.value}>
                            {p.label}
                            {p.now ? ' — berjalan' : ''}
                        </option>
                    ))}
                </select>
            </AttendanceHeader>
            {props.mode === 'all' ? <AllSite {...props} onOpen={(id) => go({ location: id })} /> : <Lokasi key={`${props.plan.id}`} {...props} />}
        </>
    );
}

function AllSite({ sites, period, onOpen }: SemuaProps & { onOpen: (id: number) => void }) {
    const t = sites.reduce(
        (a, s) => ({
            plan: a.plan + s.plan,
            actual: a.actual + s.actual,
            short: a.short + s.short,
            over: a.over + s.over,
            cost: a.cost + s.cost,
            revision: a.revision + s.revision,
        }),
        { plan: 0, actual: 0, short: 0, over: 0, cost: 0, revision: 0 },
    );
    const max = Math.max(1, ...sites.map((s) => Math.max(s.plan, s.actual)));
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4 sm:p-6">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5 [&>*]:min-w-0">
                {[
                    ['Plan semua lokasi', t.plan, `${sites.length} lokasi kerja`],
                    ['Actual', t.actual, 'karyawan aktif di jabatan'],
                    ['Kekurangan', t.short, 'perlu dipenuhi'],
                    ['Revisi menunggu', t.revision, 'perlu persetujuan HO'],
                    ['Estimasi biaya', rupiahShort(t.cost), `/ bulan · ${period.label.split(' (')[0]}`],
                ].map(([label, value, sub]) => (
                    <div key={label as string} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">{label}</p>
                        <p className="mt-1 truncate text-2xl font-black text-slate-800 tabular-nums">{value}</p>
                        <p className="truncate text-[11px] text-slate-400">{sub}</p>
                    </div>
                ))}
            </div>
            <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table className="w-full min-w-[820px] text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className={`${th} pl-5`}>Lokasi kerja</th>
                            <th className={`${th} text-right`}>Jabatan</th>
                            <th className={`${th} text-right`}>Plan</th>
                            <th className={`${th} text-right`}>Actual</th>
                            <th className={`${th} w-1/4`}>Actual / Plan</th>
                            <th className={`${th} text-right`}>Kurang</th>
                            <th className={`${th} text-right`}>Lebih</th>
                            <th className={`${th} text-right`}>Biaya / bulan</th>
                            <th className={`${th} pr-5 text-right`}>Revisi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 tabular-nums">
                        {sites.map((s) => (
                            <tr key={s.id} onClick={() => onOpen(s.id)} className="cursor-pointer hover:bg-slate-50">
                                <td className="py-3 pl-5">
                                    <p className="flex items-center gap-2 font-bold text-slate-800">
                                        <Building2 size={15} className="text-brand-500" /> {s.name}
                                    </p>
                                    <p className="pl-6 text-[11px] text-slate-400">{label(LOCATION_TYPE, s.type)}</p>
                                </td>
                                <td className="px-3 text-right text-slate-500">{s.position}</td>
                                <td className="px-3 text-right font-bold">{s.plan}</td>
                                <td className="px-3 text-right font-bold">{s.actual}</td>
                                <td className="px-3">
                                    <div className="relative h-4 rounded-r bg-slate-100" title={`Actual ${s.actual} / Plan ${s.plan}`}>
                                        <div className="h-full rounded-r bg-brand-500" style={{ width: `${(s.actual / max) * 100}%` }} />
                                        <div
                                            className="absolute -top-0.5 -bottom-0.5 w-0.5 bg-slate-800"
                                            style={{ left: `calc(${(s.plan / max) * 100}% - 1px)` }}
                                        />
                                    </div>
                                </td>
                                <td className={`px-3 text-right font-bold ${s.short ? 'text-rose-600' : 'text-slate-300'}`}>{s.short || '·'}</td>
                                <td className={`px-3 text-right font-bold ${s.over ? 'text-amber-600' : 'text-slate-300'}`}>{s.over || '·'}</td>
                                <td className="px-3 text-right text-xs">{s.cost ? rupiahShort(s.cost) : '·'}</td>
                                <td className="py-3 pr-5 text-right">
                                    {s.revision ? (
                                        <span className="rounded-full bg-amber-500 px-2 py-0.5 text-[11px] font-bold text-white">{s.revision}</span>
                                    ) : (
                                        <span className="text-slate-300">·</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <p className="text-xs text-slate-400">
                Klik lokasi untuk membuka MPP-nya. Plan tiap lokasi disalin otomatis dari periode sebelumnya saat periode baru dimulai.
            </p>
        </div>
    );
}

function Lokasi(props: LokasiProps) {
    const user = usePage().props.auth.user;
    const [editing, setEditing] = useState(false);
    const [drafts, setDrafts] = useState<Record<number, Editable>>({});
    const [notes, setCatatan] = useState('');
    const [saving, setSaving] = useState(false);
    const [importing, setImporting] = useState(false);
    const [history, setRiwayat] = useState(false);
    const pending = props.revisions_pending.length > 0;
    const count = Object.keys(drafts).length;

    // Leaving with unsaved changes asks first.
    useEffect(() => {
        if (!count) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [count]);

    const onDraft = (positionId: number, patch: Editable) =>
        setDrafts((d) => {
            const row = props.groups.flatMap((g) => g.rows).find((r) => r.position_id === positionId)!;
            const next = { ...(d[positionId] ?? {}), ...patch };
            // A value set back to what the plan has is no change.
            for (const [k, v] of Object.entries(next)) {
                const current =
                    k === 'retirements' || k === 'sick'
                        ? row[`${k}_manual` as 'retirements_manual']
                            ? row[k as 'retirements']
                            : null
                        : k === 'grade_id'
                          ? row.grade_manual
                              ? row.grade_id
                              : null
                          : row[k as keyof MppRow];
                if ((v ?? null) === (current ?? null)) delete next[k as keyof Editable];
            }
            const out = { ...d };
            if (Object.keys(next).length) out[positionId] = next;
            else delete out[positionId];
            return out;
        });

    const cancel = () => {
        if (count && !confirm('Buang perubahan yang belum disimpan?')) return;
        setDrafts({});
        setEditing(false);
    };
    const save = () => {
        setSaving(true);
        router.put(
            `/core-hr/workforce-planning/${props.plan.id}`,
            { items: Object.entries(drafts).map(([id, f]) => ({ position_id: Number(id), ...f })), notes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDrafts({});
                    setEditing(false);
                    setCatatan('');
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const canChange = props.can_edit && props.plan.editable;

    return (
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4 sm:p-6">
            <div className="flex flex-wrap items-center gap-2">
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-black text-slate-800">MPP {props.location.name}</h2>
                    <p className="text-xs text-slate-500">
                        {fmtDate(props.plan.start_date)} – {fmtDate(props.plan.end_date)}
                        {props.plan.from ? ` · disalin dari ${props.plan.from}` : ''} · Actual per {fmtDate(new Date().toISOString().slice(0, 10))}
                        {!props.plan.editable && ' · periode sudah lewat (hanya lihat)'}
                    </p>
                </div>
                <button onClick={() => setRiwayat(!history)} className={secondaryButton}>
                    <History size={16} /> Riwayat ({props.history.length})
                </button>
                <a href={`/core-hr/workforce-planning/${props.plan.id}/export`} className={secondaryButton}>
                    <Download size={16} /> Export Excel
                </a>
                {canChange && !editing && (
                    <>
                        <button onClick={() => setImporting(true)} disabled={pending && !props.can_approve} className={secondaryButton}>
                            <FileUp size={16} /> Import Excel
                        </button>
                        <button
                            onClick={() => setEditing(true)}
                            disabled={pending && !props.can_approve}
                            className={primaryButton}
                            title={pending && !props.can_approve ? 'Masih ada revisi menunggu' : undefined}
                        >
                            <Pencil size={16} /> Ubah plan
                        </button>
                    </>
                )}
            </div>

            {props.revisions_pending.map((r) => (
                <RevisiMenunggu key={r.id} r={r} canApprove={props.can_approve} userId={user?.id ?? 0} />
            ))}

            {history && (
                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 className="mb-1 text-sm font-bold text-slate-800">Riwayat perubahan {props.plan.label}</h3>
                    <Riwayat items={props.history} />
                </section>
            )}

            {!editing && (
                <MppDashboard
                    groups={props.groups}
                    total={props.total}
                    category={props.category}
                    not_yet={props.unplaced}
                    monthCount={props.period_months.length}
                />
            )}

            {editing && (
                <p className="rounded-xl border border-brand-100 bg-brand-50 px-4 py-2 text-xs text-brand-800">
                    Ubah angka langsung di tabel; sel yang berubah ditandai kuning. Kolom Pensiun & Sakit dikosongkan = dihitung otomatis dari data
                    karyawan.
                    {props.can_approve
                        ? ' Perubahan Anda langsung berlaku.'
                        : ' Perubahan dikirim sebagai revisi dan baru berlaku setelah disetujui.'}
                </p>
            )}

            <WorkforcePlanningTable
                groups={props.groups}
                total={props.total}
                editing={editing}
                drafts={drafts}
                onDraft={onDraft}
                planId={props.plan.id}
                month={props.period_months}
                grades={props.grades}
                sourceOptions={props.source_options}
                canRecruit={props.can_recruit && props.plan.editable}
            />

            {editing && (
                <div className="sticky bottom-0 z-20 -mx-4 flex flex-wrap items-center gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 shadow-[0_-8px_24px_-12px_rgba(15,23,42,0.25)] backdrop-blur sm:-mx-6 sm:px-6">
                    <span className="text-sm font-bold text-slate-700">{count} jabatan diubah</span>
                    <input
                        value={notes}
                        onChange={(e) => setCatatan(e.target.value)}
                        maxLength={255}
                        placeholder={props.can_approve ? 'Catatan perubahan (opsional)' : 'Alasan revisi untuk penyetuju'}
                        className="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                    />
                    <button
                        onClick={cancel}
                        className="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100"
                    >
                        <X size={16} /> Batal
                    </button>
                    <button onClick={save} disabled={!count || saving} className={primaryButton}>
                        {saving ? <Loader2 size={16} className="animate-spin" /> : props.can_approve ? <Save size={16} /> : <Send size={16} />}
                        {props.can_approve ? 'Simpan' : 'Ajukan revisi'}
                    </button>
                </div>
            )}

            {importing && <ImportDialog planId={props.plan.id} canApprove={props.can_approve} onClose={() => setImporting(false)} />}
        </div>
    );
}
