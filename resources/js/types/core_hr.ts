/** Shapes of the Man Power props sent by App\Http\Controllers\Manpower\KaryawanController. */

export interface ColumnDef {
    key: string;
    label: string;
    /** Frozen to the left so the row stays identifiable while scrolling sideways. */
    sticky?: boolean;
}

export interface ColumnGroup {
    id: string;
    title: string;
    /** Cannot be hidden — carries the NRP / Nama identity columns. */
    locked?: boolean;
    columns: ColumnDef[];
}

export type EmployeeRow = Record<string, string | number | boolean | null> & { id: number; employee_number: string; name: string };

export interface GroupCompleteness {
    id: string;
    title: string;
    /** null when no employee currently needs to fill this group. */
    percent: number | null;
    filled: number;
    total: number;
    applicable: number;
}

interface PersonRef {
    employee_id: number;
    employee_number: string;
    name: string;
    position: string;
}

export interface PkwtListItem extends PersonRef {
    period_label: string;
    start_date: string;
    end_date: string;
    /** Negative once the contract has ended. */
    days_left: number;
    period_count: number;
    next_start: string;
}

export interface PensiunItem extends PersonRef {
    birth_date: string;
    retirement_date: string;
    days_left: number;
}

export interface UlangTahunItem extends PersonRef {
    birth_date: string;
    days: number;
    new_age: number;
    today: boolean;
}

export interface DashboardData {
    today: string;
    total_active: number;
    completeness: { overall_percent: number | null; groups: GroupCompleteness[] };
    origin: { local: number; transferred: number; not_filled: number };
    employment_status: { contract: number; permanent: number };
    contracts_ending_soon: PkwtListItem[];
    contracts_ended: PkwtListItem[];
    retirement_age: number;
    retirements: PensiunItem[];
    birthday_month: string;
    birthdays: UlangTahunItem[];
}

export type ImportAction = 'new' | 'update' | 'unchanged' | 'skipped';

export interface FieldChange {
    group_id: string;
    group_title: string;
    label: string;
    before: string | null;
    after: string | null;
}

export interface ImportRowResult {
    /** Row number as it appears in Excel (the header is row 1). */
    row: number;
    employee_number: string;
    name: string;
    action: ImportAction;
    /** Why the row was skipped. */
    reason: string | null;
    changes: FieldChange[];
    /** Titles of the groups this row actually changes. */
    groups: string[];
    warnings: string[];
    completeness_before: number | null;
    completeness_after: number | null;
    /** percent is null when the group does not apply to this employee. */
    groups_after: { id: string; title: string; percent: number | null }[];
}

export interface ImportOutcome {
    dry_run: boolean;
    update_only: boolean;
    total_rows: number;
    created: number;
    updated: number;
    unchanged: number;
    rows: ImportRowResult[];
    recognized_groups: { id: string; title: string; columns: number }[];
    /** Headers in the file that match no known column (likely typos). */
    unknown_columns: string[];
    skipped: { row: number; reason: string }[];
}

export interface KeluarRow {
    id: number;
    employee_number: string;
    name: string;
    position_name: string;
    department_name: string;
    work_location_name: string;
    hire_date: string | null;
    termination_date: string | null;
    years_of_service: string | null;
    termination_reason: string | null;
    termination_notes: string | null;
}
