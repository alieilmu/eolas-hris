import { useForm } from '@inertiajs/react';
import { Loader2, Save } from 'lucide-react';
import { primaryButton, smallInput } from '@/components/time/ui';

export interface MppSettingsData {
    period: string;
    period_options: Record<string, string>;
    grade_by_job_level: Record<string, number>;
    /** SubGroup => golongan used now (the one set here, else from the Plafon Pengobatan rows). */
    grade_default: Record<string, number>;
    grades: { id: number; code: string }[];
}

/** Pengaturan Core HR → Man Power Planning: the plan period, and the golongan per SubGroup for its costs. */
export function WorkforcePlanningSettings({ workforce_planning, jobLevels }: { workforce_planning: MppSettingsData; jobLevels: { id: number; name: string; layer: number }[] }) {
    const form = useForm({
        period: workforce_planning.period,
        grade_by_job_level: Object.fromEntries(
            jobLevels.map((g) => [g.id, workforce_planning.grade_by_job_level[g.id] ? String(workforce_planning.grade_by_job_level[g.id]) : '']),
        ) as Record<string, string>,
    });
    const code = (id: number | undefined) => workforce_planning.grades.find((g) => g.id === id)?.code;

    return (
        <div className="space-y-6">
            <section>
                <p className="mb-2 text-sm font-bold text-slate-700">Periode plan</p>
                <div className="grid gap-2 sm:grid-cols-2">
                    {Object.entries(workforce_planning.period_options).map(([value, label]) => (
                        <label
                            key={value}
                            className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-3 ${form.data.period === value ? 'border-brand-300 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'}`}
                        >
                            <input
                                type="radio"
                                checked={form.data.period === value}
                                onChange={() => form.setData('period', value)}
                                className="mt-1 accent-brand-600"
                            />
                            <span>
                                <b className="block text-sm text-slate-800">{label}</b>
                                <span className="text-xs text-slate-500">
                                    {value === 'quarterly' ? 'Q1 Jan–Mar, Q2 Apr–Jun, Q3 Jul–Sep, Q4 Okt–Des.' : 'S1 Jan–Jun, S2 Jul–Des.'} Periode baru
                                    otomatis menyalin plan periode sebelumnya.
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </section>

            <section>
                <p className="text-sm font-bold text-slate-700">Golongan untuk estimasi biaya</p>
                <p className="mb-3 text-xs text-slate-500">
                    Biaya kebutuhan = kekurangan orang × upah step awal golongan ini (Skala Upah, Pengaturan Kompensasi). Kosong = mengikuti baris Plafon
                    Pengobatan SubGroup itu. Golongan per jabatan juga bisa diubah langsung di plan.
                </p>
                <div className="overflow-hidden rounded-2xl border border-slate-200">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-50 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th className="px-3 py-2">SubGroup</th>
                                <th className="px-3 py-2">Layer</th>
                                <th className="px-3 py-2">Golongan</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {jobLevels.map((g) => (
                                <tr key={g.id}>
                                    <td className="px-3 py-2 font-bold text-slate-700">{g.name}</td>
                                    <td className="px-3 py-2 text-slate-500">{g.layer}</td>
                                    <td className="px-3 py-2">
                                        <select
                                            value={form.data.grade_by_job_level[g.id] ?? ''}
                                            onChange={(e) =>
                                                form.setData('grade_by_job_level', { ...form.data.grade_by_job_level, [g.id]: e.target.value })
                                            }
                                            className={smallInput}
                                            aria-label={`Golongan ${g.name}`}
                                        >
                                            <option value="">
                                                Otomatis{code(workforce_planning.grade_default[g.id]) ? ` (${code(workforce_planning.grade_default[g.id])})` : ' (—)'}
                                            </option>
                                            {workforce_planning.grades.map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.code}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <div className="flex justify-end">
                <button
                    onClick={() => form.put('/core-hr/settings/workforce-planning', { preserveScroll: true })}
                    disabled={form.processing}
                    className={primaryButton}
                >
                    {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Simpan
                </button>
            </div>
        </div>
    );
}
