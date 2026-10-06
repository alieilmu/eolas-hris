import { Fragment, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Building2, Hash, ListChecks, LogOut, Plus, Trash2 } from 'lucide-react';
import { CrudTable, type Option } from '@/components/masterdata/CrudTable';
import { DirtyGuardProvider, useGuard } from '@/components/masterdata/DirtyGuard';
import { JabatanImport } from '@/components/masterdata/JabatanImport';
import { OptionList, type OptionCategory } from '@/components/masterdata/OptionList';
import { decodeNrp, type LokasiCode } from '@/lib/nrp';
import type { UnitOption } from '@/lib/orgTree';
import { PenempatanDialog } from '@/components/penempatan/PenempatanDialog';
import { emptyPlacement, type PenempatanOptions } from '@/lib/penempatan';

// --- props ------------------------------------------------------------------------------

interface Jenis {
    id: number;
    kode: string;
    nama: string;
    karyawan_count: number;
}

interface Lokasi extends LokasiCode {
    id: number;
    karyawan_count: number;
}

interface WorkLocation {
    id: number;
    name: string;
    kode: string | null;
    type: string;
    karyawan_count: number;
    lokasi_count: number;
    jabatan_count: number;
}

interface SubGroup {
    id: number;
    nama: string;
    /** 1–6: the higher, the more senior. */
    layer: number;
    /** Where the rest of its positions' names come from. */
    sumber: string;
    gelar: string | null;
    gelar_ho: string | null;
    jabatan_count: number;
}

interface Divisi {
    id: number;
    name: string;
    bod_id: number;
    departemen_count: number;
    jabatan_count: number;
}

interface Departemen {
    id: number;
    name: string;
    divisi_id: number;
    jabatan_count: number;
    section_count: number;
}

interface SectionUnit {
    id: number;
    name: string;
    departemen_id: number;
    jabatan_count: number;
}

interface SubLocationRow {
    id: number;
    name: string;
    work_location_id: number;
    jabatan_count: number;
    karyawan_count: number;
}

interface Jabatan {
    id: number;
    name: string;
    sub_group: string | null;
    work_location: string | null;
    /** e.g. "Section Workshop · Maintenance" */
    unit_label: string;
    karyawan_count: number;
    children_count: number;
}

interface Props {
    jenis: Jenis[];
    lokasi: Lokasi[];
    work_locations: WorkLocation[];
    bods: { id: number; name: string; divisi_count: number; jabatan_count: number }[];
    sub_groups: SubGroup[];
    sumber_options: Record<string, string>;
    divisis: Divisi[];
    departemens: Departemen[];
    sections: SectionUnit[];
    sub_locations: SubLocationRow[];
    unit_options: UnitOption[];
    penempatan_options: PenempatanOptions;
    jabatans: Jabatan[];
    options: OptionCategory[];
}

const BASE = '/manpower/master-data';
const str = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));
const count = (n: number, noun: string) => (n > 0 ? `${n} ${noun}` : '-');

// --- NRP guide ------------------------------------------------------------------------------

const SEGMENTS = [
    { digits: '1', label: 'Jenis karyawan', color: 'bg-brand-600' },
    { digits: '2–3', label: 'Lokasi penerimaan', color: 'bg-emerald-600' },
    { digits: '4–5', label: 'Tahun masuk', color: 'bg-amber-500' },
    { digits: '6–8', label: 'Nomor urut', color: 'bg-slate-500' },
];

