/**
 * The organisation of one work location as the server sends it (App\Support\Org\SitePlan):
 * positions by reporting line, Sub Lokasi boxes, and — at Head Office — each site's
 * Project Manager card. Plus a top-down layout for drawing it.
 */

export interface Holder {
    id: number;
    employee_number: string;
    name: string;
}

export interface JabatanData {
    kind: 'position';
    key: string;
    id: number;
    name: string;
    job_level_id: number | null;
    job_level: string | null;
    layer: number | null;
    source: string | null;
    work_location_id: number;
    sub_location_id: number | null;
    parent_position_id: number | null;
    /** "section:5" or "none". */
    unit: string;
    /** e.g. "Section WORKSHOP · MAINTENANCE"; null when not in a unit. */
    unit_label: string | null;
    department_id: number | null;
    section_id: number | null;
    single_holder: boolean;
    holders: Holder[];
}

export interface JabatanNode extends JabatanData {
    children: OrgNode[];
}

export interface SubLocationNode {
    kind: 'sub_location';
    key: string;
    id: number;
    name: string;
    parent_position_id: number | null;
    children: OrgNode[];
}

export interface SiteNode {
    kind: 'site';
    key: string;
    work_location: { id: number; name: string; code: string | null };
    /** The site's Project Manager; null while the site is not set up. */
    position: JabatanData | null;
    position_count: number;
    children: OrgNode[];
}

export type OrgNode = JabatanNode | SubLocationNode | SiteNode;

export interface LocationTab {
    id: number;
    name: string;
    code: string | null;
    type: string;
    position_count: number;
    people_count: number;
    waiting_count: number;
    needs_setup: boolean;
}

export const HEAD_OFFICE = 'head_office';

/** An organisation unit in a picker (App\Support\Org\OrgChart::unitOptions). */
export interface UnitOption {
    value: string;
    /** Indented under its parent unit, e.g. "   Section · Workshop". */
    label: string;
    /** Without indentation, e.g. "Section · Workshop (Maintenance)". */
    display: string;
    level: 'directorate' | 'division' | 'department' | 'section';
    name: string;
}

// --- walking -----------------------------------------------------------------------------------

export function eachNode(nodes: OrgNode[], visit: (n: OrgNode, parent: OrgNode | null) => void, parent: OrgNode | null = null): void {
    nodes.forEach((n) => {
        visit(n, parent);
        eachNode(n.children, visit, n);
    });
}

export function findJabatan(nodes: OrgNode[], id: number): JabatanNode | null {
    let found: JabatanNode | null = null;
    eachNode(nodes, (n) => {
        if (n.kind === 'position' && n.id === id) found = n;
    });
    return found;
}

/** True when `candidate` is the position `ancestorId` itself or anywhere below it. */
export function isWithin(nodes: OrgNode[], ancestorId: number, candidate: number): boolean {
    const start = findJabatan(nodes, ancestorId);
    if (!start) return false;
    let hit = false;
    eachNode([start], (n) => {
        if (n.kind === 'position' && n.id === candidate) hit = true;
    });
    return hit;
}

// --- layout ------------------------------------------------------------------------------------

export interface PositionedNode {
    node: OrgNode;
    /** Left and top of the card. */
    x: number;
    y: number;
    height: number;
    parentKey: string | null;
    /** Part of a vertical list under its parent (connector runs down its left side). */
    stacked: boolean;
}

/** A horizontal band of the chart holding the positions of one SubGroup layer. */
export interface LayerBand {
    layer: number;
    top: number;
    bottom: number;
    /** The SubGroups shown in the band. */
    jobLevels: string[];
}

export interface TreeLayout {
    nodes: PositionedNode[];
    width: number;
    height: number;
    bands: LayerBand[];
}

export interface LayoutOptions {
    heightOf: (n: OrgNode) => number;
    /** Rows by SubGroup layer (6 at the top), separated into bands. */
    byLayer?: boolean;
}

export const CARD_WIDTH = 232;
const H_GAP = 28;
const V_GAP = 64;
const STACK_INDENT = 30;
const STACK_GAP = 12;
/** Leaves are listed downwards from this many on, so a wide team does not stretch the chart. */
const STACK_MIN = 3;
const PADDING = 40;
/** Room on the left for the band labels. */
const BAND_GUTTER = 120;
const BAND_GAP = 44;

/** The layer a card is drawn in: a position's SubGroup layer; a site by its Project Manager. */
export function layerOf(n: OrgNode): number | null {
    if (n.kind === 'position') return n.layer;
    if (n.kind === 'site') return n.position?.layer ?? 4;
    return null;
}

/**
 * Top-down layout: each parent centred over its children, subtrees side by side; a parent whose
 * children are all leaves (and several) lists them downwards instead. With byLayer, every card sits
 * in the band of its layer (a position under one of the same layer goes a row lower in that band).
 */
