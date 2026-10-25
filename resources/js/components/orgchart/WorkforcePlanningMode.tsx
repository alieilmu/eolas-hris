import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    pointerWithin,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { ArrowRightLeft, ClipboardList, IdCard, Loader2, MapPinned, Network, Search, User, UserMinus, UserPlus, Users, X } from 'lucide-react';
import { AddEmployeeDialog, type FixedJabatan } from '@/components/core_hr/AddEmployeeDialog';
import { OrgCanvas } from '@/components/orgchart/OrgCanvas';
import {
    CARD_HEIGHT,
    PositionCard,
    positionCardHeight,
    SiteCard,
    SubLocationBox,
    type DragData,
    type DropData,
} from '@/components/orgchart/OrgCards';
import { AsideRail, CloseAsideButton, ViewSwitch, useStoredSwitch } from '@/components/orgchart/OrgLayout';
import {
    eachNode,
    findJabatan,
    type Holder,
    type JabatanData,
    type LocationTab,
    type OrgNode,
    type SiteNode,
    type SubLocationNode,
} from '@/lib/orgTree';

interface Person {
    id: number;
    employee_number: string;
    name: string;
    status: string;
    job_level_id: number | null;
    job_level: string | null;
    layer: number | null;
    department: string | null;
    section: string | null;
    discipline: string | null;
    sub_location_id: number | null;
    sub_location: string | null;
    position_id: number | null;
    position_name: string | null;
}

export interface MppModeProps {
    locations: LocationTab[];
    location: { id: number; name: string; code: string | null; type: string } | null;
    needs_setup: boolean;
    tree: OrgNode[];
    unlinked_sites: SiteNode[];
    people: Person[];
    can_edit: boolean;
    /** Opens Mode Organisasi (a site not set up yet). */
    onSetup: () => void;
}

/** Who is moved, and what we know of them (a holder on a card only carries id / nrp / name). */
type Mover = Pick<Person, 'id' | 'name'> & Partial<Person>;

const visit = { preserveScroll: true, preserveState: true } as const;

/**
 * Mode MPP: the organisation of a work location filled with people. The employees working there
 * are listed by SubGroup and departemen, to be dropped into its positions or Sub Lokasi.
 */
