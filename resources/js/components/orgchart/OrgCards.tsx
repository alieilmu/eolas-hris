import { Link } from '@inertiajs/react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { Briefcase, ExternalLink, GripVertical, Layers, MapPin, MapPinned, Trash2, User, Users, X } from 'lucide-react';
import type { Holder, JabatanData, SiteNode, SubLocationNode } from '@/lib/orgTree';

/** Colour of the strip on a card, by SubGroup layer (6 = most senior). */
const LAYER_STRIP: Record<number, string> = {
    6: 'bg-brand-950',
    5: 'bg-brand-800',
    4: 'bg-brand-600',
    3: 'bg-brand-400',
    2: 'bg-accent-400',
    1: 'bg-slate-300',
};

export interface DragData {
    type: 'position' | 'sub_location' | 'site' | 'holder' | 'person' | 'palette';
    [key: string]: unknown;
}

export interface DropData {
    type: 'position' | 'sub_location' | 'people-panel' | 'root';
    [key: string]: unknown;
}

function useCardDnd(key: string, drag: DragData | null, drop: DropData | null) {
    const draggable = useDraggable({ id: `drag:${key}`, data: drag ?? undefined, disabled: !drag });
    const droppable = useDroppable({ id: `drop:${key}`, data: drop ?? undefined, disabled: !drop });
    return {
        ref: (el: HTMLElement | null) => {
            draggable.setNodeRef(el);
            droppable.setNodeRef(el);
        },
        handle: drag ? { ...draggable.attributes, ...draggable.listeners } : {},
        isDragging: draggable.isDragging,
        isOver: droppable.isOver && !draggable.isDragging,
    };
}

/** Card height: the base card, or (people mode, expanded) one line per holder. */
export const CARD_HEIGHT = 112;
const HOLDER_LINE = 18;

export function positionCardHeight(j: JabatanData, expanded: boolean): number {
    return expanded && j.holders.length ? CARD_HEIGHT - 22 + j.holders.length * HOLDER_LINE + 10 : CARD_HEIGHT;
}

interface PositionCardProps {
    position: JabatanData;
    /** "plan": structure only; "people": who holds it. */
    mode: 'plan' | 'people';
    draggable?: boolean;
    droppable?: boolean;
    /** People mode: list the holders on the card (their names can be dragged to another card). */
    expanded?: boolean;
    /** People mode: a click opens the holders. */
    onOpen?: () => void;
    onDelete?: () => void;
}