export function layoutTree(roots: OrgNode[], { heightOf, byLayer = false }: LayoutOptions): TreeLayout {
    const nodes: PositionedNode[] = [];
    const left0 = byLayer ? PADDING + BAND_GUTTER : PADDING;
    let bottom = 0;

    const place = (n: OrgNode, left: number, top: number, parentKey: string | null, stacked: boolean): number => {
        const height = heightOf(n);
        const self: PositionedNode = { node: n, x: left, y: top, height, parentKey, stacked };
        nodes.push(self);
        bottom = Math.max(bottom, top + height);

        const kids = n.children;
        if (!kids.length) return CARD_WIDTH;

        if (!byLayer && kids.length >= STACK_MIN && kids.every((k) => !k.children.length)) {
            let y = top + height + 36;
            kids.forEach((k) => {
                place(k, left + STACK_INDENT, y, n.key, true);
                y += heightOf(k) + STACK_GAP;
            });
            return CARD_WIDTH + STACK_INDENT;
        }

        let cursor = left;
        kids.forEach((k) => {
            cursor += place(k, cursor, top + height + V_GAP, n.key, false) + H_GAP;
        });
        const width = Math.max(CARD_WIDTH, cursor - H_GAP - left);
        self.x = left + (width - CARD_WIDTH) / 2;
        return width;
    };

    let cursor = left0;
    roots.forEach((r) => {
        cursor += place(r, cursor, PADDING, null, false) + H_GAP * 2;
    });
    const width = Math.max(cursor - H_GAP * 2 + PADDING, CARD_WIDTH + left0 + PADDING);

    if (!byLayer) return { nodes, width, height: bottom + PADDING, bands: [] };

    // --- rows by layer -------------------------------------------------------------------------
    const byKey = new Map(nodes.map((n) => [n.node.key, n]));
    const parentOf = (n: PositionedNode) => (n.parentKey ? byKey.get(n.parentKey) : undefined);
    // A Sub Lokasi box sits in the layer of the position it hangs under (or of its highest content).
    const effective = new Map<string, number>();
    const layerFor = (n: PositionedNode): number => {
        const known = effective.get(n.node.key);
        if (known !== undefined) return known;
        let layer = layerOf(n.node);
        if (layer === null) {
            const parent = parentOf(n);
            layer = parent ? layerFor(parent) : Math.max(1, ...n.node.children.map((c) => layerOf(c) ?? 1));
        }
        effective.set(n.node.key, layer);
        return layer;
    };
    nodes.forEach(layerFor);

    const layers = [...new Set(nodes.map((n) => effective.get(n.node.key)!))].sort((a, b) => b - a);
    const row = new Map<string, number>();
    const bandRows: { layer: number; first: number; last: number }[] = [];
    let next = 0;
    layers.forEach((layer) => {
        const first = next;
        let last = first;
        // nodes are in pre-order: a parent always comes before its children.
        nodes
            .filter((n) => effective.get(n.node.key) === layer)
            .forEach((n) => {
                const parent = parentOf(n);
                const r = Math.max(first, parent ? (row.get(parent.node.key) ?? -1) + 1 : first);
                row.set(n.node.key, r);
                last = Math.max(last, r);
            });
        bandRows.push({ layer, first, last });
        next = last + 1;
    });

    const rowHeight: number[] = [];
    nodes.forEach((n) => {
        const r = row.get(n.node.key)!;
        rowHeight[r] = Math.max(rowHeight[r] ?? 0, n.height);
    });
    const rowTop: number[] = [];
    let y = PADDING + BAND_GAP / 2;
    bandRows.forEach((b, i) => {
        if (i > 0) y += BAND_GAP;
        for (let r = b.first; r <= b.last; r++) {
            rowTop[r] = y;
            y += (rowHeight[r] ?? 0) + (r < b.last ? V_GAP : 0);
        }
    });
    nodes.forEach((n) => (n.y = rowTop[row.get(n.node.key)!]));

    const bands: LayerBand[] = bandRows.map((b) => ({
        layer: b.layer,
        top: rowTop[b.first] - BAND_GAP / 2,
        bottom: rowTop[b.last] + (rowHeight[b.last] ?? 0) + BAND_GAP / 2,
        jobLevels: [
            ...new Set(
                nodes
                    .filter((n) => effective.get(n.node.key) === b.layer && n.node.kind === 'position')
                    .map((n) => (n.node as JabatanNode).job_level)
                    .filter((g): g is string => !!g),
            ),
        ],
    }));

    return { nodes, width, height: y + BAND_GAP / 2 + PADDING, bands };
}

/** SVG path from a parent card to a child card. */
export function connectorPath(parent: PositionedNode, child: PositionedNode): string {
    const parentBottom = parent.y + parent.height - 4;
    if (child.stacked) {
        const spine = parent.x + STACK_INDENT / 2;
        return `M ${spine} ${parentBottom} V ${child.y + child.height / 2} H ${child.x}`;
    }
    const px = parent.x + CARD_WIDTH / 2;
    const cx = child.x + CARD_WIDTH / 2;
    const midY = child.y - Math.min(V_GAP, BAND_GAP) / 2;
    return `M ${px} ${parentBottom} V ${midY} H ${cx} V ${child.y + 4}`;
}
