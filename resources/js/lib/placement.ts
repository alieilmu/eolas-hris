/**
 * Placement → position name, mirroring App\Support\Org\JabatanComposer so forms
 * can show the name before saving (the server has the final say):
 *   Section Head Site + Section PRODUKSI      → SECTION HEAD PRODUKSI
 *   Officer HO / Group Leader + HO + HSE      → OFFICER HSE (GROUP LEADER HSE at a site)
 *   Project Manager + RMTU                    → PROJECT MANAGER RMTU
 */

export type Sumber = 'directorate' | 'division' | 'department' | 'section' | 'work_location' | 'discipline';

export interface PenempatanOptions {
    work_locations: { id: number; name: string; code: string | null; type: string }[];
    sub_locations: { id: number; name: string; work_location_id: number }[];
    job_levels: {
        id: number;
        name: string;
        layer: number;
        source: Sumber;
        title_prefix: string | null;
        title_prefix_head_office: string | null;
    }[];
    directorates: { id: number; name: string }[];
    divisions: { id: number; name: string; directorate_id: number }[];
    departments: { id: number; name: string; division_id: number }[];
    sections: { id: number; name: string; department_id: number }[];
    discipline: string[];
    positions: { id: number; name: string; job_level_id: number | null; work_location_id: number | null; holders: string[] }[];
}

/** Form state: ids as strings ('' = not chosen), like the selects that hold them. */
export interface Placement {
    work_location_id: string;
    sub_location_id: string;
    job_level_id: string;
    directorate_id: string;
    division_id: string;
    department_id: string;
    section_id: string;
    discipline: string;
}

export const emptyPlacement = (): Placement => ({
    work_location_id: '',
    sub_location_id: '',
    job_level_id: '',
    directorate_id: '',
    division_id: '',
    department_id: '',
    section_id: '',
    discipline: '',
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
    directorate: 'BOD',
    division: 'Divisi',
    department: 'Departemen',
    section: 'Section',
    work_location: 'Work Location',
    discipline: 'Nama Jabatan (Section opsional)',
};

const join = (title_prefix: string | null | undefined, rest: string | null | undefined) => [title_prefix?.trim(), rest?.trim()].filter(Boolean).join(' ');

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
    const sub = o.job_levels.find((g) => String(g.id) === p.job_level_id);
    if (!location) return none('Pilih Work Location.');
    if (!sub) return none('Pilih SubGroup.');

    const title_prefix = location.type === HEAD_OFFICE && sub.title_prefix_head_office ? sub.title_prefix_head_office : sub.title_prefix;
    const ok = (name: string, unit: string): Composed => ({ name, unit, error: null });

    switch (sub.source) {
        case 'directorate': {
            const b = o.directorates.find((x) => String(x.id) === p.directorate_id);
            return b ? ok(join(title_prefix, b.name), `directorate:${b.id}`) : none('Pilih BOD.');
        }
        case 'division': {
            const d = o.divisions.find((x) => String(x.id) === p.division_id);
            return d ? ok(join(title_prefix, d.name), `division:${d.id}`) : none('Pilih Divisi.');
        }
        case 'department': {
            const d = o.departments.find((x) => String(x.id) === p.department_id);
            return d ? ok(join(title_prefix, d.name), `department:${d.id}`) : none('Pilih Departemen.');
        }
        case 'section': {
            const s = o.sections.find((x) => String(x.id) === p.section_id);
            return s ? ok(join(title_prefix, s.name), `section:${s.id}`) : none('Pilih Section.');
        }
        case 'work_location':
            // Named after the place; its superior is arranged in the chart (SO).
            return ok(join(title_prefix, location.code || location.name), 'none');
        default: {
            const s = o.sections.find((x) => String(x.id) === p.section_id);
            // In a Section, else a Departemen, else placed in the plan later.
            if (!p.discipline) return none('Pilih Nama Jabatan.');
            return ok(join(title_prefix, p.discipline), s ? `section:${s.id}` : p.department_id ? `department:${p.department_id}` : 'none');
        }
    }
}

/** The request body for a placement (empty strings as null). */
export function placementPayload(p: Placement): Record<keyof Placement, string | null> {
    return Object.fromEntries(Object.entries(p).map(([k, v]) => [k, v === '' ? null : v])) as Record<keyof Placement, string | null>;
}
