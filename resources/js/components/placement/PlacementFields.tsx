import { useId } from 'react';
import { BadgeCheck, Info } from 'lucide-react';
import { compose, SINGLE_HOLDER_MIN_LAYER, SUMBER_LABEL, type PenempatanOptions, type Placement } from '@/lib/placement';

const input =
    'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500 disabled:opacity-60';
const label = 'mb-1.5 block text-xs font-bold text-slate-500';

interface Props {
    value: Placement;
    onChange: (next: Placement) => void;
    options: PenempatanOptions;
    /** Server message for the placement, shown under the preview. */
    error?: string;
}

/**
 * Work Location (+ Sub Lokasi) → SubGroup → whatever that SubGroup names its positions
 * after (BOD, Divisi, Departemen, Section, or a Nama Jabatan with an optional Departemen /
 * Section). The employee fills the plan's matching position; without one they wait to be
 * placed in Struktur Organisasi.
 */
export function PlacementFields({ value, onChange, options: o, error }: Props) {
    const listId = useId();
    const set = (patch: Partial<Placement>) => onChange({ ...value, ...patch });
    const sub = o.job_levels.find((g) => String(g.id) === value.job_level_id);
    const composed = compose(value, o);

    const sectionsShown = value.department_id ? o.sections.filter((s) => String(s.department_id) === value.department_id) : o.sections;
    const departemenName = (id: number) => o.departments.find((d) => d.id === id)?.name ?? '';
    const divisiName = (id: number) => o.divisions.find((d) => d.id === id)?.name ?? '';
    const subLocations = (o.sub_locations ?? []).filter((s) => String(s.work_location_id) === value.work_location_id);

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label className={label}>Work Location *</label>
                    <select
                        value={value.work_location_id}
                        onChange={(e) => set({ work_location_id: e.target.value, sub_location_id: '' })}
                        className={input}
                    >
                        <option value="">Pilih...</option>
                        {o.work_locations.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                                {w.code ? ` (${w.code})` : ''}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className={label}>SubGroup *</label>
                    <select value={value.job_level_id} onChange={(e) => set({ job_level_id: e.target.value })} className={input}>
                        <option value="">Pilih...</option>
                        {o.job_levels.map((g) => (
                            <option key={g.id} value={g.id}>
                                {g.name} (layer {g.layer})
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            {subLocations.length > 0 && (
                <div>
                    <label className={label}>Sub Lokasi Kerja (bila ditugaskan di luar site induk)</label>
                    <select value={value.sub_location_id} onChange={(e) => set({ sub_location_id: e.target.value })} className={input}>
                        <option value="">— di site induknya —</option>
                        {subLocations.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {sub?.source === 'directorate' && (
                <div>
                    <label className={label}>BOD *</label>
                    <select value={value.directorate_id} onChange={(e) => set({ directorate_id: e.target.value })} className={input}>
                        <option value="">Pilih...</option>
                        {o.directorates.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {sub?.source === 'division' && (
                <div>
                    <label className={label}>Divisi *</label>
                    <select value={value.division_id} onChange={(e) => set({ division_id: e.target.value })} className={input}>
                        <option value="">Pilih...</option>
                        {o.divisions.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {(sub?.source === 'department' || sub?.source === 'section' || sub?.source === 'discipline') && (
                <div className={sub.source === 'department' ? '' : 'grid grid-cols-2 gap-3'}>
                    <div>
                        <label className={label}>
                            {sub.source === 'department'
                                ? 'Departemen *'
                                : sub.source === 'discipline'
                                  ? 'Departemen (opsional)'
                                  : 'Departemen (penyaring section)'}
                        </label>
                        <select
                            value={value.department_id}
                            onChange={(e) => {
                                const department = e.target.value;
                                const keepSection = o.sections.some(
                                    (s) => String(s.id) === value.section_id && String(s.department_id) === department,
                                );
                                set({ department_id: department, section_id: keepSection || !department ? value.section_id : '' });
                            }}
                            className={input}
                        >
                            <option value="">{sub.source === 'department' ? 'Pilih...' : 'Semua departemen'}</option>
                            {o.departments.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name} ({divisiName(d.division_id)})
                                </option>
                            ))}
                        </select>
                    </div>
                    {sub.source !== 'department' && (
                        <div>
                            <label className={label}>{sub.source === 'discipline' ? 'Section (opsional)' : 'Section *'}</label>
                            <select
                                value={value.section_id}
                                onChange={(e) => {
                                    const section = o.sections.find((s) => String(s.id) === e.target.value);
                                    set({ section_id: e.target.value, department_id: section ? String(section.department_id) : value.department_id });
                                }}
                                className={input}
                            >
                                <option value="">Pilih...</option>
                                {sectionsShown.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                        {value.department_id ? '' : ` (${departemenName(s.department_id)})`}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>
            )}

            {sub?.source === 'discipline' && (
                <div>
                    <label className={label}>Nama Jabatan *</label>
                    <input
                        list={listId}
                        value={value.discipline}
                        onChange={(e) => set({ discipline: e.target.value })}
                        placeholder="Ketik untuk mencari, mis. PRODUCTION"
                        className={input}
                    />
                    <datalist id={listId}>
                        {o.discipline.map((b) => (
                            <option key={b} value={b} />
                        ))}
                    </datalist>
                    {value.discipline && !o.discipline.some((b) => b.toLowerCase() === value.discipline.trim().toLowerCase()) && (
                        <p className="mt-1 text-[11px] text-rose-600">Tidak ada di master Nama Jabatan (Master Data → Nama Jabatan).</p>
                    )}
                </div>
            )}

            <div className="rounded-xl border border-brand-100 bg-brand-50/60 px-3 py-2.5">
                {composed.name && !composed.error ? (
                    <p className="flex items-center gap-2 text-sm">
                        <BadgeCheck size={16} className="shrink-0 text-brand-600" />
                        <span className="text-slate-500">Jabatan:</span>
                        <b className="text-brand-800">{composed.name}</b>
                    </p>
                ) : (
                    <p className="flex items-center gap-2 text-xs text-slate-500">
                        <Info size={14} className="shrink-0 text-brand-400" />
                        {composed.error}
                        {composed.name && <span className="text-slate-400">— akan menjadi {composed.name}</span>}
                    </p>
                )}
                {composed.name && !composed.error && (
                    <p className="mt-1 text-[11px] text-slate-500">
                        Langsung menjabat bila jabatan ini sudah ada di Plan Struktur Organisasi lokasi itu; bila belum, karyawan menunggu di daftar
                        Struktur Organisasi untuk ditempatkan.
                    </p>
                )}
                {sub && (
                    <p className="mt-1 text-[11px] text-slate-400">
                        {sub.name}: nama diambil dari {SUMBER_LABEL[sub.source]}
                        {sub.layer >= SINGLE_HOLDER_MIN_LAYER ? ' • 1 orang aktif per jabatan' : ''}
                    </p>
                )}
                {error && <p className="mt-1 text-xs font-bold text-rose-600">{error}</p>}
            </div>
        </div>
    );
}
