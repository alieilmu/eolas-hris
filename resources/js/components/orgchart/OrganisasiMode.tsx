import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    pointerWithin,
    useDraggable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { Boxes, Building2, Check, ChevronDown, Crown, Layers, Link2, Loader2, MapPinned, Network, Search, UserCog, X } from 'lucide-react';
import { OrgCanvas } from '@/components/orgchart/OrgCanvas';
import { CARD_HEIGHT, PositionCard, SiteCard, SubLocationBox, type DragData, type DropData } from '@/components/orgchart/OrgCards';
import { AsideRail, CloseAsideButton, ViewSwitch, useStoredSwitch } from '@/components/orgchart/OrgLayout';
import { eachNode, isWithin, type JabatanData, type LocationTab, type OrgNode, type SiteNode, type SubLocationNode } from '@/lib/orgTree';

interface Candidate {
    id: number;
    nrp: string;
    name: string;
    work_location: string | null;
    jabatan: string | null;
}

interface Setup {
    pm_sub_group: string | null;
    dpm_sub_group: string | null;
    pm_candidates: Candidate[];
    dpm_candidates: Candidate[];
    atasan_options: { id: number; name: string }[];
}

interface UnitItem {
    id: number;
    name: string;
    group?: string | null;
    departemen_id?: number;
    /** How many times its head is in the plan already (site and Sub Lokasi). */
    in_plan: number;
}

type UnitLevel = 'bod' | 'divisi' | 'departemen' | 'section';

interface LayerSubGroup {
    id: number;
    nama: string;
    layer: number;
    gelar: string | null;
    gelar_ho: string | null;
}

interface Palette {
    sub_locations: { id: number; name: string; in_plan: boolean }[];
    bods: UnitItem[];
    divisis: UnitItem[];
    departemens: UnitItem[];
    sections: UnitItem[];
    layers: { layer: number; sub_groups: LayerSubGroup[] }[];
    unit_sub_groups: Record<UnitLevel, number | null>;
    bidang: string[];
}

export interface OrganisasiModeProps {
    locations: LocationTab[];
    location: { id: number; name: string; kode: string | null; type: string } | null;
    needs_setup: boolean;
    setup: Setup | null;
    tree: OrgNode[];
    unlinked_sites: SiteNode[];
    palette: Palette | null;
    can_edit: boolean;
}

const heightOf = (n: OrgNode) => (n.kind === 'sub_location' ? 96 : CARD_HEIGHT);

/** One change sent to the plan (App\Support\Org\OrgChartChanges). */
type Op = Record<string, string | number | boolean | null | undefined | Record<string, string | number | null | undefined>>;
const visit = { preserveScroll: true, preserveState: true } as const;

/** Where a drop puts something: under a position, or at the top of a Sub Lokasi box. */
type Target = { parent: number | null; subLocationId: number | null; jabatan: JabatanData | null; label: string };

const targetOf = (drop: DropData | undefined): Target | null => {
    if (drop?.type === 'jabatan') {
        const j = drop.jabatan as JabatanData;
        return { parent: j.id, subLocationId: j.sub_location_id, jabatan: j, label: j.name };
    }
    if (drop?.type === 'sub_location') {
        const b = drop.box as SubLocationNode;
        return { parent: b.parent_jabatan_id, subLocationId: b.id, jabatan: null, label: `Sub Lokasi ${b.name}` };
    }
    return null;
};

/**
 * Mode Organisasi: which positions a work location has and who reports to whom. A site starts
 * from its Project Manager; units and Layer 1–2 titles are dropped in from the list on the right.
 */
