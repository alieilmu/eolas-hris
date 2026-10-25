import { Fragment, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Briefcase, Loader2, UserPlus } from 'lucide-react';
import { BULAN, rupiah, type Editable, type MppRow, type Totals } from '@/lib/workforce_planning';

interface Group {
    department: string;
    rows: MppRow[];
    total: Totals;
}

const th = 'border-b border-slate-200 bg-slate-100 px-2 py-2 text-[10px] font-bold tracking-wide whitespace-nowrap text-slate-500 uppercase';
const td = 'border-b border-slate-100 px-2 py-1.5 whitespace-nowrap';
const num =
    'w-14 rounded-md border border-slate-200 bg-white px-1.5 py-1 text-center text-sm tabular-nums outline-none focus:ring-2 focus:ring-brand-500';

/** The value of a field: the draft's when edited, else the row's. */
export function valueOf<K extends keyof Editable>(row: MppRow, draft: Editable | undefined, key: K): Editable[K] | MppRow[K & keyof MppRow] {
    return draft && key in draft ? draft[key] : row[key as keyof MppRow & K];
}

/** Gap with the draft applied: Actual − (Plan + Promosi + Rotasi + Demosi + Keluar + Pensiun + Sakit). */
export function gapOf(row: MppRow, draft?: Editable): number {
    const n = (k: keyof Editable) => {
        const v = valueOf(row, draft, k);
        if (k === 'retirements' && (v === null || v === undefined)) return row.retirements_auto;
        if (k === 'sick' && (v === null || v === undefined)) return row.sick_auto;
        return Number(v ?? 0);
    };
    return row.actual - (n('plan') + n('promotions') + n('rotations') + n('demotions') + n('termination') + n('retirements') + n('sick'));
}

function GapBadge({ gap }: { gap: number }) {
    return (
        <span
            className={`inline-flex min-w-9 justify-center rounded-md px-1.5 py-0.5 text-xs font-black tabular-nums ${
                gap < 0 ? 'bg-rose-50 text-rose-700' : gap > 0 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'
            }`}
            title={gap < 0 ? `Kurang ${-gap} orang` : gap > 0 ? `Lebih ${gap} orang` : 'Sesuai plan'}
        >
            {gap > 0 ? `+${gap}` : gap}
        </span>
    );
}