export function PositionCard({ position: j, mode, draggable, droppable, expanded, onOpen, onDelete }: PositionCardProps) {
    const dnd = useCardDnd(j.key, draggable ? { type: 'position', id: j.id, name: j.name } : null, droppable ? { type: 'position', position: j } : null);
    const count = j.holders.length;

    return (
        <div
            ref={dnd.ref}
            onClick={onOpen}
            title={onOpen ? 'Klik untuk melihat karyawannya' : undefined}
            className={`group/card relative flex h-full overflow-hidden rounded-2xl border bg-white shadow-sm transition-shadow ${
                dnd.isOver ? 'border-accent-400 ring-4 ring-accent-300' : 'border-slate-200'
            } ${dnd.isDragging ? 'opacity-40' : ''} ${onOpen ? 'cursor-pointer hover:border-brand-300 hover:shadow-md' : ''}`}
        >
            <div className={`w-1.5 shrink-0 ${LAYER_STRIP[j.layer ?? 1] ?? 'bg-slate-300'}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-1 p-2.5">
                <div className="flex items-start gap-1.5" {...dnd.handle}>
                    {draggable ? (
                        <GripVertical size={13} className="mt-0.5 shrink-0 cursor-grab text-slate-300" />
                    ) : (
                        <Briefcase size={13} className="mt-0.5 shrink-0 text-brand-400" />
                    )}
                    <p className={`line-clamp-2 min-w-0 flex-1 text-[13px] leading-tight font-bold text-slate-800 ${draggable ? 'cursor-grab' : ''}`}>
                        {j.name}
                    </p>
                </div>
                <div className="flex min-w-0 flex-wrap items-center gap-1">
                    {j.job_level && (
                        <span className="inline-flex max-w-full items-center gap-1 truncate rounded-md bg-brand-50 px-1.5 py-0.5 text-[9px] font-bold tracking-wide text-brand-700 uppercase">
                            <Layers size={9} className="shrink-0" />
                            {j.job_level}
                        </span>
                    )}
                </div>
                {j.unit_label && <p className="truncate text-[10px] text-slate-400">{j.unit_label}</p>}

                <div className="mt-auto border-t border-slate-100 pt-1 text-[11px]">
                    {mode === 'people' && expanded && count > 0 ? (
                        <div onClick={(e) => e.stopPropagation()}>
                            {j.holders.map((h) => (
                                <HolderChip key={h.id} holder={h} positionId={j.id} draggable={!!droppable} />
                            ))}
                        </div>
                    ) : count === 0 ? (
                        <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">Vacant</span>
                    ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                            <Users size={11} />
                            {count} orang
                            {j.single_holder && count > 1 && <span className="text-amber-600">• melebihi 1</span>}
                        </span>
                    )}
                </div>
            </div>

            {onDelete && (
                <button
                    onPointerDown={(e) => e.stopPropagation()}
                    onClick={onDelete}
                    title="Hapus jabatan dari plan"
                    className="absolute top-1.5 right-1.5 flex h-6 w-6 items-center justify-center rounded-full border border-rose-200 bg-white text-rose-500 opacity-0 shadow transition-opacity group-hover/card:opacity-100 hover:bg-rose-50"
                >
                    <Trash2 size={12} />
                </button>
            )}
        </div>
    );
}

function HolderChip({ holder, positionId, draggable }: { holder: Holder; positionId: number; draggable: boolean }) {
    const drag = useDraggable({
        id: `holder:${holder.id}`,
        data: { type: 'holder', employee: holder, from: positionId } satisfies DragData,
        disabled: !draggable,
    });
    return (
        <div
            ref={drag.setNodeRef}
            {...(draggable ? { ...drag.attributes, ...drag.listeners } : {})}
            style={{ height: HOLDER_LINE }}
            className={`flex min-w-0 items-center gap-1 font-bold text-slate-700 ${draggable ? 'cursor-grab rounded hover:bg-brand-50' : ''} ${
                drag.isDragging ? 'opacity-40' : ''
            }`}
            title={draggable ? `${holder.name} (NRP ${holder.employee_number}) — geser ke jabatan lain untuk mutasi` : holder.name}
        >
            <User size={11} className="shrink-0 text-emerald-500" />
            <span className="truncate">{holder.name}</span>
        </div>
    );
}

interface BoxProps {
    box: SubLocationNode;
    draggable?: boolean;
    droppable?: boolean;
    /** People mode: how many work there. */
    peopleCount?: number;
    onRemove?: () => void;
}

/** A Sub Lokasi Kerja: its positions hang below it. */
export function SubLocationBox({ box, draggable, droppable, peopleCount, onRemove }: BoxProps) {
    const dnd = useCardDnd(
        box.key,
        draggable ? { type: 'sub_location', id: box.id, name: box.name } : null,
        droppable ? { type: 'sub_location', box } : null,
    );

    return (
        <div
            ref={dnd.ref}
            className={`group/card relative flex h-full flex-col justify-center gap-1 rounded-2xl border-2 border-dashed px-3 transition-shadow ${
                dnd.isOver ? 'border-accent-400 bg-accent-50 ring-4 ring-accent-300' : 'border-emerald-300 bg-emerald-50/70'
            } ${dnd.isDragging ? 'opacity-40' : ''}`}
            {...dnd.handle}
        >
            <span className="flex items-center gap-1.5 text-[10px] font-bold tracking-widest text-emerald-700 uppercase">
                <MapPinned size={11} />
                Sub Lokasi Kerja
            </span>
            <p className="line-clamp-2 text-sm leading-tight font-bold text-emerald-900">{box.name}</p>
            <p className="text-[10px] text-emerald-700/70">
                {peopleCount !== undefined
                    ? `${peopleCount} orang • lepas nama karyawan di sini untuk menugaskan`
                    : box.children.length
                      ? `${box.children.length} jabatan teratas`
                      : 'Belum ada jabatan'}
            </p>
            {onRemove && (
                <button
                    onPointerDown={(e) => e.stopPropagation()}
                    onClick={onRemove}
                    title="Keluarkan dari plan"
                    className="absolute top-1.5 right-1.5 flex h-6 w-6 items-center justify-center rounded-full border border-rose-200 bg-white text-rose-500 opacity-0 shadow transition-opacity group-hover/card:opacity-100 hover:bg-rose-50"
                >
                    <X size={12} />
                </button>
            )}
        </div>
    );
}

interface SiteProps {
    site: SiteNode;
    draggable?: boolean;
    /** Opens this site's own chart. */
    href: string;
}

/** Head Office view: a site, by its Project Manager. */
export function SiteCard({ site, draggable, href }: SiteProps) {
    const pm = site.position;
    const dnd = useCardDnd(site.key, draggable && pm ? { type: 'site', positionId: pm.id, name: site.work_location.name } : null, null);

    return (
        <div
            ref={dnd.ref}
            className={`relative flex h-full flex-col gap-1 rounded-2xl border border-brand-700 bg-brand-800 p-2.5 text-white shadow-sm ${dnd.isDragging ? 'opacity-40' : ''}`}
        >
            <div className="flex items-center justify-between gap-1" {...dnd.handle}>
                <span
                    className={`flex items-center gap-1.5 text-[10px] font-bold tracking-widest text-accent-300 uppercase ${draggable && pm ? 'cursor-grab' : ''}`}
                >
                    <MapPin size={11} />
                    Lokasi kerja
                </span>
                <Link href={href} onPointerDown={(e) => e.stopPropagation()} title="Buka bagan lokasi ini" className="text-white/60 hover:text-white">
                    <ExternalLink size={12} />
                </Link>
            </div>
            <p className="truncate text-sm leading-tight font-black">
                {site.work_location.name}
                {site.work_location.code && site.work_location.code !== site.work_location.name ? ` (${site.work_location.code})` : ''}
            </p>
            {pm ? (
                <>
                    <p className="truncate text-[11px] font-bold text-brand-100">{pm.name}</p>
                    <p className="mt-auto truncate text-[11px]">
                        {pm.holders.length ? (
                            <span className="flex items-center gap-1 font-bold">
                                <User size={11} className="text-emerald-300" />
                                {pm.holders.map((h) => h.name).join(', ')}
                            </span>
                        ) : (
                            <span className="rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] font-bold text-amber-200">Vacant</span>
                        )}
                    </p>
                </>
            ) : (
                <p className="mt-auto text-[11px] text-amber-200">Belum di-setup di Plan SO</p>
            )}
            <p className="text-[10px] text-brand-200">{site.position_count} jabatan</p>
        </div>
    );
}
