import { ArrowRight, Baby, Minus, Plus } from 'lucide-react';
import { ANAK_LABELS, showValue, type AnakData, type FieldInfo, type PengajuanRow } from '@/lib/changeRequest';

/** The changes of a Pengajuan Update Data: each field from its old to its new value, per section, and the children. */
export function ChangesTable({ row, fields }: { row: PengajuanRow; fields: FieldInfo }) {
    const bySection = new Map<string, string[]>();
    for (const key of Object.keys(row.changes)) {
        const section = fields[key]?.section ?? 'Lainnya';
        bySection.set(section, [...(bySection.get(section) ?? []), key]);
    }
    const cell = 'px-3 py-2 align-top';

    return (
        <div className="space-y-4">
            {bySection.size > 0 && (
                <div className="overflow-hidden rounded-2xl border border-slate-200">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className={cell}>Data</th>
                                <th className={cell}>Sebelumnya</th>
                                <th className="w-6" />
                                <th className={cell}>Diajukan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...bySection.entries()].map(([section, keys]) => (
                                <SectionRows key={section} section={section} keys={keys} row={row} fields={fields} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {row.children.length > 0 && (
                <div className="space-y-2">
                    <p className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
                        <Baby size={14} className="text-brand-500" />
                        Data Anak
                    </p>
                    {row.children.map((op, i) => (
                        <div
                            key={i}
                            className={`rounded-2xl border px-3 py-2.5 text-sm ${
                                op.action === 'add'
                                    ? 'border-emerald-200 bg-emerald-50/50'
                                    : op.action === 'delete'
                                      ? 'border-rose-200 bg-rose-50/50'
                                      : 'border-amber-200 bg-amber-50/40'
                            }`}
                        >
                            <p className="mb-1 flex items-center gap-1.5 text-[11px] font-black tracking-wide uppercase">
                                {op.action === 'add' ? (
                                    <span className="flex items-center gap-1 text-emerald-700">
                                        <Plus size={12} /> Tambah anak
                                    </span>
                                ) : op.action === 'delete' ? (
                                    <span className="flex items-center gap-1 text-rose-700">
                                        <Minus size={12} /> Hapus anak
                                    </span>
                                ) : (
                                    <span className="text-amber-700">Ubah data anak</span>
                                )}
                                <span className="font-bold text-slate-700 normal-case">{(op.after ?? op.before)?.name}</span>
                            </p>
                            <AnakDiff before={op.before} after={op.after} />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function SectionRows({ section, keys, row, fields }: { section: string; keys: string[]; row: PengajuanRow; fields: FieldInfo }) {
    return (
        <>
            <tr className="border-t border-slate-100 bg-slate-50/60">
                <td colSpan={4} className="px-3 py-1.5 text-[11px] font-black text-slate-500">
                    {section}
                </td>
            </tr>
            {keys.map((key) => {
                const f = fields[key];
                const c = row.changes[key];
                const now = row.now && key in row.now ? row.now[key] : undefined;
                return (
                    <tr key={key} className="border-t border-slate-100">
                        <td className="px-3 py-2 align-top text-xs font-bold text-slate-600">{f?.label ?? key}</td>
                        <td className="px-3 py-2 align-top text-slate-400 line-through decoration-slate-300">
                            {showValue(c.before, f?.type)}
                            {now !== undefined && (
                                <span className="mt-0.5 block text-[10px] text-amber-700 no-underline">
                                    Sekarang sudah: {showValue(now, f?.type)} (diubah setelah diajukan)
                                </span>
                            )}
                        </td>
                        <td className="py-2 align-top text-slate-300">
                            <ArrowRight size={14} />
                        </td>
                        <td className="px-3 py-2 align-top font-bold break-words text-slate-800">{showValue(c.after, f?.type)}</td>
                    </tr>
                );
            })}
        </>
    );
}

function AnakDiff({ before, after }: { before: AnakData | null; after: AnakData | null }) {
    const keys = Object.keys(ANAK_LABELS) as (keyof typeof ANAK_LABELS)[];
    const shown = keys.filter((k) => (before?.[k] ?? null) !== null || (after?.[k] ?? null) !== null);
    return (
        <dl className="grid grid-cols-[110px_1fr] gap-x-3 gap-y-0.5 text-xs">
            {shown.map((k) => {
                const b = before?.[k] ?? null;
                const a = after?.[k] ?? null;
                const changed = before && after && String(b ?? '') !== String(a ?? '');
                return (
                    <div key={k} className="contents">
                        <dt className="text-slate-500">{ANAK_LABELS[k]}</dt>
                        <dd className="text-slate-700">
                            {changed ? (
                                <>
                                    <span className="text-slate-400 line-through">{showValue(b)}</span> → <b>{showValue(a)}</b>
                                </>
                            ) : (
                                showValue(a ?? b)
                            )}
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}
