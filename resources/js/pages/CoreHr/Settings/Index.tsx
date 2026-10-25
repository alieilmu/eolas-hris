import { Fragment, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Building2, ClipboardList, Hash, ListChecks, LogOut, Plus, Trash2 } from 'lucide-react';
import { CrudTable, type Option } from '@/components/settings/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/settings/DirtyGuard';
import { PositionImport } from '@/components/settings/PositionImport';
import { WorkforcePlanningSettings, type MppSettingsData } from '@/components/settings/WorkforcePlanningSettings';
import { OptionList, type OptionCategory } from '@/components/settings/OptionList';
import { decodeNrp, describeFormat, formatLength, segmentPositions, SEGMENT_LABEL, type LokasiCode } from '@/lib/employee_number';
import { EmployeeNumberFormatEditor, type FormatProps } from '@/components/core_hr/EmployeeNumberFormatEditor';
import type { UnitOption } from '@/lib/orgTree';
import { PlacementDialog } from '@/components/placement/PlacementDialog';
import { emptyPlacement, type PenempatanOptions } from '@/lib/placement';

// --- props ------------------------------------------------------------------------------

interface Jenis {
    id: number;
    code: string;
    name: string;
    employee_count: number;
}

interface Lokasi extends LokasiCode {
    id: number;
    employee_count: number;
}

interface WorkLocation {
    id: number;
    name: string;
    code: string | null;
    type: string;
    employee_count: number;
    location_count: number;
    position_count: number;
}

interface JobLevel {
    id: number;
    name: string;
    /** 1–6: the higher, the more senior. */
    layer: number;
    /** Where the rest of its positions' names come from. */
    source: string;
    title_prefix: string | null;
    title_prefix_head_office: string | null;
    position_count: number;
}

interface Division {
    id: number;
    name: string;
    directorate_id: number;
    department_count: number;
    position_count: number;
}

interface Department {
    id: number;
    name: string;
    division_id: number;
    position_count: number;
    section_count: number;
}

interface SectionUnit {
    id: number;
    name: string;
    department_id: number;
    position_count: number;
}

interface SubLocationRow {
    id: number;
    name: string;
    work_location_id: number;
    position_count: number;
    employee_count: number;
}

interface Position {
    id: number;
    name: string;
    job_level: string | null;
    work_location: string | null;
    /** e.g. "Section Workshop · Maintenance" */
    unit_label: string;
    employee_count: number;
    children_count: number;
}

interface Props {
    employee_number_format: FormatProps;
    type: Jenis[];
    location: Lokasi[];
    work_locations: WorkLocation[];
    directorates: { id: number; name: string; division_count: number; position_count: number }[];
    job_levels: JobLevel[];
    source_options: Record<string, string>;
    divisions: Division[];
    departments: Department[];
    sections: SectionUnit[];
    sub_locations: SubLocationRow[];
    unit_options: UnitOption[];
    placement_options: PenempatanOptions;
    positions: Position[];
    options: OptionCategory[];
    workforce_planning: MppSettingsData;
}

const BASE = '/core-hr/settings';
const str = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));
const count = (n: number, noun: string) => (n > 0 ? `${n} ${noun}` : '-');

// --- NRP guide ------------------------------------------------------------------------------

const SEGMENT_COLOR = { text: 'bg-violet-500', category: 'bg-brand-600', point_of_hire: 'bg-emerald-600', year: 'bg-amber-500', sequence: 'bg-slate-500' } as const;