export function WorkforcePlanningMode({
    locations,
    location,
    needs_setup: needsSetup,
    tree,
    unlinked_sites: unlinkedSites,
    people,
    can_edit: canEdit,
    onSetup,
}: MppModeProps) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [dragging, setDragging] = useState<DragData | null>(null);
    const [openId, setOpenId] = useState<number | null>(null);
    const [transfer, setMutasi] = useState<Mover | null>(null);
    const [newFor, setNewFor] = useState<FixedJabatan | null>(null);
    const [expanded, setExpanded] = useStoredSwitch('so.expanded');
    const [byLayer, setByLayer] = useStoredSwitch('org.byLayer');
    const [asideClosed, setAsideClosed] = useStoredSwitch('org.asideClosed');
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
    const isHo = location?.type === 'head_office';
    const focusKey = useMemo(() => {
        const id = Number(new URLSearchParams(window.location.search).get('focus'));
        return id ? `j${id}` : null;
    }, []);

    const roots = useMemo(() => [...tree, ...unlinkedSites], [tree, unlinkedSites]);
    const personById = useMemo(() => new Map(people.map((p) => [p.id, p])), [people]);
    const stats = useMemo(() => {
        let position = 0;
        let filled = 0;
        eachNode(tree, (n) => {
            if (n.kind !== 'position') return;
            position++;
            if (n.holders.length) filled++;
        });
        return { position, filled, vacant: position - filled, waiting: people.filter((p) => !p.position_id).length };
    }, [tree, people]);

    // How many work in each Sub Lokasi box.
    const boxPeople = useMemo(() => {
        const map = new Map<number, number>();
        eachNode(tree, (n) => {
            if (n.kind !== 'sub_location') return;
            let count = 0;
            eachNode(n.children, (c) => {
                if (c.kind === 'position') count += c.holders.length;
            });
            map.set(n.id, count);
        });
        return map;
    }, [tree]);

    const heightOf = useCallback(
        (n: OrgNode) => (n.kind === 'position' ? positionCardHeight(n, expanded) : n.kind === 'sub_location' ? 96 : CARD_HEIGHT),
        [expanded],
    );

    const fail = (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.');
    const run = (send: (opts: object) => void, done?: () => void) => {
        setBusy(true);
        send({
            ...visit,
            onSuccess: () => {
                setError(null);
                done?.();
            },
            onError: fail,
            onFinish: () => setBusy(false),
        });
    };

    const known = (k: Mover): Mover => ({ ...k, ...(personById.get(k.id) ?? {}) });

    /** Into a position (here or, from Mutasi, at another work location). */
    const assign = (k: Mover, j: { id: number; name: string; job_level_id: number | null; job_level: string | null }, done?: () => void) => {
        const who = known(k);
        if (who.job_level_id && j.job_level_id && who.job_level_id !== j.job_level_id) {
            const ok = confirm(
                `SubGroup ${who.name} (${who.job_level ?? '-'}) berbeda dengan jabatan ${j.name} (${j.job_level ?? '-'}).\n` +
                    'Penempatannya akan mengikuti jabatan ini. Lanjutkan?',
            );
            if (!ok) return;
        }
        run((opts) => router.put(`/core-hr/employees/${who.id}/position`, { position_id: j.id }, opts), done);
    };

    /** Into a Sub Lokasi: the position matching their placement there (made if new). */
    const toSubLocation = (k: Mover, box: { id: number; name: string }, done?: () => void) =>
        run((opts) => router.post(`/core-hr/employees/${k.id}/sub-locations`, { sub_location_id: box.id }, opts), done);

    const remove = (k: Mover, positionName?: string) => {
        if (!confirm(`Copot ${k.name}${positionName ? ` dari ${positionName}` : ''}? Ia kembali ke daftar "Belum ditempatkan".`)) return;
        run((opts) => router.post(`/core-hr/employees/${k.id}/remove`, {}, opts));
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        setDragging(null);
        const drag = active.data.current as DragData | undefined;
        const drop = over?.data.current as DropData | undefined;
        if (!drag || !drop || (drag.type !== 'person' && drag.type !== 'holder')) return;
        const who = drag.employee as Mover;

        if (drop.type === 'position') {
            const j = drop.position as JabatanData;
            if (drag.type === 'holder' && drag.from === j.id) return;
            assign(who, j);
        } else if (drop.type === 'sub_location') {
            toSubLocation(who, drop.box as SubLocationNode);
        } else if (drop.type === 'people-panel' && drag.type === 'holder') {
            remove(who, findJabatan(tree, drag.from as number)?.name);
        }
    };

    const opened = openId !== null ? findJabatan(tree, openId) : null;

    const renderNode = (n: OrgNode) => {
        if (n.kind === 'position') {
            return <PositionCard position={n} mode="people" droppable={canEdit} expanded={expanded} onOpen={() => setOpenId(n.id)} />;
        }
        if (n.kind === 'sub_location') return <SubLocationBox box={n} droppable={canEdit} peopleCount={boxPeople.get(n.id) ?? 0} />;
        return <SiteCard site={n} href={`/core-hr/org-chart?location=${n.work_location.id}&mode=workforce_planning`} />;
    };

    return (
        <>
            {!location ? (
                <div className="flex flex-1 items-center justify-center bg-slate-50 text-sm text-slate-400">
                    Belum ada Work Location di Master Data.
                </div>
            ) : needsSetup ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-3 bg-slate-50 p-10 text-center">
                    <ClipboardList size={30} className="text-slate-300" />
                    <p className="text-sm text-slate-500">
                        Struktur {location.name} belum disusun. Mulai di Mode Organisasi dengan memilih Project Manager-nya.
                    </p>
                    <button onClick={onSetup} className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700">
                        Buka Mode Organisasi
                    </button>
                </div>
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
                                <div className="flex flex-wrap items-center gap-4 text-xs text-slate-500">
                                    <span>
                                        <b className="text-slate-700">{stats.position}</b> jabatan
                                    </span>
                                    <span>
                                        <b className="text-emerald-600">{stats.filled}</b> terisi
                                    </span>
                                    <span>
                                        <b className="text-amber-600">{stats.vacant}</b> vacant
                                    </span>
                                    {canEdit && (
                                        <span>
                                            <b className="text-rose-600">{stats.waiting}</b> belum ditempatkan
                                        </span>
                                    )}
                                    {busy && <Loader2 size={14} className="animate-spin text-brand-500" />}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <ViewSwitch label="Tampilkan karyawan" checked={expanded} onChange={setExpanded} />
                                    <ViewSwitch label="Pemisah layer" checked={byLayer} onChange={setByLayer} />
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
                                renderNode={(p) => renderNode(p.node)}
                                empty={
                                    <div className="flex h-full flex-col items-center justify-center gap-2 p-10 text-center text-sm text-slate-400">
                                        <Network size={30} />
                                        Belum ada jabatan di plan {location.name}.
                                        <button onClick={onSetup} className="font-bold text-brand-600 hover:underline">
                                            Susun di Mode Organisasi →
                                        </button>
                                    </div>
                                }
                            />
                        </div>
                        {canEdit &&
                            (asideClosed ? (
                                <AsideRail label="Karyawan" onOpen={() => setAsideClosed(false)} />
                            ) : (
                                <PeopleAside people={people} isHo={isHo} onTransfer={setMutasi} onClose={() => setAsideClosed(true)} />
                            ))}
                    </div>

                    <DragOverlay dropAnimation={null}>
                        {dragging && (
                            <div className="pointer-events-none flex items-center gap-1.5 rounded-xl border border-accent-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-xl">
                                <User size={12} className="text-emerald-500" />
                                {(dragging.employee as { name: string } | undefined)?.name}
                            </div>
                        )}
                    </DragOverlay>
                </DndContext>
            )}

            {opened && (
                <PositionDialog
                    position={opened}
                    people={people}
                    canEdit={canEdit}
                    busy={busy}
                    error={error}
                    onAssign={(p) => assign(p, opened)}
                    onRemove={(h) => remove(h, opened.name)}
                    onTransfer={(h) => setMutasi(known(h))}
                    onAddNew={() => {
                        setNewFor({ id: opened.id, name: opened.name, work_location_id: opened.work_location_id });
                        setOpenId(null);
                    }}
                    onClose={() => setOpenId(null)}
                />
            )}
            {transfer && location && (
                <TransferDialog
                    employee={known(transfer)}
                    locations={locations}
                    fromLocationId={location.id}
                    busy={busy}
                    error={error}
                    onPosition={(j) => assign(transfer, j, () => setMutasi(null))}
                    onSubLocation={(s) => toSubLocation(transfer, s, () => setMutasi(null))}
                    onClose={() => setMutasi(null)}
                />
            )}
            {newFor && <AddEmployeeDialog fixedPosition={newFor} onClose={() => setNewFor(null)} />}
        </>
    );
}