/** The MPP table per departemen, as the Excel sheet, editable in place. */
export function WorkforcePlanningTable({
    groups,
    total,
    editing,
    drafts,
    onDraft,
    planId,
    month,
    grades,
    sourceOptions,
    canRecruit,
}: {
    groups: Group[];
    total: Totals;
    editing: boolean;
    drafts: Record<number, Editable>;
    onDraft: (positionId: number, patch: Editable) => void;
    planId: number;
    month: { value: number; label: string }[];
    grades: { id: number; code: string }[];
    sourceOptions: Record<string, string>;
    canRecruit: boolean;
}) {
    const [opening, setOpening] = useState<number | null>(null);
    const changed = (row: MppRow, key: keyof Editable) => drafts[row.position_id] && key in drafts[row.position_id];
    const cellEdit = (row: MppRow, key: keyof Editable) => (changed(row, key) ? 'bg-amber-50' : '');

    const intInput = (row: MppRow, key: 'plan' | 'promotions' | 'rotations' | 'demotions' | 'termination' | 'retirements' | 'sick', auto?: number) => {
        const auto_ = key === 'retirements' || key === 'sick';
        const draft = drafts[row.position_id];
        // Pensiun / Sakit: empty = counted from the employees; a number replaces that count.
        const v = draft && key in draft ? draft[key] : auto_ ? (row[`${key}_manual` as 'retirements_manual'] ? row[key] : null) : row[key];
        return (
            <input
                type="number"
                min={0}
                value={v === null || v === undefined ? '' : String(v)}
                placeholder={auto !== undefined ? String(auto) : undefined}
                title={auto !== undefined ? `Kosong = otomatis (${auto} dari data karyawan)` : undefined}
                onChange={(e) => onDraft(row.position_id, { [key]: e.target.value === '' ? (auto_ ? null : 0) : Math.max(0, Number(e.target.value)) })}
                aria-label={`${key} ${row.position}`}
                className={`${num} ${key === 'plan' ? 'font-bold' : ''}`}
            />
        );
    };

    const openLowongan = (row: MppRow, requirements: number) => {
        if (
            !confirm(
                `Buat lowongan ${row.position} untuk ${requirements} orang? Form lowongan dibuat dalam keadaan ditutup, lalu dibuka di Pengaturan Form.`,
            )
        )
            return;
        setOpening(row.position_id);
        router.post(`/core-hr/workforce-planning/${planId}/careers/${row.position_id}`, { requirements }, { onFinish: () => setOpening(null) });
    };

    const showInt = (n: number) => (n ? n : <span className="text-slate-300">·</span>);

    // Totals follow what is typed, before it is saved.
    const totalsOf = (rows: MppRow[]): Totals => {
        const n = (row: MppRow, k: keyof Editable) => {
            const v = valueOf(row, drafts[row.position_id], k);
            if ((k === 'retirements' || k === 'sick') && (v === null || v === undefined)) return row[`${k}_auto` as 'retirements_auto'];
            return Number(v ?? 0);
        };
        const t = { plan: 0, actual: 0, promotions: 0, rotations: 0, demotions: 0, termination: 0, retirements: 0, sick: 0, gap: 0, short: 0, over: 0, cost: 0 };
        for (const row of rows) {
            const gap = gapOf(row, drafts[row.position_id]);
            t.plan += n(row, 'plan');
            t.actual += row.actual;
            t.promotions += n(row, 'promotions');
            t.rotations += n(row, 'rotations');
            t.demotions += n(row, 'demotions');
            t.termination += n(row, 'termination');
            t.retirements += n(row, 'retirements');
            t.sick += n(row, 'sick');
            t.gap += gap;
            t.short += Math.max(0, -gap);
            t.over += Math.max(0, gap);
            t.cost += row.wage ? Math.max(0, -gap) * row.wage : 0;
        }
        return t;
    };

    return (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table className="w-full border-separate border-spacing-0 text-left text-sm">
                <thead className="sticky top-0 z-10">
                    <tr>
                        <th className={`${th} sticky left-0 z-20`} rowSpan={2}>
                            Kat.
                        </th>
                        <th className={`${th} sticky left-12 z-20 min-w-64`} rowSpan={2}>
                            Jabatan
                        </th>
                        <th className={`${th} text-center`} rowSpan={2}>
                            Plan
                        </th>
                        <th className={`${th} text-center`} rowSpan={2}>
                            Actual
                        </th>
                        <th className={`${th} border-l border-slate-200 text-center`} colSpan={6}>
                            Diperkirakan akan
                        </th>
                        <th className={`${th} text-center`} rowSpan={2}>
                            Gap
                        </th>
                        <th className={`${th} border-l border-slate-200 text-center`} colSpan={3}>
                            Waktu & pemenuhan
                        </th>
                        <th className={`${th} border-l border-slate-200 text-center`} colSpan={2}>
                            Estimasi biaya
                        </th>
                        <th className={th} rowSpan={2}>
                            Lowongan
                        </th>
                        <th className={th} rowSpan={2}>
                            Keterangan
                        </th>
                    </tr>
                    <tr>
                        {['Promosi', 'Rotasi', 'Demosi', 'Keluar', 'Pensiun', 'Sakit'].map((h, i) => (
                            <th key={h} className={`${th} text-center ${i === 0 ? 'border-l border-slate-200' : ''}`}>
                                {h}
                            </th>
                        ))}
                        <th className={`${th} border-l border-slate-200`}>Pindah</th>
                        <th className={th}>Dipenuhi</th>
                        <th className={th}>Dari</th>
                        <th className={`${th} border-l border-slate-200`}>Gol</th>
                        <th className={`${th} text-right`}>/ Bulan</th>
                    </tr>
                </thead>
                <tbody>
                    {groups.map((g) => (
                        <Fragment key={g.department}>
                            <tr>
                                <td
                                    colSpan={20}
                                    className="sticky left-0 border-b border-slate-200 bg-brand-50/60 px-3 py-2 text-xs font-black tracking-wide text-brand-800 uppercase"
                                >
                                    {g.department}
                                </td>
                            </tr>
                            {g.rows.map((row) => {
                                const d = drafts[row.position_id];
                                const gap = gapOf(row, d);
                                const short = Math.max(0, -gap);
                                const source = valueOf(row, d, 'source');
                                return (
                                    <tr key={row.position_id} className="hover:bg-slate-50/70">
                                        <td className={`${td} sticky left-0 bg-white text-[10px] font-bold text-slate-400`}>{row.category}</td>
                                        <td className={`${td} sticky left-12 bg-white`}>
                                            <p className="max-w-72 truncate font-bold text-slate-700" title={row.position}>
                                                {row.position}
                                            </p>
                                            <p className="text-[10px] text-slate-400">
                                                {row.job_level}
                                                {row.sub_location ? ` · ${row.sub_location}` : ''}
                                            </p>
                                        </td>
                                        <td className={`${td} text-center font-bold ${cellEdit(row, 'plan')}`}>
                                            {editing ? intInput(row, 'plan') : row.plan}
                                        </td>
                                        <td className={`${td} text-center`}>
                                            <span
                                                className="cursor-help font-bold text-slate-700 tabular-nums"
                                                title={
                                                    row.employee.length
                                                        ? row.employee.map((k) => `${k.employee_number} ${k.name}`).join('\n')
                                                        : 'Belum ada karyawan'
                                                }
                                            >
                                                {row.actual}
                                            </span>
                                        </td>
                                        {(['promotions', 'rotations', 'demotions', 'termination'] as const).map((k, i) => (
                                            <td
                                                key={k}
                                                className={`${td} text-center tabular-nums ${i === 0 ? 'border-l border-slate-100' : ''} ${cellEdit(row, k)}`}
                                            >
                                                {editing ? intInput(row, k) : showInt(row[k])}
                                            </td>
                                        ))}
                                        <td
                                            className={`${td} text-center tabular-nums ${cellEdit(row, 'retirements')}`}
                                            title={row.retirements_list.map((p) => `${p.name}: pensiun ${p.date}`).join('\n') || undefined}
                                        >
                                            {editing ? intInput(row, 'retirements', row.retirements_auto) : showInt(row.retirements)}
                                        </td>
                                        <td
                                            className={`${td} text-center tabular-nums ${cellEdit(row, 'sick')}`}
                                            title={
                                                row.sick_list
                                                    .map((p) => `${p.name}: sejak ${p.start_date}${p.description ? ` (${p.description})` : ''}`)
                                                    .join('\n') || undefined
                                            }
                                        >
                                            {editing ? intInput(row, 'sick', row.sick_auto) : showInt(row.sick)}
                                        </td>
                                        <td className={`${td} text-center`}>
                                            <GapBadge gap={gap} />
                                        </td>
                                        {(['movement_month', 'fulfilment_month'] as const).map((k, i) => (
                                            <td key={k} className={`${td} ${i === 0 ? 'border-l border-slate-100' : ''} ${cellEdit(row, k)}`}>
                                                {editing ? (
                                                    <select
                                                        value={String(valueOf(row, d, k) ?? '')}
                                                        onChange={(e) =>
                                                            onDraft(row.position_id, { [k]: e.target.value ? Number(e.target.value) : null })
                                                        }
                                                        aria-label={`${k} ${row.position}`}
                                                        className="rounded-md border border-slate-200 bg-white px-1 py-1 text-xs"
                                                    >
                                                        <option value="">—</option>
                                                        {month.map((b) => (
                                                            <option key={b.value} value={b.value}>
                                                                {BULAN[b.value - 1]}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : row[k] ? (
                                                    <span className="text-xs text-slate-600">{BULAN[(row[k] as number) - 1]}</span>
                                                ) : (
                                                    <span className="text-slate-300">·</span>
                                                )}
                                            </td>
                                        ))}
                                        <td className={`${td} ${cellEdit(row, 'source')}`}>
                                            {editing ? (
                                                <select
                                                    value={source ?? ''}
                                                    onChange={(e) =>
                                                        onDraft(row.position_id, { source: (e.target.value || null) as MppRow['source'] })
                                                    }
                                                    aria-label={`Pemenuhan dari ${row.position}`}
                                                    className="rounded-md border border-slate-200 bg-white px-1 py-1 text-xs"
                                                >
                                                    <option value="">—</option>
                                                    {Object.entries(sourceOptions).map(([v, l]) => (
                                                        <option key={v} value={v}>
                                                            {l}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : source ? (
                                                <span
                                                    className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${source === 'internal' ? 'bg-sky-50 text-sky-700' : 'bg-violet-50 text-violet-700'}`}
                                                >
                                                    {source === 'internal' ? 'Internal' : 'Eksternal'}
                                                </span>
                                            ) : (
                                                <span className="text-slate-300">·</span>
                                            )}
                                        </td>
                                        <td className={`${td} border-l border-slate-100 ${cellEdit(row, 'grade_id')}`}>
                                            {editing ? (
                                                <select
                                                    value={String(
                                                        d && 'grade_id' in d ? (d.grade_id ?? '') : row.grade_manual ? row.grade_id : '',
                                                    )}
                                                    onChange={(e) =>
                                                        onDraft(row.position_id, { grade_id: e.target.value ? Number(e.target.value) : null })
                                                    }
                                                    aria-label={`Golongan ${row.position}`}
                                                    className="rounded-md border border-slate-200 bg-white px-1 py-1 text-xs"
                                                >
                                                    <option value="">
                                                        Otomatis{!row.grade_manual && row.grade ? ` (${row.grade})` : ''}
                                                    </option>
                                                    {grades.map((o) => (
                                                        <option key={o.id} value={o.id}>
                                                            {o.code}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <span className={`text-xs ${row.grade_manual ? 'font-bold text-slate-700' : 'text-slate-400'}`}>
                                                    {row.grade ?? '—'}
                                                </span>
                                            )}
                                        </td>
                                        <td className={`${td} text-right text-xs tabular-nums`}>
                                            {short && row.wage ? (
                                                <span className="font-bold text-slate-700" title={`${short} × Rp ${rupiah(row.wage)}`}>
                                                    {rupiah(short * row.wage)}
                                                </span>
                                            ) : (
                                                <span className="text-slate-300">·</span>
                                            )}
                                        </td>
                                        <td className={td}>
                                            {row.careers ? (
                                                <Link
                                                    href={`/recruitment/job-postings/${row.careers.form_id}`}
                                                    className={`inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-bold ${row.careers.is_open ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}
                                                    title={row.careers.title}
                                                >
                                                    <Briefcase size={12} /> {row.careers.is_open ? 'Dibuka' : 'Ditutup'} · {row.careers.candidate}{' '}
                                                    kandidat
                                                </Link>
                                            ) : short > 0 && canRecruit && !editing ? (
                                                <button
                                                    onClick={() => openLowongan(row, short)}
                                                    disabled={opening === row.position_id}
                                                    className="inline-flex items-center gap-1 rounded-md bg-brand-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                                >
                                                    {opening === row.position_id ? (
                                                        <Loader2 size={12} className="animate-spin" />
                                                    ) : (
                                                        <UserPlus size={12} />
                                                    )}{' '}
                                                    Buat lowongan
                                                </button>
                                            ) : (
                                                <span className="text-slate-300">·</span>
                                            )}
                                        </td>
                                        <td className={`${td} ${cellEdit(row, 'description')}`}>
                                            {editing ? (
                                                <input
                                                    value={String(valueOf(row, d, 'description') ?? '')}
                                                    onChange={(e) => onDraft(row.position_id, { description: e.target.value || null })}
                                                    maxLength={255}
                                                    aria-label={`Keterangan ${row.position}`}
                                                    className="w-48 rounded-md border border-slate-200 bg-white px-2 py-1 text-xs outline-none focus:ring-2 focus:ring-brand-500"
                                                />
                                            ) : (
                                                <span className="block max-w-56 truncate text-xs text-slate-500" title={row.description ?? undefined}>
                                                    {row.description}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            <SubtotalRow label="Sub Total" t={totalsOf(g.rows)} />
                        </Fragment>
                    ))}
                    <SubtotalRow label="JUMLAH" t={total && totalsOf(groups.flatMap((g) => g.rows))} strong />
                </tbody>
            </table>
            {groups.length === 0 && (
                <p className="px-6 py-10 text-center text-sm text-slate-400">
                    Lokasi ini belum punya jabatan. Susun dulu di Struktur Organisasi → Mode Organisasi.
                </p>
            )}
        </div>
    );
}

function SubtotalRow({ label, t, strong }: { label: string; t: Totals; strong?: boolean }) {
    const cls = `${td} text-center font-bold tabular-nums ${strong ? 'bg-slate-200/70' : 'bg-slate-50'}`;
    return (
        <tr>
            <td className={`${cls} sticky left-0`} />
            <td className={`${cls} sticky left-12 text-left text-xs tracking-wide uppercase`}>{label}</td>
            <td className={cls}>{t.plan}</td>
            <td className={cls}>{t.actual}</td>
            <td className={cls}>{t.promotions}</td>
            <td className={cls}>{t.rotations}</td>
            <td className={cls}>{t.demotions}</td>
            <td className={cls}>{t.termination}</td>
            <td className={cls}>{t.retirements}</td>
            <td className={cls}>{t.sick}</td>
            <td className={cls}>
                <GapBadge gap={t.gap} />
            </td>
            <td className={cls} colSpan={4} />
            <td className={`${cls} text-right text-xs`}>{t.cost ? rupiah(t.cost) : ''}</td>
            <td className={cls} colSpan={2} />
        </tr>
    );
}
