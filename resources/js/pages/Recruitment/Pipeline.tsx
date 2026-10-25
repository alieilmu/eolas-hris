import { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    TouchSensor,
    pointerWithin,
    rectIntersection,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type CollisionDetection,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import {
    BadgeCheck,
    ChevronLeft,
    ChevronRight,
    Clock,
    FileText,
    MessageSquareText,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { AttendanceHeader, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { CandidateDetailModal } from '@/components/recruitment/CandidateDetailModal';
import { CandidateFormDialog } from '@/components/recruitment/CandidateFormDialog';
import { StageDialog } from '@/components/recruitment/StageDialog';
import { hariLabel, tint, type Stage, type StageCard } from '@/lib/recruitment';

interface Props {
    stages: Stage[];
    filters: { q: string; position_title: string; rejected: boolean };
    position_title_options: string[];
    colors: string[];
    quantity: { active: number; rejected: number };
    can_edit: boolean;
    can_employee: boolean;
}

type DragData = { type: 'card'; id: number; stageId: number } | { type: 'stage'; stageId: number };

const visit = { preserveScroll: true, preserveState: true } as const;

/** A card over another card wins over the column it sits in; outside any, the nearest. */
const collision: CollisionDetection = (args) => {
    const hits = pointerWithin(args);
    const cards = hits.filter((h) => (h.data?.droppableContainer.data.current as DragData | undefined)?.type === 'card');
    if (cards.length) return cards;
    return hits.length ? hits : rectIntersection(args);
};

/** Pipeline Kandidat: one column per stage, candidates dragged from stage to stage. */
export default function Pipeline({ stages, filters, position_title_options, colors, quantity, can_edit, can_employee }: Props) {
    const [board, setBoard] = useState(stages);
    const [dragging, setDragging] = useState<StageCard | null>(null);
    const [detail, setDetail] = useState<number | null>(() => Number(new URLSearchParams(window.location.search).get('view')) || null);
    const [dialog, setDialog] = useState<{ kind: 'stage'; stage: Stage | null } | { kind: 'candidate'; stageId?: number } | null>(null);
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    useEffect(() => setBoard(stages), [stages]);

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 6 } }),
    );

    const apply = (next: Partial<Props['filters']>) => {
        const m = { ...filters, q: search, ...next };
        const query: Record<string, string> = {};
        if (m.q.trim()) query.q = m.q.trim();
        if (m.position_title) query.position_title = m.position_title;
        if (m.rejected) query.rejected = '1';
        router.get('/recruitment/pipeline', query, { ...visit, replace: true });
    };
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => apply({ q: search }), 350);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const onDragStart = (e: DragStartEvent) => {
        const data = e.active.data.current as DragData;
        if (data.type === 'card') setDragging(board.flatMap((s) => s.candidates).find((c) => c.id === data.id) ?? null);
    };

    const onDragEnd = (e: DragEndEvent) => {
        setDragging(null);
        const active = e.active.data.current as DragData | undefined;
        const over = e.over?.data.current as DragData | undefined;
        if (!active || active.type !== 'card' || !over || !e.over) return;
        const card = board.flatMap((s) => s.candidates).find((c) => c.id === active.id);
        if (!card) return;

        const without = board.map((s) => ({ ...s, candidates: s.candidates.filter((c) => c.id !== card.id) }));
        const target = without.find((s) => s.id === over.stageId);
        if (!target) return;
        let index = target.candidates.length;
        if (over.type === 'card' && over.id !== card.id) {
            index = target.candidates.findIndex((c) => c.id === over.id);
            // Below the middle of the card it is dropped on: after it.
            const dropped = e.active.rect.current.translated;
            if (dropped && dropped.top + dropped.height / 2 > e.over.rect.top + e.over.rect.height / 2) index += 1;
        } else if (over.type === 'card') {
            return; // dropped on itself
        }
        const list = [...target.candidates];
        list.splice(index, 0, card);
        const sameSpot =
            active.stageId === target.id &&
            board
                .find((s) => s.id === target.id)
                ?.candidates.map((c) => c.id)
                .join() === list.map((c) => c.id).join();
        if (sameSpot) return;

        const next = without.map((s) => (s.id === target.id ? { ...s, candidates: list } : s));
        setBoard(next);
        router.post(
            '/recruitment/candidate/move',
            { candidate_id: card.id, stage_id: target.id, ordered_ids: list.map((c) => c.id) },
            { ...visit, only: ['stages', 'quantity'], onError: () => setBoard(stages) },
        );
    };

    const total = board.reduce((n, s) => n + s.candidates.length, 0);

    return (
        <>
            <Head title="Pipeline Kandidat" />
            <AttendanceHeader
                title="Pipeline Kandidat"
                subtitle={`${quantity.active} kandidat dalam proses · ${quantity.rejected} tidak dilanjutkan · geser kartu untuk memindah tahap`}
            >
                {can_edit && (
                    <>
                        <button onClick={() => setDialog({ kind: 'stage', stage: null })} className={secondaryButton}>
                            <Plus size={16} /> Tahap
                        </button>
                        <button onClick={() => setDialog({ kind: 'candidate' })} className={primaryButton}>
                            <UserPlus size={16} /> Kandidat
                        </button>
                    </>
                )}
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
                    <div className="relative">
                        <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama / email / posisi"
                            className={`${smallInput} w-60 pl-8`}
                        />
                    </div>
                    <select value={filters.position_title} onChange={(e) => apply({ position_title: e.target.value })} className={smallInput} aria-label="Posisi">
                        <option value="">Semua posisi</option>
                        {position_title_options.map((p) => (
                            <option key={p} value={p}>
                                {p}
                            </option>
                        ))}
                    </select>
                    <label className="flex items-center gap-2 text-xs font-bold text-slate-500">
                        <input
                            type="checkbox"
                            checked={filters.rejected}
                            onChange={(e) => apply({ rejected: e.target.checked })}
                            className="accent-brand-600"
                        />
                        Tampilkan yang tidak dilanjutkan
                    </label>
                    <span className="ml-auto text-xs text-slate-400">{total} kartu tampil</span>
                </div>

                <DndContext
                    sensors={sensors}
                    collisionDetection={collision}
                    onDragStart={onDragStart}
                    onDragEnd={onDragEnd}
                    onDragCancel={() => setDragging(null)}
                >
                    <div className="flex min-h-0 flex-1 gap-4 overflow-x-auto p-4 sm:p-6">
                        {board.map((stage, i) => (
                            <Column
                                key={stage.id}
                                stage={stage}
                                first={i === 0}
                                last={i === board.length - 1}
                                canEdit={can_edit}
                                onOpen={setDetail}
                                onEdit={() => setDialog({ kind: 'stage', stage })}
                                onAdd={() => setDialog({ kind: 'candidate', stageId: stage.id })}
                            />
                        ))}
                        {board.length === 0 && <p className="m-auto text-sm text-slate-400">Belum ada tahap. Tambahkan lewat tombol Tahap.</p>}
                    </div>
                    <DragOverlay dropAnimation={null}>{dragging && <CardBody card={dragging} color="#64748b" lifted />}</DragOverlay>
                </DndContext>
            </div>

            {detail !== null && (
                <CandidateDetailModal
                    candidateId={detail}
                    stages={board}
                    positionTitleOptions={position_title_options}
                    canEdit={can_edit}
                    canEmployee={can_employee}
                    onClose={() => setDetail(null)}
                />
            )}
            {dialog?.kind === 'stage' && <StageDialog stage={dialog.stage} colors={colors} onClose={() => setDialog(null)} />}
            {dialog?.kind === 'candidate' && (
                <CandidateFormDialog stages={board} stageId={dialog.stageId} positionTitleOptions={position_title_options} onClose={() => setDialog(null)} />
            )}
        </>
    );
}

