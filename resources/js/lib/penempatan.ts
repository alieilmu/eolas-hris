/**
 * Placement → position name, mirroring App\Support\Org\JabatanComposer so forms
 * can show the name before saving (the server has the final say):
 *   Section Head Site + Section PRODUKSI      → SECTION HEAD PRODUKSI
 *   Officer HO / Group Leader + HO + HSE      → OFFICER HSE (GROUP LEADER HSE at a site)
 *   Project Manager + RMTU                    → PROJECT MANAGER RMTU
 */

export type Sumber = 'bod' | 'divisi' | 'departemen' | 'section' | 'work_location' | 'bidang';

export interface PenempatanOptions {
    work_locations: { id: number; name: string; kode: string | null; type: string }[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    sub_groups: {
        id: number;
        nama: string;
        layer: number;
        sumber: Sumber;
        gelar: string | null;
        gelar_ho: string | null;
    }[];
    bods: { id: number; name: string }[];
    divisis: { id: number; name: string; bod_id: number }[];
    departemens: { id: number; name: string; divisi_id: number }[];
    sections: { id: number; name: string; departemen_id: number }[];
    bidang: string[];
    jabatans: { id: number; name: string; sub_group_id: number | null; work_location_id: number | null; holders: string[] }[];
}

/** Form state: ids as strings ('' = not chosen), like the selects that hold them. */
export interface Placement {
    work_location_id: string;
    sub_location_id: string;
    sub_group_id: string;
    bod_id: string;
    divisi_id: string;
    departemen_id: string;
    section_id: string;
    bidang: string;
}

export const emptyPlacement = (): Placement => ({
    work_location_id: '',
    sub_location_id: '',
    sub_group_id: '',
    bod_id: '',
    divisi_id: '',
    departemen_id: '',
    section_id: '',
    bidang: '',
});

/** From the server's placementOf() (numbers / nulls). */
export function placementFrom(raw: Partial<Record<keyof Placement, number | string | null>> | null | undefined): Placement {
    const p = emptyPlacement();
    if (!raw) return p;
    (Object.keys(p) as (keyof Placement)[]).forEach((k) => {
        const v = raw[k];
        p[k] = v === null || v === undefined ? '' : String(v);
    });
    return p;
}

export const HEAD_OFFICE = 'Head Office';

export const SINGLE_HOLDER_MIN_LAYER = 3;

export const SUMBER_LABEL: Record<Sumber, string> = {
    bod: 'BOD',
    divisi: 'Divisi',
    departemen: 'Departemen',
    section: 'Section',
    work_location: 'Work Location',
    bidang: 'Nama Jabatan (Section opsional)',
};

const join = (gelar: string | null | undefined, rest: string | null | undefined) => [gelar?.trim(), rest?.trim()].filter(Boolean).join(' ');

export interface Composed {
    /** Complete when there is no error. */
    name: string | null;
    /** "section:5" — where the position sits in the chart; "none" = placed in the chart (SO) later. */
    unit: string | null;
    error: string | null;
}

export function compose(p: Placement, o: PenempatanOptions): Composed {
    const none = (error: string): Composed => ({ name: null, unit: null, error });
    const location = o.work_locations.find((w) => String(w.id) === p.work_location_id);
    const sub = o.sub_groups.find((g) => String(g.id) === p.sub_group_id);
    if (!location) return none('Pilih Work Location.');
    if (!sub) return none('Pilih SubGroup.');

    const gelar = location.type === HEAD_OFFICE && sub.gelar_ho ? sub.gelar_ho : sub.gelar;
    const ok = (name: string, unit: string): Composed => ({ name, unit, error: null });

    switch (sub.sumber) {
        case 'bod': {
            const b = o.bods.find((x) => String(x.id) === p.bod_id);
            return b ? ok(join(gelar, b.name), `bod:${b.id}`) : none('Pilih BOD.');
        }
        case 'divisi': {
            const d = o.divisis.find((x) => String(x.id) === p.divisi_id);
            return d ? ok(join(gelar, d.name), `divisi:${d.id}`) : none('Pilih Divisi.');
        }
        case 'departemen': {
            const d = o.departemens.find((x) => String(x.id) === p.departemen_id);
            return d ? ok(join(gelar, d.name), `departemen:${d.id}`) : none('Pilih Departemen.');
        }
        case 'section': {
            const s = o.sections.find((x) => String(x.id) === p.section_id);
            return s ? ok(join(gelar, s.name), `section:${s.id}`) : none('Pilih Section.');
        }
        case 'work_location':
            // Named after the place; its superior is arranged in the chart (SO).
            return ok(join(gelar, location.kode || location.name), 'none');
        default: {
            const s = o.sections.find((x) => String(x.id) === p.section_id);
            // In a Section, else a Departemen, else placed in the plan later.
            if (!p.bidang) return none('Pilih Nama Jabatan.');
            return ok(join(gelar, p.bidang), s ? `section:${s.id}` : p.departemen_id ? `departemen:${p.departemen_id}` : 'none');
        }
    }
}

/** The request body for a placement (empty strings as null). */
export function placementPayload(p: Placement): Record<keyof Placement, string | null> {
    return Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v === '' ? null : v])) as Record<keyof Placement, string | null>;
}