export function OrganisasiMode({
    location,
    needs_setup: needsSetup,
    setup,
    tree,
    unlinked_sites: unlinkedSites,
    palette,
    can_edit: canEdit,
}: OrganisasiModeProps) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState<DragData | null>(null);
    const [bidangFor, setBidangFor] = useState<{ subGroup: LayerSubGroup; target: Target } | null>(null);
    const [byLayer, setByLayer] = useStoredSwitch('org.byLayer');
    const [asideClosed, setAsideClosed] = useStoredSwitch('org.asideClosed');
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
    const isHo = location?.type === 'Head Office';
    const focusKey = useMemo(() => {
        const id = Number(new URLSearchParams(window.location.search).get('focus'));
        return id ? `j${id}` : null;
    }, []);

    const roots = useMemo(() => [...tree, ...unlinkedSites], [tree, unlinkedSites]);
    const stats = useMemo(() => {
        let jabatan = 0;
        let vacant = 0;
        eachNode(tree, (n) => {
            if (n.kind !== 'jabatan') return;
            jabatan++;
            if (!n.holders.length) vacant++;
        });
        return { jabatan, vacant };
    }, [tree]);
    // Positions at the site itself without a superior, besides the Project Manager.
    const loose = isHo ? 0 : tree.filter((n) => n.kind === 'jabatan' && !(n.sumber === 'work_location' && !/deputy/i.test(n.sub_group ?? ''))).length;

    const send = (ops: Op[], done?: () => void) => {
        if (!location) return;
        setBusy(true);
        router.post(
            `/manpower/org-structure/plan/${location.id}/ops`,
            { ops },
            {
                ...visit,
                onSuccess: () => {
                    setError(null);
                    done?.();
                },
                onError: (errors) => setError(errors.ops ?? Object.values(errors)[0] ?? 'Gagal menyimpan plan.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    const createOp = (penempatan: Record<string, string | number | null>, target: Target) => ({
        type: 'create',
        temp_id: `temp-${Date.now()}`,
        parent: target.parent,
        penempatan: { work_location_id: location!.id, sub_location_id: target.subLocationId, ...penempatan },
    });

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        const drag = active.data.current as DragData | undefined;
        const drop = over?.data.current as DropData | undefined;
        if (!drag || !drop || !location) return;
        const target = targetOf(drop);

        switch (drag.type) {
            case 'palette': {
                // Dropped on the empty canvas: a position without a superior (e.g. the first BOD).
                const at = target ?? (drop.type === 'root' ? { parent: null, subLocationId: null, jabatan: null, label: 'puncak bagan' } : null);
                if (!at) return;
                if (drag.kind === 'unit') {
                    const level = drag.level as UnitLevel;
                    const subGroupId = palette?.unit_sub_groups[level];
                    if (!subGroupId) {
                        setError(`Belum ada SubGroup yang dinamai dari ${level} di Master Data.`);
                        return;
                    }
                    send([createOp({ sub_group_id: subGroupId, [`${level}_id`]: drag.id as number }, at)]);
                } else if (drag.kind === 'sub_group') {
                    setBidangFor({ subGroup: drag.subGroup as LayerSubGroup, target: at });
                } else if (drag.kind === 'sub_location') {
                    if (!at.jabatan) {
                        setError('Sub Lokasi ditaruh di bawah sebuah jabatan (mis. Project Manager).');
                        return;
                    }
                    send([{ type: 'sub_location', sub_location: drag.id as number, parent: at.jabatan.id }]);
                }
                return;
            }
            case 'jabatan': {
                const id = drag.id as number;
                if (drop.type === 'root') {
                    send([{ type: 'reparent', jabatan: id, parent: null }]);
                    return;
                }
                if (!target || target.parent === id) return;
                if (target.parent && isWithin(tree, id, target.parent)) {
                    setError('Jabatan tidak bisa dipindah ke bawah bawahannya sendiri.');
                    return;
                }
                send([{ type: 'reparent', jabatan: id, parent: target.parent, sub_location: target.subLocationId }]);
                return;
            }
            case 'sub_location': {
                if (target?.jabatan) send([{ type: 'sub_location', sub_location: drag.id as number, parent: target.jabatan.id }]);
                return;
            }
            case 'site': {
                if (drop.type === 'root') send([{ type: 'reparent', jabatan: drag.jabatanId as number, parent: null }]);
                else if (target?.jabatan) send([{ type: 'reparent', jabatan: drag.jabatanId as number, parent: target.jabatan.id }]);
                return;
            }
        }
    };

    const removeJabatan = (j: JabatanData) => {
        if (confirm(`Hapus jabatan "${j.name}" dari plan ${location?.name}?`)) send([{ type: 'delete', jabatan: j.id }]);
    };

    const linkLoose = () => {
        if (!location) return;
        setBusy(true);
        router.post(
            `/manpower/org-structure/plan/${location.id}/link`,
            {},
            { ...visit, onError: (e) => setError(Object.values(e)[0]), onFinish: () => setBusy(false) },
        );
    };

    const renderNode = (n: OrgNode) => {
        if (n.kind === 'jabatan') {
            return (
                <PositionCard
                    jabatan={n}
                    mode="plan"
                    draggable={canEdit}
                    droppable={canEdit}
                    onDelete={canEdit ? () => removeJabatan(n) : undefined}
                />
            );
        }
        if (n.kind === 'sub_location') {
            return (
                <SubLocationBox
                    box={n}
                    draggable={canEdit}
                    droppable={canEdit}
                    onRemove={canEdit ? () => send([{ type: 'sub_location', sub_location: n.id, parent: null }]) : undefined}
                />
            );
        }
        return <SiteCard site={n} draggable={canEdit && isHo} href={`/manpower/org-structure?lokasi=${n.work_location.id}&mode=organisasi`} />;
    };

    return (
        <>
            {!location ? (
                <div className="flex flex-1 items-center justify-center bg-slate-50 text-sm text-slate-400">
                    Belum ada Work Location di Master Data.
                </div>
            ) : needsSetup ? (
                <SetupPanel location={location} setup={setup} canEdit={canEdit} />
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={pointerWithin}
                    onDragStart={(e: DragStartEvent) => setDragging((e.active.data.current as DragData) ?? null)}
                    onDragCancel={() => setDragging(null)}
                    onDragEnd={onDragEnd}
                >
                    <div className="flex min-h-0 flex-1">
                        <div className="flex min-w-0 flex-1 flex-col bg-slate-50">
                            <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-6 py-2.5">
                                <div className="flex items-center gap-4 text-xs text-slate-500">
                                    <span>
                                        <b className="text-slate-700">{stats.jabatan}</b> jabatan
                                    </span>
                                    <span>
                                        <b className="text-amber-600">{stats.vacant}</b> vacant
                                    </span>
                                    {busy && <Loader2 size={14} className="animate-spin text-brand-500" />}
                                </div>
                                <div className="flex items-center gap-3">
                                    <ViewSwitch label="Pemisah layer" checked={byLayer} onChange={setByLayer} />
                                    {canEdit && loose > 0 && (
                                        <button
                                            onClick={linkLoose}
                                            disabled={busy}
                                            className="flex items-center gap-1.5 rounded-xl bg-accent-100 px-3 py-1.5 text-xs font-bold text-accent-800 hover:bg-accent-200 disabled:opacity-50"
                                        >
                                            <Link2 size={13} />
                                            Hubungkan {loose} jabatan tanpa atasan ke Project Manager
                                        </button>
                                    )}
                                    <p className="max-w-xl text-right text-[11px] text-slate-400">
                                        {canEdit
                                            ? isHo
                                                ? 'Geser organisasi dari daftar kanan ke kartu atasannya. Kartu lokasi kerja digeser ke jabatan HO atasan Project Manager-nya.'
                                                : 'Geser organisasi dari daftar kanan ke kartu atasannya. Kartu juga bisa digeser untuk mengganti atasan; lepas di area kosong untuk melepas atasannya.'
                                            : 'Hanya Admin / HR yang dapat mengubah plan.'}
                                    </p>
                                </div>
                            </div>
                            {error && (
                                <div className="mx-6 mt-3 flex items-start justify-between gap-4 rounded-xl border border-rose-100 bg-rose-50 px-4 py-2.5 text-sm text-rose-600">
                                    <span>{error}</span>
                                    <button onClick={() => setError(null)} className="shrink-0 text-rose-400 hover:text-rose-600" aria-label="Tutup">
                                        <X size={16} />
                                    </button>
                                </div>
                            )}
                            <OrgCanvas
                                roots={roots}
                                heightOf={heightOf}
                                byLayer={byLayer}
                                focusKey={focusKey}
                                rootDropId={canEdit ? 'plan-root' : undefined}
                                renderNode={(p) => renderNode(p.node)}
                                empty={
                                    <div className="flex h-full flex-col items-center justify-center gap-2 p-10 text-center text-sm text-slate-400">
                                        <Network size={30} />
                                        {isHo
                                            ? 'Plan Head Office masih kosong. Mulai dengan menggeser BOD dari daftar kanan ke sini.'
                                            : 'Plan masih kosong.'}
                                        {canEdit && isHo && <RootDropHint />}
                                    </div>
                                }
                            />
                        </div>
                        {canEdit &&
                            palette &&
                            (asideClosed ? (
                                <AsideRail label="Organisasi" onOpen={() => setAsideClosed(false)} />
                            ) : (
                                <PaletteAside palette={palette} isHo={isHo} onClose={() => setAsideClosed(true)} />
                            ))}
                    </div>

                    <DragOverlay dropAnimation={null}>{dragging && <DragGhost data={dragging} />}</DragOverlay>
                </DndContext>
            )}

            {bidangFor && location && palette && (
                <BidangDialog
                    subGroup={bidangFor.subGroup}
                    target={bidangFor.target}
                    isHo={isHo}
                    palette={palette}
                    busy={busy}
                    onClose={() => setBidangFor(null)}
                    onSubmit={(values) =>
                        send([createOp({ sub_group_id: bidangFor.subGroup.id, ...values }, bidangFor.target)], () => setBidangFor(null))
                    }
                />
            )}
        </>
    );
}