// --- the people on the right --------------------------------------------------------------------

function PeopleAside({ people, isHo, onTransfer, onClose }: { people: Person[]; isHo: boolean; onTransfer: (p: Person) => void; onClose: () => void }) {
    const [show, setShow] = useState<'waiting' | 'all'>('waiting');
    const [query, setQuery] = useState('');
    const drop = useDroppable({ id: 'people-panel', data: { type: 'people-panel' } satisfies DropData });

    const waiting = people.filter((p) => !p.position_id);
    const q = query.trim().toLowerCase();
    const list = (show === 'waiting' ? waiting : people).filter(
        (p) => !q || [p.name, p.employee_number, p.job_level, p.department, p.discipline, p.position_name, p.sub_location].some((t) => t?.toLowerCase().includes(q)),
    );

    // SubGroup (most senior first) → departemen → people.
    const groups = useMemo(() => {
        const bySub = new Map<string, { layer: number; departments: Map<string, Person[]> }>();
        list.forEach((p) => {
            const sub = p.job_level ?? 'Tanpa SubGroup';
            const dept = [p.department, p.sub_location].filter(Boolean).join(' · ') || 'Tanpa departemen';
            const entry = bySub.get(sub) ?? { layer: p.layer ?? 0, departments: new Map() };
            entry.departments.set(dept, [...(entry.departments.get(dept) ?? []), p]);
            bySub.set(sub, entry);
        });
        return [...bySub.entries()].sort((a, b) => b[1].layer - a[1].layer || a[0].localeCompare(b[0]));
    }, [list]);

    return (
        <aside
            ref={drop.setNodeRef}
            className={`flex w-80 shrink-0 flex-col border-l bg-white transition-colors ${drop.isOver ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200'}`}
        >
            <div className="shrink-0 border-b border-slate-100 p-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="flex items-center gap-2 text-sm font-bold text-slate-800">
                        <Users size={15} className="text-brand-500" />
                        Karyawan {isHo ? 'Head Office' : 'lokasi ini'}
                    </h3>
                    <CloseAsideButton onClick={onClose} />
                </div>
                <p className="mt-0.5 text-[11px] text-slate-400">
                    Geser nama ke kartu jabatan atau kotak Sub Lokasi. Lepas nama dari kartu di sini untuk mencopot.
                </p>
                <div className="mt-3 flex gap-1 rounded-xl bg-slate-100 p-1">
                    {(
                        [
                            ['waiting', `Belum ditempatkan (${waiting.length})`],
                            ['all', `Semua (${people.length})`],
                        ] as const
                    ).map(([id, label]) => (
                        <button
                            key={id}
                            onClick={() => setShow(id)}
                            className={`flex-1 rounded-lg px-2 py-1.5 text-[11px] font-bold ${show === id ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <div className="relative mt-2">
                    <Search size={14} className="absolute top-2.5 left-3 text-slate-300" />
                    <input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Cari nama, NRP, departemen..."
                        className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                    />
                </div>
            </div>
            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
                {groups.length === 0 && (
                    <p className="px-2 py-6 text-center text-xs text-slate-400">
                        {show === 'waiting' && !q ? 'Semua karyawan lokasi ini sudah ditempatkan.' : 'Tidak ada karyawan yang cocok.'}
                    </p>
                )}
                {groups.map(([sub, g]) => (
                    <div key={sub} className="rounded-xl border border-slate-100">
                        <p className="flex items-center justify-between rounded-t-xl bg-brand-50 px-3 py-1.5 text-[11px] font-bold text-brand-800">
                            {sub}
                            <span className="text-[10px] font-medium text-brand-500">layer {g.layer || '-'}</span>
                        </p>
                        {[...g.departments.entries()]
                            .sort((a, b) => a[0].localeCompare(b[0]))
                            .map(([dept, members]) => (
                                <div key={dept} className="px-1.5 py-1">
                                    <p className="px-1.5 pt-1 text-[10px] font-bold tracking-wide text-slate-400 uppercase">{dept}</p>
                                    {members.map((p) => (
                                        <PersonRow key={p.id} person={p} onTransfer={() => onTransfer(p)} />
                                    ))}
                                </div>
                            ))}
                    </div>
                ))}
            </div>
        </aside>
    );
}

function PersonRow({ person: p, onTransfer }: { person: Person; onTransfer: () => void }) {
    const drag = useDraggable({ id: `person:${p.id}`, data: { type: 'person', employee: p } satisfies DragData });
    return (
        <div className={`group/person flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-brand-50 ${drag.isDragging ? 'opacity-40' : ''}`}>
            <div ref={drag.setNodeRef} {...drag.attributes} {...drag.listeners} className="flex min-w-0 flex-1 cursor-grab items-center gap-2">
                <User size={12} className={`shrink-0 ${p.position_id ? 'text-emerald-500' : 'text-slate-300'}`} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-bold text-slate-700">{p.name}</p>
                    <p className="truncate text-[10px] text-slate-400">
                        {p.employee_number}
                        {p.position_name ? ` · ${p.position_name}` : p.discipline ? ` · ${p.discipline}` : ''}
                    </p>
                </div>
            </div>
            <button
                onClick={onTransfer}
                title={p.position_id ? 'Mutasi ke jabatan / lokasi lain' : 'Tempatkan ke jabatan / lokasi'}
                className="shrink-0 rounded-lg p-1 text-slate-300 opacity-0 group-hover/person:opacity-100 hover:bg-white hover:text-brand-600"
            >
                <ArrowRightLeft size={13} />
            </button>
        </div>
    );
}

// --- one position: who holds it -----------------------------------------------------------------

function PositionDialog({
    position,
    people,
    canEdit,
    busy,
    error,
    onAssign,
    onRemove,
    onTransfer,
    onAddNew,
    onClose,
}: {
    position: JabatanData;
    people: Person[];
    canEdit: boolean;
    busy: boolean;
    error: string | null;
    onAssign: (p: Person) => void;
    onRemove: (h: Holder) => void;
    onTransfer: (h: Holder) => void;
    onAddNew: () => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const full = position.single_holder && position.holders.length >= 1;
    const q = query.trim().toLowerCase();
    const candidates = people
        .filter((p) => p.position_id !== position.id)
        // Same SubGroup first, then those still waiting.
        .sort(
            (a, b) =>
                Number(b.job_level_id === position.job_level_id) - Number(a.job_level_id === position.job_level_id) ||
                Number(!!a.position_id) - Number(!!b.position_id),
        )
        .filter((p) => !q || [p.name, p.employee_number, p.position_name, p.department].some((t) => t?.toLowerCase().includes(q)))
        .slice(0, 60);
    const byId = new Map(people.map((p) => [p.id, p]));

    return (
        <div
            className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm"
            onMouseDown={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="flex max-h-[88vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex shrink-0 items-start justify-between border-b border-slate-100 px-6 py-5">
                    <div className="min-w-0">
                        <h3 className="font-bold text-slate-800">{position.name}</h3>
                        <p className="mt-0.5 text-xs text-slate-400">
                            {[position.job_level, position.unit_label, position.single_holder ? 'maksimal 1 karyawan aktif' : null]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="space-y-5 overflow-y-auto p-6">
                    {error && <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">{error}</div>}
                    <section>
                        <p className="mb-2 text-xs font-bold tracking-wide text-slate-500 uppercase">Karyawan ({position.holders.length})</p>
                        {position.holders.length === 0 ? (
                            <p className="text-sm text-slate-400 italic">Vacant.</p>
                        ) : (
                            <div className="space-y-1.5">
                                {position.holders.map((h) => {
                                    const p = byId.get(h.id);
                                    return (
                                        <div
                                            key={h.id}
                                            className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-bold text-slate-700">{h.name}</p>
                                                <p className="truncate text-[11px] text-slate-400">
                                                    NRP {h.employee_number}
                                                    {p?.department ? ` · ${p.department}` : ''}
                                                    {p?.sub_location ? ` · ${p.sub_location}` : ''}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1">
                                                <Link
                                                    href={`/core-hr/employees/${h.id}/profile`}
                                                    title="Profil"
                                                    className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600"
                                                >
                                                    <IdCard size={15} />
                                                </Link>
                                                {canEdit && (
                                                    <>
                                                        <button
                                                            onClick={() => onTransfer(h)}
                                                            disabled={busy}
                                                            className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-[11px] font-bold text-brand-600 hover:bg-brand-50 disabled:opacity-50"
                                                        >
                                                            <ArrowRightLeft size={12} />
                                                            Mutasi
                                                        </button>
                                                        <button
                                                            onClick={() => onRemove(h)}
                                                            disabled={busy}
                                                            className="flex items-center gap-1 rounded-lg px-2 py-1.5 text-[11px] font-bold text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                                                        >
                                                            <UserMinus size={12} />
                                                            Copot
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    {canEdit && (
                        <section className="border-t border-slate-100 pt-4">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-xs font-bold tracking-wide text-slate-500 uppercase">Tempatkan karyawan</p>
                                <button onClick={onAddNew} className="flex items-center gap-1 text-[11px] font-bold text-brand-600 hover:underline">
                                    <UserPlus size={12} />
                                    Karyawan baru
                                </button>
                            </div>
                            {full ? (
                                <p className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                    Jabatan ini hanya untuk 1 karyawan aktif. Mutasi atau copot pemegangnya dulu untuk menggantinya.
                                </p>
                            ) : (
                                <>
                                    <div className="relative mb-2">
                                        <Search size={14} className="absolute top-2.5 left-3 text-slate-300" />
                                        <input
                                            value={query}
                                            onChange={(e) => setQuery(e.target.value)}
                                            placeholder="Cari nama, NRP, jabatan..."
                                            className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                                        />
                                    </div>
                                    <div className="max-h-64 space-y-1 overflow-y-auto">
                                        {candidates.map((p) => (
                                            <div
                                                key={p.id}
                                                className="flex items-center justify-between gap-2 rounded-xl px-3 py-2 hover:bg-slate-50"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-slate-700">{p.name}</p>
                                                    <p className="truncate text-[11px] text-slate-400">
                                                        {p.job_level ?? '-'} · {p.position_name ?? 'belum ditempatkan'}
                                                    </p>
                                                </div>
                                                <button
                                                    onClick={() => onAssign(p)}
                                                    disabled={busy}
                                                    className="flex shrink-0 items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-brand-700 disabled:opacity-50"
                                                >
                                                    <UserPlus size={12} />
                                                    Tempatkan
                                                </button>
                                            </div>
                                        ))}
                                        {candidates.length === 0 && (
                                            <p className="px-3 py-2 text-sm text-slate-400">Tidak ada karyawan yang cocok.</p>
                                        )}
                                    </div>
                                </>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </div>
    );
}

// --- Mutasi: to a position or Sub Lokasi, here or at another work location --------------------

interface TargetPosition {
    id: number;
    name: string;
    job_level_id: number | null;
    job_level: string | null;
    layer: number | null;
    unit_label: string | null;
    sub_location: string | null;
    holders: number;
    single_holder: boolean;
}

function TransferDialog({
    employee,
    locations,
    fromLocationId,
    busy,
    error,
    onPosition,
    onSubLocation,
    onClose,
}: {
    employee: Mover;
    locations: LocationTab[];
    fromLocationId: number;
    busy: boolean;
    error: string | null;
    onPosition: (j: TargetPosition) => void;
    onSubLocation: (s: { id: number; name: string }) => void;
    onClose: () => void;
}) {
    const [locationId, setLocationId] = useState(fromLocationId);
    const [data, setData] = useState<{ positions: TargetPosition[]; sub_locations: { id: number; name: string }[] } | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [query, setQuery] = useState('');
    const [sameSubGroup, setSameSubGroup] = useState(!!employee.job_level_id);

    useEffect(() => {
        setData(null);
        setLoadError(null);
        fetch(`/core-hr/org-chart/positions?location=${locationId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then(setData)
            .catch(() => setLoadError('Gagal memuat jabatan lokasi itu.'));
    }, [locationId]);

    const q = query.trim().toLowerCase();
    const positions = (data?.positions ?? [])
        .filter((j) => j.id !== employee.position_id)
        .filter((j) => !sameSubGroup || !employee.job_level_id || j.job_level_id === employee.job_level_id)
        .filter((j) => !q || [j.name, j.unit_label, j.sub_location].some((t) => t?.toLowerCase().includes(q)));
    const location = locations.find((l) => l.id === locationId);

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 p-6 backdrop-blur-sm"
            onMouseDown={(e) => e.target === e.currentTarget && onClose()}
        >
            <div className="flex max-h-[88vh] w-full max-w-xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div className="flex shrink-0 items-start justify-between border-b border-slate-100 px-6 py-5">
                    <div className="min-w-0">
                        <h3 className="flex items-center gap-2 font-bold text-slate-800">
                            <ArrowRightLeft size={16} className="text-brand-500" />
                            Mutasi {employee.name}
                        </h3>
                        <p className="mt-0.5 truncate text-xs text-slate-400">
                            Sekarang: {employee.position_name ?? 'belum ditempatkan'}
                            {employee.job_level ? ` · ${employee.job_level}` : ''}
                        </p>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
                        <X size={18} />
                    </button>
                </div>

                <div className="shrink-0 space-y-2 border-b border-slate-100 px-6 py-4">
                    <div className="grid grid-cols-2 gap-2">
                        <select
                            value={locationId}
                            onChange={(e) => setLocationId(Number(e.target.value))}
                            className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                    {l.id === fromLocationId ? ' (lokasi sekarang)' : ''}
                                </option>
                            ))}
                        </select>
                        <div className="relative">
                            <Search size={14} className="absolute top-2.5 left-3 text-slate-300" />
                            <input
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Cari jabatan..."
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-sm outline-none focus:ring-2 focus:ring-brand-500"
                            />
                        </div>
                    </div>
                    {employee.job_level_id && (
                        <label className="flex items-center gap-2 text-xs text-slate-500">
                            <input
                                type="checkbox"
                                checked={sameSubGroup}
                                onChange={(e) => setSameSubGroup(e.target.checked)}
                                className="rounded border-slate-300"
                            />
                            Hanya jabatan SubGroup {employee.job_level}
                        </label>
                    )}
                    {error && <p className="rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-600">{error}</p>}
                </div>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    {loadError && <p className="text-sm text-rose-600">{loadError}</p>}
                    {!data && !loadError && <Loader2 size={18} className="mx-auto animate-spin text-brand-500" />}

                    {data && data.sub_locations.length > 0 && employee.job_level_id && (
                        <section>
                            <p className="mb-1.5 text-[11px] font-bold tracking-wide text-slate-500 uppercase">
                                Ke Sub Lokasi Kerja {location?.name}
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {data.sub_locations.map((s) => (
                                    <button
                                        key={s.id}
                                        onClick={() => onSubLocation(s)}
                                        disabled={busy}
                                        title="Menjabat jabatan yang sesuai penempatannya di Sub Lokasi ini (dibuat bila belum ada)"
                                        className="flex items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-800 hover:bg-emerald-100 disabled:opacity-50"
                                    >
                                        <MapPinned size={12} />
                                        {s.name}
                                    </button>
                                ))}
                            </div>
                        </section>
                    )}

                    {data && (
                        <section>
                            <p className="mb-1.5 text-[11px] font-bold tracking-wide text-slate-500 uppercase">Ke jabatan ({positions.length})</p>
                            <div className="space-y-1">
                                {positions.map((j) => {
                                    const full = j.single_holder && j.holders > 0;
                                    return (
                                        <div key={j.id} className="flex items-center justify-between gap-2 rounded-xl px-3 py-2 hover:bg-slate-50">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-bold text-slate-700">{j.name}</p>
                                                <p className="truncate text-[11px] text-slate-400">
                                                    {[
                                                        j.job_level,
                                                        j.unit_label,
                                                        j.sub_location ? `Sub Lokasi ${j.sub_location}` : null,
                                                        j.holders ? `${j.holders} orang` : 'Vacant',
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <button
                                                onClick={() => onPosition(j)}
                                                disabled={busy || full}
                                                title={full ? 'Sudah terisi (maks. 1 orang)' : undefined}
                                                className="shrink-0 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-brand-700 disabled:opacity-40"
                                            >
                                                Pindahkan
                                            </button>
                                        </div>
                                    );
                                })}
                                {positions.length === 0 && (
                                    <p className="px-3 py-2 text-sm text-slate-400">Tidak ada jabatan yang cocok di lokasi ini.</p>
                                )}
                            </div>
                        </section>
                    )}
                </div>
            </div>
        </div>
    );
}