function EmployeeNumberGuide({ type, location, workLocations, format }: { type: Jenis[]; location: Lokasi[]; workLocations: WorkLocation[]; format: FormatProps }) {
    const [sample, setSample] = useState('');
    const decoded = sample.trim() ? decodeNrp(sample, { type, location }, format) : null;
    const place = (id: number | null) => workLocations.find((w) => w.id === id)?.name;
    const length = formatLength(format);

    return (
        <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
            <div>
                <h3 className="text-sm font-bold text-slate-800">Struktur NRP ({length === null ? 'bebas' : `${length} karakter`})</h3>
                <p className="mt-0.5 text-xs text-slate-500">
                    {format.mode === 'free' ? (
                        <>NRP bebas ({describeFormat(format)}); tidak ada data yang dibaca dari NRP. Ubah di Format Nomor Induk.</>
                    ) : (
                        <>
                            Jenis Karyawan, Tempat Penerimaan dan Status Asal terisi otomatis dari NRP. Status Asal = <b>Lokal</b> bila lokasi penerimaan sama
                            dengan lokasi kerja jabatannya, selain itu <b>Kiriman</b>. NRP karyawan baru yang tidak sesuai pola ditolak.
                        </>
                    )}
                </p>
            </div>

            {format.mode === 'pattern' && (
                <div className="flex flex-wrap gap-2">
                    {segmentPositions(format).map(({ segment, where }, i) => (
                        <div key={i} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-black text-white ${SEGMENT_COLOR[segment.type]}`}>{where}</span>
                            <span className="text-xs font-bold text-slate-600">{segment.type === 'text' ? `"${segment.value}"` : SEGMENT_LABEL[segment.type]}</span>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-start gap-3">
                <div>
                    <label className="mb-1 block text-[11px] font-bold text-slate-500">Coba baca NRP</label>
                    <input
                        value={sample}
                        onChange={(e) => setSample(e.target.value)}
                        placeholder={`mis. ${format.example}`}
                        maxLength={length ?? format.max_length}
                        className="w-48 rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-sm tracking-widest outline-none focus:ring-2 focus:ring-brand-500"
                    />
                </div>
                {decoded && (
                    <div
                        className={`flex-1 rounded-xl border px-4 py-2.5 text-xs ${decoded.error ? 'border-rose-100 bg-rose-50 text-rose-600' : 'border-emerald-100 bg-emerald-50 text-emerald-800'}`}
                    >
                        {decoded.error ??
                            (format.mode === 'free' ? (
                                'Format NRP sesuai.'
                            ) : (
                                <span>
                                    {[
                                        decoded.type && <b key="t">{decoded.type.name}</b>,
                                        decoded.location && (
                                            <span key="l">
                                                diterima di <b>{decoded.location.name}</b>
                                                {decoded.location.work_location_id ? ` (${place(decoded.location.work_location_id)})` : ' (belum dipetakan ke lokasi kerja)'}
                                            </span>
                                        ),
                                        decoded.year && (
                                            <span key="y">
                                                masuk <b>{decoded.year}</b>
                                            </span>
                                        ),
                                        decoded.sequence && (
                                            <span key="s">
                                                nomor urut <b>{decoded.sequence}</b>
                                            </span>
                                        ),
                                    ]
                                        .filter(Boolean)
                                        .reduce<ReactNode[]>((all, part, i) => (i ? [...all, ' • ', part] : [part]), [])}
                                </span>
                            ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/** "Karakter ke-2–3 NRP" for a segment type, or null when the format has none. */
function whereIn(format: FormatProps, type: 'category' | 'point_of_hire'): string | null {
    const found = segmentPositions(format).find((p) => p.segment.type === type);
    return found ? `Karakter ke-${found.where} NRP (${found.segment.length} karakter, huruf/angka)` : null;
}

// --- jabatan ----------------------------------------------------------------------------------

/** Positions are composed from a placement: listed here, added as vacancies, or removed when empty. */
function JabatanSection({ positions }: { positions: Position[] }) {
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState('');
    const [error, setError] = useState<string | null>(null);
    const q = query.trim().toLowerCase();
    const shown = q
        ? positions.filter((j) => [j.name, j.job_level, j.work_location, j.unit_label].some((v) => v?.toLowerCase().includes(q)))
        : positions;

    const remove = (j: Position) => {
        if (!confirm(`Hapus jabatan ${j.name}?`)) return;
        setError(null);
        router.delete(`${BASE}/position/${j.id}`, { preserveScroll: true, onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menghapus.') });
    };
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <div className="space-y-3">
            <PositionImport />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Cari jabatan, SubGroup, lokasi, unit..."
                    className="w-72 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                />
                <button
                    onClick={() => setAdding(true)}
                    className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700"
                >
                    <Plus size={16} />
                    Tambah Jabatan
                </button>
            </div>
            {error && <p className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</p>}
            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className={th}>Jabatan</th>
                            <th className={th}>Work Location</th>
                            <th className={th}>SubGroup</th>
                            <th className={th}>Melekat pada</th>
                            <th className={th}>Karyawan</th>
                            <th className={`${th} text-right`}>Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {shown.map((j) => {
                            const blocked = j.employee_count
                                ? `Masih ada ${j.employee_count} karyawan`
                                : j.children_count
                                  ? `Masih punya ${j.children_count} bawahan di bagan`
                                  : null;
                            return (
                                <tr key={j.id} className="hover:bg-slate-50/60">
                                    <td className="px-3 py-2 font-bold text-slate-700">{j.name}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{j.work_location ?? '-'}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{j.job_level ?? '-'}</td>
                                    <td className="px-3 py-2 text-slate-500">{j.unit_label}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-500">{count(j.employee_count, 'orang')}</td>
                                    <td className="px-3 py-2 text-right">
                                        <button
                                            onClick={() => remove(j)}
                                            disabled={!!blocked}
                                            title={blocked ?? 'Hapus'}
                                            className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-30"
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    </td>
                                </tr>
                            );
                        })}
                        {shown.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-sm text-slate-400">
                                    {positions.length ? 'Tidak ada jabatan yang cocok.' : 'Belum ada jabatan.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {adding && (
                <PlacementDialog
                    title="Tambah Jabatan"
                    subtitle="Posisi baru (boleh kosong dulu). Namanya tersusun dari penempatan."
                    initial={emptyPlacement()}
                    method="post"
                    url={`${BASE}/position`}
                    submitLabel="Tambahkan"
                    onClose={() => setAdding(false)}
                />
            )}
        </div>
    );
}

// --- page -------------------------------------------------------------------------------------

type Section = {
    id: string;
    label: string;
    hint?: string;
    render: () => ReactNode;
};

const SUB_GROUP_LAYERS = [6, 5, 4, 3, 2, 1];
const layerLabel = (n: number) => `${n}${n === 6 ? ' — tertinggi' : n === 1 ? ' — terendah' : ''}`;

export default function MasterDataIndex(props: Props) {
    // Unsaved edits hold back switching master type or leaving the page.
    return (
        <DirtyGuardProvider>
            <MasterDataPage {...props} />
        </DirtyGuardProvider>
    );
}

function MasterDataPage(props: Props) {
    const {
        type,
        location,
        work_locations: workLocations,
        directorates,
        job_levels: jobLevels,
        source_options: sourceOptions,
        divisions,
        departments,
        sections,
        sub_locations: subLocations,
        positions,
        options,
        workforce_planning,
    } = props;
    const guard = useGuard();

    const asOptions = <T,>(rows: T[], value: (r: T) => number, label: (r: T) => string): Option[] =>
        rows.map((r) => ({ value: value(r), label: label(r) }));
    const workLocationOptions = asOptions(
        workLocations,
        (w) => w.id,
        (w) => w.name,
    );
    const divisiOptions = asOptions(
        divisions,
        (d) => d.id,
        (d) => d.name,
    );
    const departemenOptions = asOptions(
        departments,
        (d) => d.id,
        (d) => `${d.name} (${divisions.find((v) => v.id === d.division_id)?.name ?? '-'})`,
    );

    const groups: { title: string; icon: typeof Hash; sections: Section[] }[] = [
        {
            title: 'Kode NRP',
            icon: Hash,
            sections: [
                {
                    id: 'employee-number-format',
                    label: 'Format Nomor Induk',
                    hint: 'Susunan NRP perusahaan Anda, mis. "TY" + jenis karyawan + tahun masuk + nomor urut, atau bebas. NRP yang sudah ada tidak diubah.',
                    render: () => <EmployeeNumberFormatEditor format={props.employee_number_format} />,
                },
                {
                    id: 'employee-categories',
                    label: 'Jenis Karyawan',
                    hint: `${whereIn(props.employee_number_format, 'category') ?? 'Tidak dipakai di format NRP sekarang'}. Kode yang sudah dipakai NRP karyawan tidak bisa diubah atau dihapus.`,
                    render: () => (
                        <CrudTable
                            title="Jenis Karyawan"
                            rows={type}
                            baseUrl={`${BASE}/employee-categories`}
                            fields={[
                                {
                                    key: 'code',
                                    label: 'Kode',
                                    placeholder: '1',
                                    width: 'w-24',
                                },
                                {
                                    key: 'name',
                                    label: 'Nama',
                                    placeholder: 'mis. Karyawan Normal',
                                },
                            ]}
                            toForm={(r) => ({ code: r.code, name: r.name })}
                            emptyForm={{ code: '', name: '' }}
                            extraColumns={[
                                {
                                    label: 'Dipakai',
                                    render: (r) => count(r.employee_count, 'karyawan'),
                                },
                            ]}
                            deleteBlockedReason={(r) => (r.employee_count ? `Dipakai NRP ${r.employee_count} karyawan` : null)}
                            describe={(r) => `jenis karyawan ${r.code} — ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'points-of-hire',
                    label: 'Lokasi Penerimaan',
                    hint: `${whereIn(props.employee_number_format, 'point_of_hire') ?? 'Tidak dipakai di format NRP sekarang'}. Petakan ke lokasi kerja agar Status Asal (Lokal/Kiriman) bisa dihitung.`,
                    render: () => (
                        <CrudTable
                            title="Lokasi Penerimaan"
                            rows={location}
                            baseUrl={`${BASE}/points-of-hire`}
                            fields={[
                                {
                                    key: 'code',
                                    label: 'Kode',
                                    placeholder: '15',
                                    width: 'w-24',
                                },
                                {
                                    key: 'name',
                                    label: 'Nama',
                                    placeholder: 'mis. Murung Raya',
                                },
                                {
                                    key: 'work_location_id',
                                    label: 'Lokasi Kerja',
                                    type: 'select',
                                    options: workLocationOptions,
                                    nullable: true,
                                },
                            ]}
                            toForm={(r) => ({
                                code: r.code,
                                name: r.name,
                                work_location_id: str(r.work_location_id),
                            })}
                            emptyForm={{
                                code: '',
                                name: '',
                                work_location_id: '',
                            }}
                            extraColumns={[
                                {
                                    label: 'Dipakai',
                                    render: (r) => count(r.employee_count, 'karyawan'),
                                },
                            ]}
                            deleteBlockedReason={(r) => (r.employee_count ? `Dipakai NRP ${r.employee_count} karyawan` : null)}
                            describe={(r) => `lokasi penerimaan ${r.code} — ${r.name}`}
                        />
                    ),
                },
            ],
        },
        {
            title: 'Organisasi',
            icon: Building2,
            sections: [
                {
                    id: 'directorate',
                    label: 'BOD',
                    hint: 'Puncak bagan Struktur Organisasi. Setiap divisi berada di bawah satu BOD.',
                    render: () => (
                        <CrudTable
                            title="BOD"
                            rows={directorates}
                            baseUrl={`${BASE}/directorate`}
                            fields={[
                                {
                                    key: 'name',
                                    label: 'Nama BOD',
                                    placeholder: 'mis. Direktur Utama',
                                },
                            ]}
                            toForm={(r) => ({ name: r.name })}
                            emptyForm={{ name: '' }}
                            extraColumns={[
                                {
                                    label: 'Divisi',
                                    render: (r) => count(r.division_count, 'divisi'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.division_count
                                    ? `Masih membawahi ${r.division_count} divisi`
                                    : r.position_count
                                      ? `Masih punya ${r.position_count} jabatan`
                                      : null
                            }
                            describe={(r) => `BOD ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'division',
                    label: 'Divisi',
                    render: () => (
                        <CrudTable
                            title="Divisi"
                            rows={divisions}
                            baseUrl={`${BASE}/division`}
                            fields={[
                                { key: 'name', label: 'Nama Divisi' },
                                {
                                    key: 'directorate_id',
                                    label: 'BOD',
                                    type: 'select',
                                    options: asOptions(
                                        directorates,
                                        (b) => b.id,
                                        (b) => b.name,
                                    ),
                                },
                            ]}
                            toForm={(r) => ({
                                name: r.name,
                                directorate_id: str(r.directorate_id),
                            })}
                            emptyForm={{
                                name: '',
                                directorate_id: str(directorates[0]?.id),
                            }}
                            extraColumns={[
                                {
                                    label: 'Departemen',
                                    render: (r) => count(r.department_count, 'departemen'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.department_count
                                    ? `Masih punya ${r.department_count} departemen`
                                    : r.position_count
                                      ? `Masih punya ${r.position_count} jabatan`
                                      : null
                            }
                            describe={(r) => `divisi ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'department',
                    label: 'Departemen',
                    render: () => (
                        <CrudTable
                            title="Departemen"
                            rows={departments}
                            baseUrl={`${BASE}/department`}
                            fields={[
                                { key: 'name', label: 'Nama Departemen' },
                                {
                                    key: 'division_id',
                                    label: 'Divisi',
                                    type: 'select',
                                    options: divisiOptions,
                                },
                            ]}
                            toForm={(r) => ({
                                name: r.name,
                                division_id: str(r.division_id),
                            })}
                            emptyForm={{ name: '', division_id: '' }}
                            extraColumns={[
                                { label: 'Section', render: (r) => count(r.section_count, 'section') },
                                {
                                    label: 'Jabatan',
                                    render: (r) => count(r.position_count, 'jabatan'),
                                },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.section_count
                                    ? `Masih punya ${r.section_count} section`
                                    : r.position_count
                                      ? `Masih punya ${r.position_count} jabatan`
                                      : null
                            }
                            describe={(r) => `departemen ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'section',
                    label: 'Section',
                    hint: 'Unit di bawah Departemen. Sebagian besar jabatan melekat pada section; kepala unit bisa melekat langsung pada Departemen, Divisi atau BOD.',
                    render: () => (
                        <CrudTable
                            title="Section"
                            rows={sections}
                            baseUrl={`${BASE}/section`}
                            fields={[
                                { key: 'name', label: 'Nama Section', placeholder: 'mis. Workshop' },
                                { key: 'department_id', label: 'Departemen', type: 'select', options: departemenOptions },
                            ]}
                            toForm={(r) => ({ name: r.name, department_id: str(r.department_id) })}
                            emptyForm={{ name: '', department_id: '' }}
                            extraColumns={[{ label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') }]}
                            deleteBlockedReason={(r) => (r.position_count ? `Masih punya ${r.position_count} jabatan` : null)}
                            describe={(r) => `section ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'position',
                    label: 'Jabatan',
                    hint: 'Nama jabatan tersusun otomatis dari penempatan: Work Location + SubGroup + unit atau Nama Jabatan. Jabatan disusun di Plan Struktur Organisasi; di sini bisa ditambahkan atau diimpor juga. Karyawan langsung menjabat bila jabatan yang cocok sudah ada. SubGroup layer 3 ke atas hanya untuk 1 karyawan aktif per jabatan.',
                    render: () => <JabatanSection positions={positions} />,
                },
                {
                    id: 'position_discipline',
                    label: 'Nama Jabatan',
                    hint: 'Daftar bidang untuk jabatan layer bawah, mis. PRODUCTION → GROUP LEADER PRODUCTION. Mengganti nama ikut memperbarui nama jabatan yang memakainya. Gunakan "Tempel banyak" untuk menyalin satu kolom dari Excel.',
                    render: () => <OptionList category={options.find((c) => c.category === 'position_discipline')!} usageNoun="position" />,
                },
                {
                    id: 'job-levels',
                    label: 'SubGroup',
                    hint: 'Layer 1–6 (makin tinggi makin senior) dan aturan nama jabatannya: dari mana nama diambil dan gelarnya. "Gelar di HO" dipakai bila Work Location bertipe Head Office (mis. OFFICER), selain itu Gelar (mis. GROUP LEADER). Mengubah aturan ikut mengganti nama jabatan yang sudah ada. Atasan tidak diatur di sini: disusun di Struktur Organisasi, dengan syarat layer atasan tidak di bawah bawahannya.',
                    render: () => (
                        <CrudTable
                            title="SubGroup"
                            rows={jobLevels}
                            baseUrl={`${BASE}/job-levels`}
                            fields={[
                                { key: 'name', label: 'Nama SubGroup', placeholder: 'mis. Section Head Site' },
                                {
                                    key: 'layer',
                                    label: 'Layer',
                                    type: 'select',
                                    options: SUB_GROUP_LAYERS.map((n) => ({ value: n, label: layerLabel(n) })),
                                    width: 'w-40',
                                },
                                {
                                    key: 'source',
                                    label: 'Nama diambil dari',
                                    type: 'select',
                                    options: Object.entries(sourceOptions).map(([value, label]) => ({ value, label })),
                                },
                                { key: 'title_prefix', label: 'Gelar', placeholder: 'mis. SECTION HEAD' },
                                { key: 'title_prefix_head_office', label: 'Gelar di HO', placeholder: 'mis. OFFICER' },
                            ]}
                            toForm={(r) => ({
                                name: r.name,
                                layer: str(r.layer),
                                source: r.source,
                                title_prefix: str(r.title_prefix),
                                title_prefix_head_office: str(r.title_prefix_head_office),
                            })}
                            emptyForm={{ name: '', layer: '', source: 'discipline', title_prefix: '', title_prefix_head_office: '' }}
                            extraColumns={[{ label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') }]}
                            deleteBlockedReason={(r) => (r.position_count ? `Masih dipakai ${r.position_count} jabatan` : null)}
                            describe={(r) => `SubGroup ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'work-locations',
                    label: 'Lokasi Kerja',
                    hint: 'Setiap jabatan milik satu Work Location, jadi karyawan mengikuti lokasi jabatannya. Tipe Head Office memakai "Gelar di HO" (mis. OFFICER). Kode dipakai di nama jabatan, mis. PROJECT MANAGER RMTU. Juga menentukan Status Asal.',
                    render: () => (
                        <CrudTable
                            title="Lokasi Kerja"
                            rows={workLocations}
                            baseUrl={`${BASE}/work-locations`}
                            fields={[
                                { key: 'name', label: 'Nama Lokasi' },
                                { key: 'code', label: 'Kode', placeholder: 'mis. RMTU', width: 'w-28' },
                                {
                                    key: 'type',
                                    label: 'Tipe',
                                    type: 'select',
                                    options: [
                                        { value: 'head_office', label: 'Head Office' },
                                        { value: 'site', label: 'Site' },
                                    ],
                                },
                            ]}
                            toForm={(r) => ({ name: r.name, code: str(r.code), type: r.type })}
                            emptyForm={{ name: '', code: '', type: 'site' }}
                            extraColumns={[
                                {
                                    label: 'Karyawan',
                                    render: (r) => count(r.employee_count, 'orang'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') },
                                {
                                    label: 'Kode Penerimaan',
                                    render: (r) =>
                                        location
                                            .filter((l) => l.work_location_id === r.id)
                                            .map((l) => l.code)
                                            .join(', ') || '-',
                                },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.employee_count
                                    ? `Masih dipakai ${r.employee_count} karyawan`
                                    : r.position_count
                                      ? `Masih punya ${r.position_count} jabatan`
                                      : r.location_count
                                        ? 'Masih dipetakan dari kode lokasi penerimaan'
                                        : null
                            }
                            describe={(r) => `lokasi kerja ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'sub-locations',
                    label: 'Sub Lokasi Kerja',
                    hint: 'Tempat di bawah satu Work Location (mis. pelabuhan, kantor perwakilan) untuk karyawan yang ditugaskan di luar site induknya. Di Plan Struktur Organisasi, Sub Lokasi menjadi kotak tersendiri di bawah Project Manager (atau atasan lain) dan bisa diisi departemen, section dan jabatan.',
                    render: () => (
                        <CrudTable
                            title="Sub Lokasi Kerja"
                            rows={subLocations}
                            baseUrl={`${BASE}/sub-locations`}
                            fields={[
                                { key: 'name', label: 'Nama Sub Lokasi', placeholder: 'mis. Jetty Batulicin' },
                                { key: 'work_location_id', label: 'Work Location induk', type: 'select', options: workLocationOptions },
                            ]}
                            toForm={(r) => ({ name: r.name, work_location_id: str(r.work_location_id) })}
                            emptyForm={{ name: '', work_location_id: '' }}
                            extraColumns={[
                                { label: 'Jabatan', render: (r) => count(r.position_count, 'jabatan') },
                                { label: 'Karyawan', render: (r) => count(r.employee_count, 'orang') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.position_count
                                    ? `Masih punya ${r.position_count} jabatan`
                                    : r.employee_count
                                      ? `Masih dipakai ${r.employee_count} karyawan`
                                      : null
                            }
                            describe={(r) => `sub lokasi ${r.name}`}
                        />
                    ),
                },
            ],
        },
        {
            title: 'Pilihan Profil',
            icon: ListChecks,
            sections: options
                .filter((c) => !['termination_reason', 'position_discipline', 'cash_advance_status'].includes(c.category))
                .map((c) => ({
                    id: c.category,
                    label: c.label,
                    hint: 'Mengganti nama sebuah pilihan ikut memperbarui data karyawan yang memakainya. Pilihan yang masih dipakai tidak bisa dihapus.',
                    render: () => <OptionList category={c} />,
                })),
        },
        {
            title: 'Man Power Planning',
            icon: ClipboardList,
            sections: [
                {
                    id: 'workforce_planning',
                    label: 'Periode & Biaya MPP',
                    hint: 'Plan Man Power Planning disimpan per periode; perubahan dari akun tanpa hak Persetujuan Revisi MPP menunggu persetujuan.',
                    render: () => <WorkforcePlanningSettings workforce_planning={workforce_planning} jobLevels={jobLevels} />,
                },
            ],
        },
        {
            title: 'Karyawan Keluar',
            icon: LogOut,
            sections: options
                .filter((c) => c.category === 'termination_reason')
                .map((c) => ({
                    id: c.category,
                    label: c.label,
                    hint: 'Pilihan alasan di dialog Karyawan Keluar.',
                    render: () => <OptionList category={c} />,
                })),
        },
    ];

    const allSections = groups.flatMap((g) => g.sections);
    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'employee-categories');
    const active = allSections.find((s) => s.id === activeId) ?? allSections[0];

    const select = (id: string) => {
        if (id === active.id) return;
        guard(() => {
            setActiveId(id);
            // Keep the tab in the address so a reload (and the redirect after a save) lands on it again.
            window.history.replaceState(window.history.state, '', `${BASE}?tab=${id}`);
        });
    };

    return (
        <>
            <Head title="Pengaturan Core HR" />

            <header className="flex h-16 shrink-0 items-center border-b-[3px] border-accent-400 bg-white px-8">
                <h1 className="text-xl font-bold tracking-tight text-slate-800">Pengaturan Core HR</h1>
            </header>

            <div className="flex min-h-0 flex-1 bg-slate-50">
                <nav className="scrollbar-hide w-64 shrink-0 space-y-5 overflow-y-auto border-r border-slate-200 bg-white p-4">
                    {groups.map(({ title, icon: Icon, sections }) => (
                        <div key={title}>
                            <p className="mb-1.5 flex items-center gap-2 px-2 text-[10px] font-bold tracking-widest text-slate-400 uppercase">
                                <Icon size={12} />
                                {title}
                            </p>
                            {sections.map((s) => (
                                <button
                                    key={s.id}
                                    onClick={() => select(s.id)}
                                    className={`block w-full rounded-lg px-3 py-1.5 text-left text-sm transition-colors ${
                                        s.id === active.id ? 'bg-brand-50 font-bold text-brand-600' : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {s.label}
                                </button>
                            ))}
                        </div>
                    ))}
                </nav>

                <div className="min-w-0 flex-1 overflow-y-auto p-6">
                    <div className="w-full space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <h2 className="text-lg font-bold text-slate-800">{active.label}</h2>
                            {active.hint && <p className="mt-0.5 text-xs text-slate-400">{active.hint}</p>}
                        </div>

                        {(active.id === 'employee-categories' || active.id === 'points-of-hire' || active.id === 'employee-number-format') && (
                            <EmployeeNumberGuide type={type} location={location} workLocations={workLocations} format={props.employee_number_format} />
                        )}

                        {/* Keyed so each master type gets fresh form state instead of inheriting the previous type's add/edit row. */}
                        <Fragment key={active.id}>{active.render()}</Fragment>
                    </div>
                </div>
            </div>
        </>
    );
}
