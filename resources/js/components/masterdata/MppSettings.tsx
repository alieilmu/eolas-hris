import { useForm } from '@inertiajs/react';
import { Loader2, Save } from 'lucide-react';
import { primaryButton, smallInput } from '@/components/attendance/ui';

export interface MppSettingsData {
    periode: string;
    periode_options: Record<string, string>;
    golongan_sub_group: Record<string, number>;
    /** SubGroup => golongan used now (the one set here, else from the Plafon Pengobatan rows). */
    golongan_default: Record<string, number>;
    golongans: { id: number; kode: string }[];
}

/** Master Data Personalia → Man Power Planning: the plan period, and the golongan per SubGroup for its costs. */
export function MppSettings({ mpp, subGroups }: { mpp: MppSettingsData; subGroups: { id: number; nama: string; layer: number }[] }) {
    const form = useForm({
        periode: mpp.periode,
        golongan_sub_group: Object.fromEntries(
            subGroups.map((g) => [g.id, mpp.golongan_sub_group[g.id] ? String(mpp.golongan_sub_group[g.id]) : '']),
        ) as Record<string, string>,
    });
    const kode = (id: number | undefined) => mpp.golongans.find((g) => g.id === id)?.kode;

    return (
        <div className="space-y-6">
            <section>
                <p className="mb-2 text-sm font-bold text-slate-700">Periode plan</p>
                <div className="grid gap-2 sm:grid-cols-2">
                    {Object.entries(mpp.periode_options).map(([value, label]) => (
                        <label
                            key={value}
                            className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-3 ${form.data.periode === value ? 'border-brand-300 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'}`}
                        >
                            <input
                                type="radio"
                                checked={form.data.periode === value}
                                onChange={() => form.setData('periode', value)}
                                className="mt-1 accent-brand-600"
                            />
                            <span>
                                <b className="block text-sm text-slate-800">{label}</b>
                                <span className="text-xs text-slate-500">
                                    {value === 'kuartal' ? 'Q1 Jan–Mar, Q2 Apr–Jun, Q3 Jul–Sep, Q4 Okt–Des.' : 'S1 Jan–Jun, S2 Jul–Des.'} Periode baru
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
                    Biaya kebutuhan = kekurangan orang × upah step awal golongan ini (Skala Upah, Master Data Comben). Kosong = mengikuti baris Plafon
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
                            {subGroups.map((g) => (
                                <tr key={g.id}>
                                    <td className="px-3 py-2 font-bold text-slate-700">{g.nama}</td>
                                    <td className="px-3 py-2 text-slate-500">{g.layer}</td>
                                    <td className="px-3 py-2">
                                        <select
                                            value={form.data.golongan_sub_group[g.id] ?? ''}
                                            onChange={(e) =>
                                                form.setData('golongan_sub_group', { ...form.data.golongan_sub_group, [g.id]: e.target.value })
                                            }
                                            className={smallInput}
                                            aria-label={`Golongan ${g.nama}`}
                                        >
                                            <option value="">
                                                Otomatis{kode(mpp.golongan_default[g.id]) ? ` (${kode(mpp.golongan_default[g.id])})` : ' (—)'}
                                            </option>
                                            {mpp.golongans.map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.kode}
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
                    onClick={() => form.put('/manpower/master-data/mpp', { preserveScroll: true })}
                    disabled={form.processing}
                    className={primaryButton}
                >
                    {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />} Simpan
                </button>
            </div>
        </div>
    );
}