function NrpGuide({ jenis, lokasi, workLocations }: { jenis: Jenis[]; lokasi: Lokasi[]; workLocations: WorkLocation[] }) {
    const [sample, setSample] = useState('');
    const decoded = sample.trim() ? decodeNrp(sample, { jenis, lokasi }) : null;
    const place = (id: number | null) => workLocations.find((w) => w.id === id)?.name;

    return (
        <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
            <div>
                <h3 className="text-sm font-bold text-slate-800">Struktur NRP (8 digit)</h3>
                <p className="mt-0.5 text-xs text-slate-500">
                    Jenis Karyawan, Tempat Penerimaan dan Status Asal terisi otomatis dari NRP. Status Asal = <b>Lokal</b> bila lokasi penerimaan sama
                    dengan lokasi kerja jabatannya, selain itu <b>Kiriman</b>. NRP karyawan baru yang tidak sesuai pola ditolak.
                </p>
            </div>

            <div className="flex flex-wrap gap-2">
                {SEGMENTS.map((s) => (
                    <div key={s.digits} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                        <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-black text-white ${s.color}`}>{s.digits}</span>
                        <span className="text-xs font-bold text-slate-600">{s.label}</span>
                    </div>
                ))}
            </div>

            <div className="flex flex-wrap items-start gap-3">
                <div>
                    <label className="mb-1 block text-[11px] font-bold text-slate-500">Coba baca NRP</label>
                    <input
                        value={sample}
                        onChange={(e) => setSample(e.target.value)}
                        placeholder="mis. 11524101"
                        maxLength={8}
                        className="w-40 rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-sm tracking-widest outline-none focus:ring-2 focus:ring-brand-500"
                    />
                </div>
                {decoded && (
                    <div
                        className={`flex-1 rounded-xl border px-4 py-2.5 text-xs ${decoded.error ? 'border-rose-100 bg-rose-50 text-rose-600' : 'border-emerald-100 bg-emerald-50 text-emerald-800'}`}
                    >
                        {decoded.error ?? (
                            <span>
                                <b>{decoded.jenis?.nama}</b> • diterima di <b>{decoded.lokasi?.nama}</b>
                                {decoded.lokasi?.work_location_id
                                    ? ` (${place(decoded.lokasi.work_location_id)})`
                                    : ' (belum dipetakan ke lokasi kerja)'}{' '}
                                • masuk <b>{decoded.tahun}</b> • nomor urut <b>{decoded.urut}</b>
                            </span>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

// --- jabatan ----------------------------------------------------------------------------------

/** Positions are composed from a placement: listed here, added as vacancies, or removed when empty. */
function JabatanSection({ jabatans }: { jabatans: Jabatan[] }) {
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState('');
    const [error, setError] = useState<string | null>(null);
    const q = query.trim().toLowerCase();
    const shown = q
        ? jabatans.filter((j) => [j.name, j.sub_group, j.work_location, j.unit_label].some((v) => v?.toLowerCase().includes(q)))
        : jabatans;

    const remove = (j: Jabatan) => {
        if (!confirm(`Hapus jabatan ${j.name}?`)) return;
        setError(null);
        router.delete(`${BASE}/jabatan/${j.id}`, { preserveScroll: true, onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menghapus.') });
    };
    const th = 'px-3 py-2.5 text-[10px] font-bold tracking-wide text-slate-500 uppercase';

    return (
        <div className="space-y-3">
            <JabatanImport />
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
                            const blocked = j.karyawan_count
                                ? `Masih ada ${j.karyawan_count} karyawan`
                                : j.children_count
                                  ? `Masih punya ${j.children_count} bawahan di bagan`
                                  : null;
                            return (
                                <tr key={j.id} className="hover:bg-slate-50/60">
                                    <td className="px-3 py-2 font-bold text-slate-700">{j.name}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{j.work_location ?? '-'}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-600">{j.sub_group ?? '-'}</td>
                                    <td className="px-3 py-2 text-slate-500">{j.unit_label}</td>
                                    <td className="px-3 py-2 whitespace-nowrap text-slate-500">{count(j.karyawan_count, 'orang')}</td>
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
                                    {jabatans.length ? 'Tidak ada jabatan yang cocok.' : 'Belum ada jabatan.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {adding && (
                <PenempatanDialog
                    title="Tambah Jabatan"
                    subtitle="Posisi baru (boleh kosong dulu). Namanya tersusun dari penempatan."
                    initial={emptyPlacement()}
                    method="post"
                    url={`${BASE}/jabatan`}
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
        jenis,
        lokasi,
        work_locations: workLocations,
        bods,
        sub_groups: subGroups,
        sumber_options: sumberOptions,
        divisis,
        departemens,
        sections,
        sub_locations: subLocations,
        jabatans,
        options,
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
        divisis,
        (d) => d.id,
        (d) => d.name,
    );
    const departemenOptions = asOptions(
        departemens,
        (d) => d.id,
        (d) => `${d.name} (${divisis.find((v) => v.id === d.divisi_id)?.name ?? '-'})`,
    );

    const groups: { title: string; icon: typeof Hash; sections: Section[] }[] = [
        {
            title: 'Kode NRP',
            icon: Hash,
            sections: [
                {
                    id: 'jenis-karyawan',
                    label: 'Jenis Karyawan',
                    hint: 'Digit ke-1 NRP. Kode yang sudah dipakai NRP karyawan tidak bisa diubah atau dihapus.',
                    render: () => (
                        <CrudTable
                            title="Jenis Karyawan"
                            rows={jenis}
                            baseUrl={`${BASE}/jenis-karyawan`}
                            fields={[
                                {
                                    key: 'kode',
                                    label: 'Kode',
                                    placeholder: '1',
                                    width: 'w-24',
                                },
                                {
                                    key: 'nama',
                                    label: 'Nama',
                                    placeholder: 'mis. Karyawan Normal',
                                },
                            ]}
                            toForm={(r) => ({ kode: r.kode, nama: r.nama })}
                            emptyForm={{ kode: '', nama: '' }}
                            extraColumns={[
                                {
                                    label: 'Dipakai',
                                    render: (r) => count(r.karyawan_count, 'karyawan'),
                                },
                            ]}
                            deleteBlockedReason={(r) => (r.karyawan_count ? `Dipakai NRP ${r.karyawan_count} karyawan` : null)}
                            describe={(r) => `jenis karyawan ${r.kode} — ${r.nama}`}
                        />
                    ),
                },
                {
                    id: 'lokasi-penerimaan',
                    label: 'Lokasi Penerimaan',
                    hint: 'Digit ke-2–3 NRP. Petakan ke lokasi kerja agar Status Asal (Lokal/Kiriman) bisa dihitung.',
                    render: () => (
                        <CrudTable
                            title="Lokasi Penerimaan"
                            rows={lokasi}
                            baseUrl={`${BASE}/lokasi-penerimaan`}
                            fields={[
                                {
                                    key: 'kode',
                                    label: 'Kode',
                                    placeholder: '15',
                                    width: 'w-24',
                                },
                                {
                                    key: 'nama',
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
                                kode: r.kode,
                                nama: r.nama,
                                work_location_id: str(r.work_location_id),
                            })}
                            emptyForm={{
                                kode: '',
                                nama: '',
                                work_location_id: '',
                            }}
                            extraColumns={[
                                {
                                    label: 'Dipakai',
                                    render: (r) => count(r.karyawan_count, 'karyawan'),
                                },
                            ]}
                            deleteBlockedReason={(r) => (r.karyawan_count ? `Dipakai NRP ${r.karyawan_count} karyawan` : null)}
                            describe={(r) => `lokasi penerimaan ${r.kode} — ${r.nama}`}
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
                    id: 'bod',
                    label: 'BOD',
                    hint: 'Puncak bagan Struktur Organisasi. Setiap divisi berada di bawah satu BOD.',
                    render: () => (
                        <CrudTable
                            title="BOD"
                            rows={bods}
                            baseUrl={`${BASE}/bod`}
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
                                    render: (r) => count(r.divisi_count, 'divisi'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.divisi_count
                                    ? `Masih membawahi ${r.divisi_count} divisi`
                                    : r.jabatan_count
                                      ? `Masih punya ${r.jabatan_count} jabatan`
                                      : null
                            }
                            describe={(r) => `BOD ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'divisi',
                    label: 'Divisi',
                    render: () => (
                        <CrudTable
                            title="Divisi"
                            rows={divisis}
                            baseUrl={`${BASE}/divisi`}
                            fields={[
                                { key: 'name', label: 'Nama Divisi' },
                                {
                                    key: 'bod_id',
                                    label: 'BOD',
                                    type: 'select',
                                    options: asOptions(
                                        bods,
                                        (b) => b.id,
                                        (b) => b.name,
                                    ),
                                },
                            ]}
                            toForm={(r) => ({
                                name: r.name,
                                bod_id: str(r.bod_id),
                            })}
                            emptyForm={{
                                name: '',
                                bod_id: str(bods[0]?.id),
                            }}
                            extraColumns={[
                                {
                                    label: 'Departemen',
                                    render: (r) => count(r.departemen_count, 'departemen'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.departemen_count
                                    ? `Masih punya ${r.departemen_count} departemen`
                                    : r.jabatan_count
                                      ? `Masih punya ${r.jabatan_count} jabatan`
                                      : null
                            }
                            describe={(r) => `divisi ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'departemen',
                    label: 'Departemen',
                    render: () => (
                        <CrudTable
                            title="Departemen"
                            rows={departemens}
                            baseUrl={`${BASE}/departemen`}
                            fields={[
                                { key: 'name', label: 'Nama Departemen' },
                                {
                                    key: 'divisi_id',
                                    label: 'Divisi',
                                    type: 'select',
                                    options: divisiOptions,
                                },
                            ]}
                            toForm={(r) => ({
                                name: r.name,
                                divisi_id: str(r.divisi_id),
                            })}
                            emptyForm={{ name: '', divisi_id: '' }}
                            extraColumns={[
                                { label: 'Section', render: (r) => count(r.section_count, 'section') },
                                {
                                    label: 'Jabatan',
                                    render: (r) => count(r.jabatan_count, 'jabatan'),
                                },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.section_count
                                    ? `Masih punya ${r.section_count} section`
                                    : r.jabatan_count
                                      ? `Masih punya ${r.jabatan_count} jabatan`
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
                                { key: 'departemen_id', label: 'Departemen', type: 'select', options: departemenOptions },
                            ]}
                            toForm={(r) => ({ name: r.name, departemen_id: str(r.departemen_id) })}
                            emptyForm={{ name: '', departemen_id: '' }}
                            extraColumns={[{ label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') }]}
                            deleteBlockedReason={(r) => (r.jabatan_count ? `Masih punya ${r.jabatan_count} jabatan` : null)}
                            describe={(r) => `section ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'jabatan',
                    label: 'Jabatan',
                    hint: 'Nama jabatan tersusun otomatis dari penempatan: Work Location + SubGroup + unit atau Nama Jabatan. Jabatan disusun di Plan Struktur Organisasi; di sini bisa ditambahkan atau diimpor juga. Karyawan langsung menjabat bila jabatan yang cocok sudah ada. SubGroup layer 3 ke atas hanya untuk 1 karyawan aktif per jabatan.',
                    render: () => <JabatanSection jabatans={jabatans} />,
                },
                {
                    id: 'bidang_jabatan',
                    label: 'Nama Jabatan',
                    hint: 'Daftar bidang untuk jabatan layer bawah, mis. PRODUCTION → GROUP LEADER PRODUCTION. Mengganti nama ikut memperbarui nama jabatan yang memakainya. Gunakan "Tempel banyak" untuk menyalin satu kolom dari Excel.',
                    render: () => <OptionList category={options.find((c) => c.kategori === 'bidang_jabatan')!} usageNoun="jabatan" />,
                },
                {
                    id: 'sub-group',
                    label: 'SubGroup',
                    hint: 'Layer 1–6 (makin tinggi makin senior) dan aturan nama jabatannya: dari mana nama diambil dan gelarnya. "Gelar di HO" dipakai bila Work Location bertipe Head Office (mis. OFFICER), selain itu Gelar (mis. GROUP LEADER). Mengubah aturan ikut mengganti nama jabatan yang sudah ada. Atasan tidak diatur di sini: disusun di Struktur Organisasi, dengan syarat layer atasan tidak di bawah bawahannya.',
                    render: () => (
                        <CrudTable
                            title="SubGroup"
                            rows={subGroups}
                            baseUrl={`${BASE}/sub-group`}
                            fields={[
                                { key: 'nama', label: 'Nama SubGroup', placeholder: 'mis. Section Head Site' },
                                {
                                    key: 'layer',
                                    label: 'Layer',
                                    type: 'select',
                                    options: SUB_GROUP_LAYERS.map((n) => ({ value: n, label: layerLabel(n) })),
                                    width: 'w-40',
                                },
                                {
                                    key: 'sumber',
                                    label: 'Nama diambil dari',
                                    type: 'select',
                                    options: Object.entries(sumberOptions).map(([value, label]) => ({ value, label })),
                                },
                                { key: 'gelar', label: 'Gelar', placeholder: 'mis. SECTION HEAD' },
                                { key: 'gelar_ho', label: 'Gelar di HO', placeholder: 'mis. OFFICER' },
                            ]}
                            toForm={(r) => ({
                                nama: r.nama,
                                layer: str(r.layer),
                                sumber: r.sumber,
                                gelar: str(r.gelar),
                                gelar_ho: str(r.gelar_ho),
                            })}
                            emptyForm={{ nama: '', layer: '', sumber: 'bidang', gelar: '', gelar_ho: '' }}
                            extraColumns={[{ label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') }]}
                            deleteBlockedReason={(r) => (r.jabatan_count ? `Masih dipakai ${r.jabatan_count} jabatan` : null)}
                            describe={(r) => `SubGroup ${r.nama}`}
                        />
                    ),
                },
                {
                    id: 'work-location',
                    label: 'Lokasi Kerja',
                    hint: 'Setiap jabatan milik satu Work Location, jadi karyawan mengikuti lokasi jabatannya. Tipe Head Office memakai "Gelar di HO" (mis. OFFICER). Kode dipakai di nama jabatan, mis. PROJECT MANAGER RMTU. Juga menentukan Status Asal.',
                    render: () => (
                        <CrudTable
                            title="Lokasi Kerja"
                            rows={workLocations}
                            baseUrl={`${BASE}/work-location`}
                            fields={[
                                { key: 'name', label: 'Nama Lokasi' },
                                { key: 'kode', label: 'Kode', placeholder: 'mis. RMTU', width: 'w-28' },
                                {
                                    key: 'type',
                                    label: 'Tipe',
                                    type: 'select',
                                    options: ['Head Office', 'Site'].map((t) => ({ value: t, label: t })),
                                },
                            ]}
                            toForm={(r) => ({ name: r.name, kode: str(r.kode), type: r.type })}
                            emptyForm={{ name: '', kode: '', type: 'Site' }}
                            extraColumns={[
                                {
                                    label: 'Karyawan',
                                    render: (r) => count(r.karyawan_count, 'orang'),
                                },
                                { label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') },
                                {
                                    label: 'Kode Penerimaan',
                                    render: (r) =>
                                        lokasi
                                            .filter((l) => l.work_location_id === r.id)
                                            .map((l) => l.kode)
                                            .join(', ') || '-',
                                },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.karyawan_count
                                    ? `Masih dipakai ${r.karyawan_count} karyawan`
                                    : r.jabatan_count
                                      ? `Masih punya ${r.jabatan_count} jabatan`
                                      : r.lokasi_count
                                        ? 'Masih dipetakan dari kode lokasi penerimaan'
                                        : null
                            }
                            describe={(r) => `lokasi kerja ${r.name}`}
                        />
                    ),
                },
                {
                    id: 'sub-lokasi',
                    label: 'Sub Lokasi Kerja',
                    hint: 'Tempat di bawah satu Work Location (mis. pelabuhan, kantor perwakilan) untuk karyawan yang ditugaskan di luar site induknya. Di Plan Struktur Organisasi, Sub Lokasi menjadi kotak tersendiri di bawah Project Manager (atau atasan lain) dan bisa diisi departemen, section dan jabatan.',
                    render: () => (
                        <CrudTable
                            title="Sub Lokasi Kerja"
                            rows={subLocations}
                            baseUrl={`${BASE}/sub-lokasi`}
                            fields={[
                                { key: 'name', label: 'Nama Sub Lokasi', placeholder: 'mis. Jetty Batulicin' },
                                { key: 'work_location_id', label: 'Work Location induk', type: 'select', options: workLocationOptions },
                            ]}
                            toForm={(r) => ({ name: r.name, work_location_id: str(r.work_location_id) })}
                            emptyForm={{ name: '', work_location_id: '' }}
                            extraColumns={[
                                { label: 'Jabatan', render: (r) => count(r.jabatan_count, 'jabatan') },
                                { label: 'Karyawan', render: (r) => count(r.karyawan_count, 'orang') },
                            ]}
                            deleteBlockedReason={(r) =>
                                r.jabatan_count
                                    ? `Masih punya ${r.jabatan_count} jabatan`
                                    : r.karyawan_count
                                      ? `Masih dipakai ${r.karyawan_count} karyawan`
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
                .filter((c) => !['alasan_keluar', 'bidang_jabatan', 'status_uang'].includes(c.kategori))
                .map((c) => ({
                    id: c.kategori,
                    label: c.label,
                    hint: 'Mengganti nama sebuah pilihan ikut memperbarui data karyawan yang memakainya. Pilihan yang masih dipakai tidak bisa dihapus.',
                    render: () => <OptionList category={c} />,
                })),
        },
        {
            title: 'Karyawan Keluar',
            icon: LogOut,
            sections: options
                .filter((c) => c.kategori === 'alasan_keluar')
                .map((c) => ({
                    id: c.kategori,
                    label: c.label,
                    hint: 'Pilihan alasan di dialog Karyawan Keluar.',
                    render: () => <OptionList category={c} />,
                })),
        },
    ];

    const allSections = groups.flatMap((g) => g.sections);
    const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'jenis-karyawan');
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
            <Head title="Master Data Personalia" />

            <header className="flex h-16 shrink-0 items-center border-b-[3px] border-accent-400 bg-white px-8">
                <h1 className="text-xl font-bold tracking-tight text-slate-800">Master Data Personalia</h1>
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

                        {(active.id === 'jenis-karyawan' || active.id === 'lokasi-penerimaan') && (
                            <NrpGuide jenis={jenis} lokasi={lokasi} workLocations={workLocations} />
                        )}

                        {/* Keyed so each master type gets fresh form state instead of inheriting the previous type's add/edit row. */}
                        <Fragment key={active.id}>{active.render()}</Fragment>
                    </div>
                </div>
            </div>
        </>
    );
}