/** HO with nothing yet: the first BOD is dropped on the canvas itself. */
function RootDropHint() {
    return <p className="text-xs">Tips: lepas di area ini untuk membuat jabatan tanpa atasan.</p>;
}

function DragGhost({ data }: { data: DragData }) {
    const label = (data.name as string) ?? (data.subGroup as LayerSubGroup | undefined)?.nama ?? '';
    return (
        <div className="pointer-events-none max-w-60 rounded-xl border border-accent-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-xl">
            {label}
        </div>
    );
}

// --- the Project Manager first -------------------------------------------------------------------

function SetupPanel({ location, setup, canEdit }: { location: NonNullable<OrganisasiModeProps['location']>; setup: Setup | null; canEdit: boolean }) {
    const form = useForm({ pm_karyawan_id: '', deputy: false, dpm_karyawan_id: '', atasan_jabatan_id: '' });
    const select = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
    const label = 'mb-1.5 block text-xs font-bold text-slate-500';
    const option = (c: Candidate) => `${c.name} (NRP ${c.nrp})${c.jabatan ? ` — ${c.jabatan}` : ''}${c.work_location ? ` · ${c.work_location}` : ''}`;

    if (!canEdit || !setup) {
        return (
            <div className="flex flex-1 items-center justify-center bg-slate-50 p-10 text-center text-sm text-slate-400">
                Struktur {location.name} belum disusun. Admin / HR memulainya dengan memilih Project Manager.
            </div>
        );
    }

    const submit = () => {
        form.transform((d) => ({
            pm_karyawan_id: d.pm_karyawan_id || null,
            deputy: d.deputy,
            dpm_karyawan_id: d.deputy && d.dpm_karyawan_id ? d.dpm_karyawan_id : null,
            atasan_jabatan_id: d.atasan_jabatan_id || null,
        }));
        form.post(`/manpower/org-structure/plan/${location.id}/setup`, visit);
    };

    return (
        <div className="flex flex-1 items-start justify-center overflow-y-auto bg-slate-50 p-8">
            <div className="w-full max-w-xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div className="flex items-start gap-3 border-b border-slate-100 px-6 py-5">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                        <UserCog size={20} />
                    </div>
                    <div>
                        <h3 className="font-bold text-slate-800">Mulai susun SO {location.name}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            Struktur site dimulai dari {setup.pm_sub_group ?? 'Project Manager'}-nya. Pilih orangnya, atau biarkan Vacant.
                        </p>
                    </div>
                </div>

                <div className="space-y-4 p-6">
                    {Object.values(form.errors).length > 0 && (
                        <p className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-2.5 text-sm text-rose-600">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <div>
                        <label className={label}>{setup.pm_sub_group ?? 'Project Manager'} *</label>
                        <select value={form.data.pm_karyawan_id} onChange={(e) => form.setData('pm_karyawan_id', e.target.value)} className={select}>
                            <option value="">Vacant (belum ada orangnya)</option>
                            {setup.pm_candidates.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {option(c)}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-[11px] text-slate-400">Pilihan: karyawan ber-SubGroup {setup.pm_sub_group ?? 'Project Manager'}.</p>
                    </div>

                    <label className="flex items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2.5">
                        <input
                            type="checkbox"
                            checked={form.data.deputy}
                            onChange={(e) => form.setData('deputy', e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-brand-600"
                            disabled={!setup.dpm_sub_group}
                        />
                        <span className="text-sm font-bold text-slate-700">Butuh {setup.dpm_sub_group ?? 'Deputy Project Manager'}</span>
                        {!setup.dpm_sub_group && <span className="text-[11px] text-slate-400">(SubGroup-nya belum ada di Master Data)</span>}
                    </label>
                    {form.data.deputy && (
                        <div>
                            <label className={label}>{setup.dpm_sub_group}</label>
                            <select
                                value={form.data.dpm_karyawan_id}
                                onChange={(e) => form.setData('dpm_karyawan_id', e.target.value)}
                                className={select}
                            >
                                <option value="">Vacant</option>
                                {setup.dpm_candidates.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {option(c)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div>
                        <label className={label}>Atasan di Head Office (opsional)</label>
                        <select
                            value={form.data.atasan_jabatan_id}
                            onChange={(e) => form.setData('atasan_jabatan_id', e.target.value)}
                            className={select}
                        >
                            <option value="">— atur nanti di Plan Head Office —</option>
                            {setup.atasan_options.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <p className="rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">
                        Jabatan {location.name} yang sudah ada dan belum punya atasan akan otomatis ditaruh di bawah Project Manager.
                    </p>
                </div>

                <div className="flex justify-end border-t border-slate-100 px-6 py-4">
                    <button
                        onClick={submit}
                        disabled={form.processing}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Network size={16} />}
                        Buat SO {location.name}
                    </button>
                </div>
            </div>
        </div>
    );
}

// --- the list on the right ------------------------------------------------------------------

function PaletteAside({ palette, isHo, onClose }: { palette: Palette; isHo: boolean; onClose: () => void }) {
    const [query, setQuery] = useState('');
    const q = query.trim().toLowerCase();
    const match = (...texts: (string | null | undefined)[]) => !q || texts.some((t) => t?.toLowerCase().includes(q));

    const units = (level: UnitLevel, items: UnitItem[]) =>
        items
            .filter((i) => match(i.name, i.group))
            .map((i) => (
                <PaletteItem
                    key={`${level}-${i.id}`}
                    id={`pal:${level}:${i.id}`}
                    data={{ type: 'palette', kind: 'unit', level, id: i.id, name: i.name }}
                    title={i.name}
                    subtitle={i.group ?? undefined}
                    count={i.in_plan}
                    disabled={!palette.unit_sub_groups[level]}
                />
            ));

    return (
        <aside className="flex w-80 shrink-0 flex-col border-l border-slate-200 bg-white">
            <div className="shrink-0 border-b border-slate-100 p-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-bold text-slate-800">Organisasi</h3>
                    <CloseAsideButton onClick={onClose} />
                </div>
                <p className="mt-0.5 text-[11px] text-slate-400">
                    Geser ke kartu atasannya di bagan. ✓ = sudah ada di plan; departemen & section bisa ditambah lagi (mis. di Sub Lokasi).
                </p>
                <div className="relative mt-3">
                    <Search size={14} className="absolute top-2.5 left-3 text-slate-300" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cari departemen, section..."
                        className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                    />
                </div>
            </div>
            <div className="min-h-0 flex-1 space-y-1 overflow-y-auto p-3">
                <PaletteGroup icon={MapPinned} title="Sub Lokasi Kerja" count={palette.sub_locations.length} defaultOpen>
                    {palette.sub_locations.length === 0 ? (
                        <p className="px-2 py-1 text-[11px] text-slate-400">
                            Belum ada.{' '}
                            <Link href="/manpower/master-data?tab=sub-lokasi" className="font-bold text-brand-600 hover:underline">
                                Tambah di Master Data
                            </Link>
                        </p>
                    ) : (
                        palette.sub_locations
                            .filter((s) => match(s.name))
                            .map((s) => (
                                <PaletteItem
                                    key={s.id}
                                    id={`pal:sub_location:${s.id}`}
                                    data={{ type: 'palette', kind: 'sub_location', id: s.id, name: s.name }}
                                    title={s.name}
                                    done={s.in_plan}
                                />
                            ))
                    )}
                </PaletteGroup>
                {isHo && (
                    <>
                        <PaletteGroup icon={Crown} title="BOD" count={palette.bods.length}>
                            {units('bod', palette.bods)}
                        </PaletteGroup>
                        <PaletteGroup icon={Network} title="Divisi" count={palette.divisis.length}>
                            {units('divisi', palette.divisis)}
                        </PaletteGroup>
                    </>
                )}
                <PaletteGroup icon={Building2} title="Departemen" count={palette.departemens.length} defaultOpen={!!q}>
                    {units('departemen', palette.departemens)}
                </PaletteGroup>
                <PaletteGroup icon={Boxes} title="Section" count={palette.sections.length} defaultOpen={!!q}>
                    {units('section', palette.sections)}
                </PaletteGroup>
                {palette.layers.map((l) => (
                    <PaletteGroup key={l.layer} icon={Layers} title={`Jabatan Layer ${l.layer}`} count={l.sub_groups.length} defaultOpen>
                        {l.sub_groups
                            .filter((g) => match(g.nama, g.gelar, g.gelar_ho))
                            .map((g) => (
                                <PaletteItem
                                    key={g.id}
                                    id={`pal:sub_group:${g.id}`}
                                    data={{ type: 'palette', kind: 'sub_group', subGroup: g, name: g.nama }}
                                    title={g.nama}
                                    subtitle={[...new Set([g.gelar_ho, g.gelar].filter(Boolean))].join(' / ') || 'Nama Jabatan saja'}
                                />
                            ))}
                    </PaletteGroup>
                ))}
            </div>
        </aside>
    );
}

function PaletteGroup({
    icon: Icon,
    title,
    count,
    defaultOpen = false,
    children,
}: {
    icon: typeof Layers;
    title: string;
    count: number;
    defaultOpen?: boolean;
    children: React.ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    return (
        <div className="rounded-xl border border-slate-100">
            <button onClick={() => setOpen((v) => !v)} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left">
                <span className="flex items-center gap-2 text-xs font-bold text-slate-600">
                    <Icon size={14} className="text-brand-500" />
                    {title}
                    <span className="rounded-full bg-slate-100 px-1.5 text-[10px] text-slate-500">{count}</span>
                </span>
                <ChevronDown size={14} className={`text-slate-400 transition-transform ${open ? '' : '-rotate-90'}`} />
            </button>
            {open && <div className="space-y-0.5 px-1.5 pb-1.5">{children}</div>}
        </div>
    );
}

function PaletteItem({
    id,
    data,
    title,
    subtitle,
    done,
    count,
    disabled,
}: {
    id: string;
    data: DragData;
    title: string;
    subtitle?: string;
    /** Can be in the plan once only, and is. */
    done?: boolean;
    /** Can be in the plan several times (the site and its Sub Lokasi): how many times it is. */
    count?: number;
    disabled?: boolean;
}) {
    const drag = useDraggable({ id, data, disabled: disabled || done });
    return (
        <div
            ref={drag.setNodeRef}
            {...drag.attributes}
            {...drag.listeners}
            title={
                done
                    ? 'Sudah ada di plan lokasi ini'
                    : disabled
                      ? 'SubGroup-nya belum ada di Master Data'
                      : count
                        ? `Sudah ada ${count}× di plan — bisa ditambah lagi, mis. di Sub Lokasi`
                        : 'Geser ke bagan'
            }
            className={`flex items-center gap-2 rounded-lg px-2 py-1.5 ${
                done || disabled ? 'cursor-default text-slate-400' : 'cursor-grab text-slate-700 hover:bg-brand-50'
            } ${drag.isDragging ? 'opacity-40' : ''}`}
        >
            <div className="min-w-0 flex-1">
                <p className="truncate text-xs font-bold">{title}</p>
                {subtitle && <p className="truncate text-[10px] text-slate-400">{subtitle}</p>}
            </div>
            {done && <Check size={13} className="shrink-0 text-emerald-500" />}
            {!done && !!count && (
                <span className="flex shrink-0 items-center gap-0.5 text-[10px] font-bold text-emerald-600">
                    <Check size={12} />
                    {count > 1 ? `${count}×` : ''}
                </span>
            )}
        </div>
    );
}

// --- a Layer 1–2 title ------------------------------------------------------------------------

function BidangDialog({
    subGroup,
    target,
    isHo,
    palette,
    busy,
    onClose,
    onSubmit,
}: {
    subGroup: LayerSubGroup;
    target: Target;
    isHo: boolean;
    palette: Palette;
    busy: boolean;
    onClose: () => void;
    onSubmit: (values: { bidang: string; section_id: number | null; departemen_id: number | null }) => void;
}) {
    const [bidang, setBidang] = useState('');
    // Defaults to the unit of the superior it goes under.
    const [sectionId, setSectionId] = useState(target.jabatan?.section_id ? String(target.jabatan.section_id) : '');
    const [departemenId, setDepartemenId] = useState(target.jabatan?.departemen_id ? String(target.jabatan.departemen_id) : '');
    const gelar = isHo && subGroup.gelar_ho ? subGroup.gelar_ho : subGroup.gelar;
    const known = palette.bidang.find((b) => b.toLowerCase() === bidang.trim().toLowerCase());
    const sections = palette.sections.filter((s) => !departemenId || String(s.departemen_id) === departemenId);
    const input = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm"
            onMouseDown={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex items-start justify-between border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 className="font-bold text-slate-800">Tambah {subGroup.nama}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">Di bawah {target.label}</p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>
                <div className="space-y-4 p-6">
                    <div>
                        <label className="mb-1.5 block text-xs font-bold text-slate-500">Nama Jabatan *</label>
                        <input
                            value={bidang}
                            onChange={(e) => setBidang(e.target.value)}
                            list="plan-bidang"
                            autoFocus
                            placeholder="Ketik untuk mencari..."
                            className={input}
                        />
                        <datalist id="plan-bidang">
                            {palette.bidang.map((b) => (
                                <option key={b} value={b} />
                            ))}
                        </datalist>
                        {bidang && !known && <p className="mt-1 text-[11px] text-rose-500">Tidak ada di master Nama Jabatan.</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="mb-1.5 block text-xs font-bold text-slate-500">Departemen</label>
                            <select
                                value={departemenId}
                                onChange={(e) => {
                                    setDepartemenId(e.target.value);
                                    setSectionId('');
                                }}
                                className={input}
                            >
                                <option value="">—</option>
                                {palette.departemens.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1.5 block text-xs font-bold text-slate-500">Section</label>
                            <select value={sectionId} onChange={(e) => setSectionId(e.target.value)} className={input}>
                                <option value="">—</option>
                                {sections.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="rounded-xl border border-brand-100 bg-brand-50/60 px-3 py-2.5 text-sm">
                        <span className="text-slate-500">Jabatan: </span>
                        <b className="text-brand-800">{known ? [gelar, known].filter(Boolean).join(' ') : '—'}</b>
                    </div>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 px-6 py-4">
                    <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100">
                        Batal
                    </button>
                    <button
                        onClick={() =>
                            known &&
                            onSubmit({
                                bidang: known,
                                section_id: sectionId ? Number(sectionId) : null,
                                departemen_id: departemenId ? Number(departemenId) : null,
                            })
                        }
                        disabled={!known || busy}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                    >
                        {busy && <Loader2 size={16} className="animate-spin" />}
                        Tambahkan
                    </button>
                </div>
            </div>
        </div>
    );
}
