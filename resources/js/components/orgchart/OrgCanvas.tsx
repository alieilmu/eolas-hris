import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useDroppable } from '@dnd-kit/core';
import { Maximize2, Minus, Plus } from 'lucide-react';
import { CARD_WIDTH, connectorPath, layoutTree, type OrgNode, type PositionedNode } from '@/lib/orgTree';

interface Props {
    roots: OrgNode[];
    /** Height of each card (expanded cards are taller). */
    heightOf: (n: OrgNode) => number;
    /** Rows by SubGroup layer, with a band per layer. */
    byLayer?: boolean;
    renderNode: (p: PositionedNode) => ReactNode;
    /** Scrolls to and highlights this card (e.g. "j12"). */
    focusKey?: string | null;
    /** In the plan, dropping a position on the empty canvas takes it from its superior. */
    rootDropId?: string;
    empty?: ReactNode;
}

/** A zoomable chart: cards placed by layoutTree(), joined by lines. */
export function OrgCanvas({ roots, heightOf, byLayer = false, renderNode, focusKey, rootDropId, empty }: Props) {
    const [scale, setScale] = useState(1);
    const scroller = useRef<HTMLDivElement>(null);
    const layout = useMemo(() => layoutTree(roots, { heightOf, byLayer }), [roots, heightOf, byLayer]);
    const byKey = useMemo(() => new Map(layout.nodes.map((n) => [n.node.key, n])), [layout]);
    const rootDrop = useDroppable({ id: rootDropId ?? 'canvas-root', data: { kind: 'root' }, disabled: !rootDropId });

    const zoom = (next: number) => setScale(Math.min(1.4, Math.max(0.3, Math.round(next * 100) / 100)));
    const fit = () => scroller.current && zoom(Math.min(1, (scroller.current.clientWidth - 32) / layout.width));

    useEffect(() => {
        if (!focusKey) return;
        const frame = requestAnimationFrame(() =>
            document.querySelector(`[data-node-key="${focusKey}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' }),
        );
        return () => cancelAnimationFrame(frame);
    }, [focusKey, layout]);

    const controlButton = 'rounded-lg p-1.5 text-slate-500 hover:bg-slate-100';

    return (
        <div className="relative min-h-0 flex-1">
            <div
                ref={(el) => {
                    scroller.current = el;
                    rootDrop.setNodeRef(el);
                }}
                className={`absolute inset-0 overflow-auto ${rootDrop.isOver ? 'bg-accent-50/60' : ''}`}
            >
                {layout.nodes.length === 0 ? (
                    empty
                ) : (
                    <div style={{ width: layout.width * scale, height: layout.height * scale, margin: '0 auto', position: 'relative' }}>
                        <div
                            style={{
                                width: layout.width,
                                height: layout.height,
                                transform: `scale(${scale})`,
                                transformOrigin: 'top left',
                                position: 'absolute',
                            }}
                        >
                            <svg width={layout.width} height={layout.height} className="pointer-events-none absolute inset-0">
                                {layout.bands.map((b, i) => (
                                    <g key={b.layer}>
                                        <rect
                                            x={0}
                                            y={b.top}
                                            width={layout.width}
                                            height={b.bottom - b.top}
                                            fill={i % 2 ? 'var(--color-slate-100)' : 'var(--color-brand-50)'}
                                            opacity={0.7}
                                        />
                                        <line
                                            x1={0}
                                            x2={layout.width}
                                            y1={b.bottom}
                                            y2={b.bottom}
                                            stroke="var(--color-brand-200)"
                                            strokeDasharray="6 6"
                                        />
                                        <text x={20} y={b.top + 30} fontSize={15} fontWeight={800} fill="var(--color-brand-700)">
                                            LAYER {b.layer}
                                        </text>
                                        {b.jobLevels.slice(0, 4).map((g, j) => (
                                            <text key={g} x={20} y={b.top + 50 + j * 15} fontSize={10.5} fill="var(--color-slate-500)">
                                                {g.length > 20 ? `${g.slice(0, 19)}…` : g}
                                            </text>
                                        ))}
                                    </g>
                                ))}
                                {layout.nodes.map((n) => {
                                    const parent = n.parentKey ? byKey.get(n.parentKey) : null;
                                    return parent ? (
                                        <path
                                            key={n.node.key}
                                            d={connectorPath(parent, n)}
                                            fill="none"
                                            stroke="var(--color-brand-200)"
                                            strokeWidth={1.5}
                                        />
                                    ) : null;
                                })}
                            </svg>
                            {layout.nodes.map((n) => (
                                <div
                                    key={n.node.key}
                                    data-node-key={n.node.key}
                                    style={{ position: 'absolute', left: n.x, top: n.y, width: CARD_WIDTH, height: n.height }}
                                    className={focusKey === n.node.key ? 'rounded-2xl ring-4 ring-accent-400 ring-offset-2' : ''}
                                >
                                    {renderNode(n)}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div className="absolute right-4 bottom-4 flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                <button onClick={() => zoom(scale - 0.1)} title="Perkecil" className={controlButton}>
                    <Minus size={15} />
                </button>
                <button
                    onClick={() => zoom(1)}
                    title="Ukuran asli"
                    className="w-12 rounded-lg py-1 text-xs font-bold text-slate-600 tabular-nums hover:bg-slate-100"
                >
                    {Math.round(scale * 100)}%
                </button>
                <button onClick={() => zoom(scale + 0.1)} title="Perbesar" className={controlButton}>
                    <Plus size={15} />
                </button>
                <button onClick={fit} title="Muat selebar layar" className={controlButton}>
                    <Maximize2 size={15} />
                </button>
            </div>
        </div>
    );
}