function Column({
    stage,
    first,
    last,
    canEdit,
    onOpen,
    onEdit,
    onAdd,
}: {
    stage: Stage;
    first: boolean;
    last: boolean;
    canEdit: boolean;
    onOpen: (id: number) => void;
    onEdit: () => void;
    onAdd: () => void;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `s${stage.id}`, data: { type: 'stage', stageId: stage.id } satisfies DragData });
    const [menu, setMenu] = useState(false);

    const move = (direction: 'left' | 'right') => {
        setMenu(false);
        router.post(`/recruitment/stage/${stage.id}/move`, { direction }, visit);
    };
    const remove = () => {
        setMenu(false);
        if (confirm(`Hapus tahap ${stage.title}?`)) router.delete(`/recruitment/stage/${stage.id}`, visit);
    };

    return (
        <section
            className={`flex w-72 shrink-0 flex-col rounded-2xl border bg-white/60 transition-colors ${isOver ? 'border-brand-300 bg-brand-50/60' : 'border-slate-200'}`}
            aria-label={`Tahap ${stage.title}`}
        >
            <header
                className="relative flex items-center gap-2 border-b border-slate-100 px-3 py-2.5"
                style={{ borderTop: `3px solid ${stage.color}`, borderRadius: '1rem 1rem 0 0' }}
            >
                <h2 className="min-w-0 flex-1 truncate text-sm font-bold text-slate-700">{stage.title}</h2>
                <span className="rounded-full px-2 py-0.5 text-[11px] font-bold" style={tint(stage.color)}>
                    {stage.candidates.length}
                </span>
                {canEdit && (
                    <button
                        onClick={() => setMenu(!menu)}
                        aria-label={`Menu tahap ${stage.title}`}
                        className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    >
                        <MoreHorizontal size={16} />
                    </button>
                )}
                {menu && (
                    <div
                        className="absolute top-10 right-2 z-20 w-44 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-xl"
                        onMouseLeave={() => setMenu(false)}
                    >
                        <MenuItem icon={Pencil} label="Ubah nama & warna" onClick={() => (setMenu(false), onEdit())} />
                        <MenuItem icon={ChevronLeft} label="Geser ke kiri" onClick={() => move('left')} disabled={first} />
                        <MenuItem icon={ChevronRight} label="Geser ke kanan" onClick={() => move('right')} disabled={last} />
                        <MenuItem icon={Trash2} label="Hapus tahap" onClick={remove} danger disabled={stage.candidates.length > 0} />
                    </div>
                )}
            </header>
            <div ref={setNodeRef} className="flex min-h-24 flex-1 flex-col gap-2 overflow-y-auto p-2">
                {stage.candidates.map((c) => (
                    <Card key={c.id} card={c} stageId={stage.id} color={stage.color} canDrag={canEdit && !c.rejected && !c.employee_number} onOpen={onOpen} />
                ))}
                {stage.candidates.length === 0 && <p className="py-6 text-center text-xs text-slate-300">Kosong</p>}
            </div>
            {canEdit && (
                <button
                    onClick={onAdd}
                    className="m-2 mt-0 flex items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 py-2 text-xs font-bold text-slate-400 hover:border-brand-300 hover:bg-white hover:text-brand-600"
                >
                    <Plus size={14} /> Tambah kandidat
                </button>
            )}
        </section>
    );
}

function MenuItem({
    icon: Icon,
    label,
    onClick,
    danger,
    disabled,
}: {
    icon: typeof Pencil;
    label: string;
    onClick: () => void;
    danger?: boolean;
    disabled?: boolean;
}) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`flex w-full items-center gap-2 px-3 py-2 text-left disabled:opacity-35 ${danger ? 'text-rose-600 hover:bg-rose-50' : 'text-slate-600 hover:bg-slate-50'}`}
        >
            <Icon size={14} /> {label}
        </button>
    );
}

function Card({
    card,
    stageId,
    color,
    canDrag,
    onOpen,
}: {
    card: StageCard;
    stageId: number;
    color: string;
    canDrag: boolean;
    onOpen: (id: number) => void;
}) {
    const data = { type: 'card', id: card.id, stageId } satisfies DragData;
    const drag = useDraggable({ id: `c${card.id}`, data, disabled: !canDrag });
    const drop = useDroppable({ id: `d${card.id}`, data });

    return (
        <div
            ref={(el) => {
                drag.setNodeRef(el);
                drop.setNodeRef(el);
            }}
            {...drag.attributes}
            {...drag.listeners}
            onClick={() => onOpen(card.id)}
            onKeyDown={(e) => e.key === 'Enter' && onOpen(card.id)}
            role="button"
            tabIndex={0}
            aria-label={`${card.name}, ${card.position}`}
            className={`relative rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${drag.isDragging ? 'opacity-30' : ''} ${
                drop.isOver && !drag.isDragging
                    ? 'before:absolute before:-top-1.5 before:right-2 before:left-2 before:h-0.5 before:rounded-full before:bg-brand-500'
                    : ''
            }`}
        >
            <CardBody card={card} color={color} draggable={canDrag} />
        </div>
    );
}

function CardBody({ card, color, lifted, draggable }: { card: StageCard; color: string; lifted?: boolean; draggable?: boolean }) {
    return (
        <div
            className={`rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition-shadow hover:border-brand-200 hover:shadow-md ${
                lifted ? 'w-68 rotate-2 shadow-2xl' : ''
            } ${draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'} ${card.rejected ? 'opacity-60' : ''}`}
            style={{ borderLeft: `3px solid ${color}` }}
        >
            <p className="truncate text-sm font-bold text-slate-800">{card.name}</p>
            <p className="truncate text-xs text-slate-500">{card.position}</p>
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                <span className="flex items-center gap-1" title="Lama di tahap ini">
                    <Clock size={12} /> {hariLabel(card.days_in_stage)}
                </span>
                {card.notes_count > 0 && (
                    <span className="flex items-center gap-1" title="Catatan">
                        <MessageSquareText size={12} /> {card.notes_count}
                    </span>
                )}
                {card.has_cv && (
                    <span className="flex items-center gap-1" title="Ada CV">
                        <FileText size={12} /> CV
                    </span>
                )}
                {card.employee_number && (
                    <span className="flex items-center gap-1 rounded-full bg-emerald-50 px-1.5 font-bold text-emerald-700">
                        <BadgeCheck size={12} /> {card.employee_number}
                    </span>
                )}
                {card.rejected && <span className="rounded-full bg-rose-50 px-1.5 font-bold text-rose-600">Tidak dilanjutkan</span>}
            </div>
        </div>
    );
}
