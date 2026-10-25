import { memo, useEffect, useLayoutEffect, useMemo, useRef, useState, type ClipboardEvent, type KeyboardEvent, type MouseEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    Copy,
    Download,
    HelpCircle,
    Keyboard,
    Loader2,
    Pencil,
    Plane,
    Repeat,
    Save,
    Search,
    Upload,
    X,
} from 'lucide-react';
import {
    AttendanceHeader,
    PeriodPicker,
    SubLocationSelect,
    matchesSubLocation,
    primaryButton,
    secondaryButton,
    smallInput,
    subLocationFor,
    type SubLocationOption,
} from '@/components/time/ui';
import {
    CopyRosterDialog,
    ExportDialog,
    ImportDialog,
    PatternDialog,
    type FillRequest,
    type ImportedChange,
    type MonthSpan,
    type Target,
} from '@/components/time/SheetDialogs';
import {
    JENIS_CELL,
    JENIS_LABEL,
    SHEET_PATH,
    WEEKDAY_SHORT,
    attendanceStats,
    lastDayOf,
    patternCodeOn,
    pct,
    type Jenis,
    type Pattern,
    type PeriodDay,
    type PeriodInfo,
    type PeriodOption,
    type SheetRow,
    type ShiftCode,
} from '@/lib/time';
import { fmtDate } from '@/lib/format';
import { EMPLOYEE_STATUS, label } from '@/lib/labels';

type SheetType = 'schedule' | 'attendance';

interface Props {
    type: SheetType;
    period: PeriodInfo;
    periods: PeriodOption[];
    codes: ShiftCode[];
    patterns: Pattern[];
    rows: SheetRow[];
    locations: { id: number; name: string }[];
    sub_locations: SubLocationOption[];
    departments: { id: number; name: string }[];
    can_edit: boolean;
}

type Sumber = 'manual' | 'schedule' | 'import';
interface Change {
    code: number | null;
    source: Sumber;
}
/** Unsaved cells: karyawan id => day => change. */
type Draft = Record<number, Record<number, Change>>;
/** A cell: row index among the shown rows, and day of the month. */
interface Pos {
    r: number;
    d: number;
}
type Entry = { row: SheetRow; day: number; change: Change };

const COL = { check: 34, employee_number: 86, name: 200 };
const DAY_WIDTH = 38;
const HEAD_HEIGHT = 42;
const FOOT_HEIGHT = 34;
const PAGE_ROWS = 15;
const MAX_UNDO = 100;

const original = (row: SheetRow, day: number): number | null => row.cells[day] ?? null;
const effective = (row: SheetRow, day: number, draftRow?: Record<number, Change>): number | null =>
    draftRow && draftRow[day] !== undefined ? draftRow[day].code : original(row, day);
const clamp = (n: number, min: number, max: number) => Math.min(Math.max(n, min), max);

/** Sets cells into a copy of the draft, dropping any that end up equal to what is saved. */
function withChanges(prev: Draft, entries: Entry[]): Draft {
    const next: Draft = { ...prev };
    const copied = new Set<number>();
    for (const { row, day, change } of entries) {
        if (day < row.from || day > row.until) continue;
        if (!copied.has(row.id)) {
            next[row.id] = { ...(prev[row.id] ?? {}) };
            copied.add(row.id);
        }
        if (change.code === original(row, day)) delete next[row.id][day];
        else next[row.id][day] = change;
    }
    for (const id of copied) if (!Object.keys(next[id]).length) delete next[id];
    return next;
}

export default function Sheet({ type, period, periods, codes, patterns, rows, locations, sub_locations, departments, can_edit }: Props) {
    const isAttendance = type === 'attendance';
    const title = isAttendance ? 'Absensi' : 'Jadwal Shift (Roster)';
    const query = new URLSearchParams(window.location.search);
    const [location, setLokasi] = useState(query.get('location') ?? '');
    const [sub, setSub] = useState(query.get('sub') ?? '');
    const [department, setDepartemen] = useState(query.get('department') ?? '');
    const [search, setSearch] = useState(query.get('q') ?? '');

    const [editing, setEditing] = useState(false);
    const [draft, setDraftState] = useState<Draft>({});
    const draftRef = useRef<Draft>({});
    const undoStack = useRef<Draft[]>([]);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [dialog, setDialog] = useState<'pattern' | 'copy' | 'import' | 'export' | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ text: string; tone: 'ok' | 'warn' } | null>(null);
    const savingRef = useRef(false);

    // Spreadsheet state: the active cell, the other corner of the selected block, and the cell editor.
    const [cursor, setCursor] = useState<Pos | null>(null);
    const [anchor, setAnchor] = useState<Pos | null>(null);
    // select: opened with F2 / double-click (the current code selected) rather than by typing.
    const [editor, setEditor] = useState<{ value: string; error: boolean; select?: boolean } | null>(null);
    // Set when the editor is closed by a key or a click, so its blur does not write a second time.
    const editorClosed = useRef(false);
    const [editorBox, setEditorBox] = useState<{ top: number; left: number; width: number; height: number } | null>(null);
    const grid = useRef<HTMLDivElement>(null);
    const dragging = useRef(false);

    const codeById = useMemo(() => new Map(codes.map((c) => [c.id, c])), [codes]);
    const typeOf = useMemo(() => new Map(codes.map((c) => [c.id, c.type])), [codes]);
    const rowById = useMemo(() => new Map(rows.map((r) => [r.id, r])), [rows]);
    // What can be typed or pasted into a cell: a Kode Shift, or its DWS.
    const codeByText = useMemo(() => {
        const map = new Map<string, number>();
        for (const c of codes) map.set(c.code.toUpperCase(), c.id);
        for (const c of codes) if (c.daily_work_schedule && !map.has(c.daily_work_schedule.toUpperCase())) map.set(c.daily_work_schedule.toUpperCase(), c.id);
        return map;
    }, [codes]);
    const lastDay = lastDayOf(period);
    const dayCount = period.days.length;
    const todayDay = period.today.slice(0, 7) === period.value ? Number(period.today.slice(8)) : 0;

    // Filters live in the address, so a reload, a save or another month keeps them.
    useEffect(() => {
        const url = new URL(window.location.href);
        for (const [key, value] of [
            ['location', location],
            ['sub', sub],
            ['department', department],
            ['q', search.trim()],
        ]) {
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        }
        window.history.replaceState(window.history.state, '', url);
    }, [location, sub, department, search]);

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter(
            (r) =>
                (!location || String(r.work_location_id) === location) &&
                matchesSubLocation(sub, r.sub_location_id) &&
                (!department || String(r.department_id) === department) &&
                (!q || r.name.toLowerCase().includes(q) || r.employee_number.includes(q) || (r.position ?? '').toLowerCase().includes(q)),
        );
    }, [rows, location, sub, department, search]);

    // A filter that hides the active cell's row drops the selection.
    useEffect(() => {
        if (cursor && cursor.r >= visible.length) {
            setCursor(null);
            setAnchor(null);
            setEditor(null);
        }
    }, [visible, cursor]);

    const changeCount = useMemo(() => Object.values(draft).reduce((n, days) => n + Object.keys(days).length, 0), [draft]);
    const dirty = changeCount > 0;

    // Unsaved cells hold back leaving the page (another menu, another month, closing the tab).
    useEffect(() => {
        if (!dirty) return;
        const off = router.on('before', (event) => {
            if (savingRef.current) return;
            if (!confirm(`Ada ${changeCount} perubahan ${title} yang belum disimpan. Tinggalkan halaman dan buang perubahan?`))
                event.preventDefault();
        });
        const onUnload = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', onUnload);
        return () => {
            off();
            window.removeEventListener('beforeunload', onUnload);
        };
    }, [dirty, changeCount, title]);

    useEffect(() => {
        const stop = () => (dragging.current = false);
        window.addEventListener('mouseup', stop);
        return () => window.removeEventListener('mouseup', stop);
    }, []);

    useEffect(() => {
        if (!notice) return;
        const timer = setTimeout(() => setNotice(null), 6000);
        return () => clearTimeout(timer);
    }, [notice]);

    // --- the draft, with undo ---------------------------------------------------------------

    const setDraft = (next: Draft) => {
        draftRef.current = next;
        setDraftState(next);
    };
    /** Applies cells to the draft as one undoable step; returns how many changed the table. */
    const apply = (entries: Entry[]): void => {
        const prev = draftRef.current;
        const next = withChanges(prev, entries);
        undoStack.current = [...undoStack.current.slice(-MAX_UNDO + 1), prev];
        setDraft(next);
    };
    const undo = () => {
        const prev = undoStack.current.pop();
        if (prev) setDraft(prev);
    };
    const resetDraft = () => {
        undoStack.current = [];
        setDraft({});
    };

    // --- selection ------------------------------------------------------------------------------

    const range = cursor
        ? {
              r1: Math.min(cursor.r, (anchor ?? cursor).r),
              r2: Math.max(cursor.r, (anchor ?? cursor).r),
              d1: Math.min(cursor.d, (anchor ?? cursor).d),
              d2: Math.max(cursor.d, (anchor ?? cursor).d),
          }
        : null;

    const moveTo = (pos: Pos, extend: boolean) => {
        if (!visible.length) return;
        const next = { r: clamp(pos.r, 0, visible.length - 1), d: clamp(pos.d, 1, dayCount) };
        setCursor(next);
        if (!extend) setAnchor(next);
    };

    const cellAt = (pos: Pos) => grid.current?.querySelector<HTMLElement>(`tr[data-index="${pos.r}"] td[data-day="${pos.d}"]`) ?? null;

    // Keep the active cell in view, clear of the sticky columns, header and footer.
    useLayoutEffect(() => {
        const box = grid.current;
        const td = cursor && cellAt(cursor);
        if (!box || !td) return;
        const b = box.getBoundingClientRect();
        const c = td.getBoundingClientRect();
        const sticky = (editing ? COL.check : 0) + COL.employee_number + COL.name;
        const top = c.top - b.top + box.scrollTop;
        const leftPos = c.left - b.left + box.scrollLeft;
        if (leftPos < box.scrollLeft + sticky) box.scrollLeft = leftPos - sticky;
        else if (leftPos + c.width > box.scrollLeft + box.clientWidth) box.scrollLeft = leftPos + c.width - box.clientWidth;
        if (top < box.scrollTop + HEAD_HEIGHT) box.scrollTop = top - HEAD_HEIGHT;
        else if (top + c.height > box.scrollTop + box.clientHeight - FOOT_HEIGHT) box.scrollTop = top + c.height - box.clientHeight + FOOT_HEIGHT;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cursor]);

    // The cell editor sits over the active cell.
    useLayoutEffect(() => {
        const box = grid.current;
        const td = editor && cursor ? cellAt(cursor) : null;
        if (!box || !td) {
            setEditorBox(null);
            return;
        }
        const b = box.getBoundingClientRect();
        const c = td.getBoundingClientRect();
        setEditorBox({ top: c.top - b.top + box.scrollTop, left: c.left - b.left + box.scrollLeft, width: c.width, height: c.height });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editor !== null, cursor]);

    const focusGrid = () => grid.current?.focus({ preventScroll: true });

    // --- typing, pasting, copying -----------------------------------------------------------

    /** Kode Shift or DWS -> code id; '' or '-' empties the cell; undefined = not a known code. */
    const parse = (text: string): number | null | undefined => {
        const t = text.trim().toUpperCase();
        if (t === '' || t === '-') return null;
        return codeByText.get(t);
    };

    const entriesFor = (cells: { r: number; d: number; code: number | null }[]): Entry[] =>
        cells.flatMap(({ r, d, code }) => {
            const row = visible[r];
            return row && d >= 1 && d <= dayCount ? [{ row, day: d, change: { code, source: 'manual' as const } }] : [];
        });

    const selectionCells = () => {
        if (!range) return [];
        const cells: Pos[] = [];
        for (let r = range.r1; r <= range.r2; r++) for (let d = range.d1; d <= range.d2; d++) cells.push({ r, d });
        return cells;
    };

    const clearSelection = () => apply(entriesFor(selectionCells().map((p) => ({ ...p, code: null }))));

    const knownCodes = codes.map((c) => c.code).join(', ');

    const openEditor = (value: string, select: boolean) => {
        editorClosed.current = false;
        setEditor({ value, error: false, select });
    };

    /** Writes the editor's value; Ctrl+Enter fills the whole selection. False when the code is unknown. */
    const commitEditor = (fillSelection = false): boolean => {
        if (!editor || !cursor) return true;
        const code = parse(editor.value);
        if (code === undefined) {
            setEditor({ ...editor, error: true });
            setNotice({ text: `Kode "${editor.value.trim().toUpperCase()}" tidak dikenal. Pakai Kode Shift atau DWS: ${knownCodes}.`, tone: 'warn' });
            return false;
        }
        const targets = fillSelection ? selectionCells() : [cursor];
        apply(entriesFor(targets.map((p) => ({ ...p, code }))));
        setEditor(null);
        return true;
    };

    const toText = () => {
        if (!range) return '';
        const lines: string[] = [];
        for (let r = range.r1; r <= range.r2; r++) {
            const row = visible[r];
            const values: string[] = [];
            for (let d = range.d1; d <= range.d2; d++) {
                const code = effective(row, d, draft[row.id]);
                values.push(code !== null ? (codeById.get(code)?.code ?? '') : '');
            }
            lines.push(values.join('\t'));
        }
        return lines.join('\n');
    };

    const onCopy = (e: ClipboardEvent<HTMLDivElement>) => {
        if (editor || !range) return;
        e.preventDefault();
        e.clipboardData.setData('text/plain', toText());
        const cells = (range.r2 - range.r1 + 1) * (range.d2 - range.d1 + 1);
        setNotice({ text: `${cells} sel disalin — bisa ditempel di Excel atau di tabel ini.`, tone: 'ok' });
    };

    const onCut = (e: ClipboardEvent<HTMLDivElement>) => {
        if (editor || !range || !editing) return;
        onCopy(e);
        clearSelection();
    };

    /** Pastes a block copied from Excel (tab-separated) at the selection; one value fills the whole selection. */
    const onPaste = (e: ClipboardEvent<HTMLDivElement>) => {
        if (editor || !range) return;
        e.preventDefault();
        if (!editing) {
            setNotice({ text: `Tekan "Edit ${title}" dulu untuk menempel data.`, tone: 'warn' });
            return;
        }
        const text = e.clipboardData.getData('text/plain');
        const block = text
            .replace(/\r\n?/g, '\n')
            .replace(/\n$/, '')
            .split('\n')
            .map((line) => line.split('\t'));
        if (!block.length) return;

        const unknown = new Set<string>();
        const cells: { r: number; d: number; code: number | null }[] = [];
        const put = (r: number, d: number, raw: string) => {
            const code = parse(raw);
            if (code === undefined) unknown.add(raw.trim().toUpperCase());
            else cells.push({ r, d, code });
        };
        const single = block.length === 1 && block[0].length === 1;
        let height = block.length;
        let width = Math.max(...block.map((l) => l.length));
        if (single && (range.r2 > range.r1 || range.d2 > range.d1)) {
            for (const p of selectionCells()) put(p.r, p.d, block[0][0]);
            height = range.r2 - range.r1 + 1;
            width = range.d2 - range.d1 + 1;
        } else {
            block.forEach((line, i) =>
                line.forEach((raw, j) => {
                    if (range.r1 + i < visible.length && range.d1 + j <= dayCount) put(range.r1 + i, range.d1 + j, raw);
                }),
            );
        }
        const entries = entriesFor(cells);
        apply(entries);
        setAnchor({ r: range.r1, d: range.d1 });
        setCursor({ r: clamp(range.r1 + height - 1, 0, visible.length - 1), d: clamp(range.d1 + width - 1, 1, dayCount) });
        const clipped = block.length > visible.length - range.r1 || width > dayCount - range.d1 + 1;
        setNotice({
            text:
                `${entries.length} sel ditempel.` +
                (unknown.size ? ` Kode tidak dikenal dilewati: ${[...unknown].slice(0, 5).join(', ')}${unknown.size > 5 ? ', …' : ''}.` : '') +
                (clipped ? ' Sebagian data melewati batas tabel dan tidak ditempel.' : ''),
            tone: unknown.size || clipped ? 'warn' : 'ok',
        });
    };

    const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
        if (editor || (e.target as HTMLElement).tagName === 'INPUT') return;
        const ctrl = e.ctrlKey || e.metaKey;
        if (!cursor) {
            if (e.key.startsWith('Arrow') || e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                moveTo({ r: 0, d: todayDay || 1 }, false);
            }
            return;
        }
        const { r, d } = cursor;
        const go = (pos: Pos, extend = e.shiftKey) => {
            e.preventDefault();
            moveTo(pos, extend);
        };
        switch (e.key) {
            case 'ArrowUp':
                return go({ r: ctrl ? 0 : r - 1, d });
            case 'ArrowDown':
                return go({ r: ctrl ? visible.length - 1 : r + 1, d });
            case 'ArrowLeft':
                return go({ r, d: ctrl ? 1 : d - 1 });
            case 'ArrowRight':
                return go({ r, d: ctrl ? dayCount : d + 1 });
            case 'Tab':
                return go({ r, d: d + (e.shiftKey ? -1 : 1) }, false);
            case 'Enter':
                return go({ r: r + (e.shiftKey ? -1 : 1), d }, false);
            case 'Home':
                return go({ r: ctrl ? 0 : r, d: 1 });
            case 'End':
                return go({ r: ctrl ? visible.length - 1 : r, d: dayCount });
            case 'PageDown':
                return go({ r: r + PAGE_ROWS, d });
            case 'PageUp':
                return go({ r: r - PAGE_ROWS, d });
            case 'Escape':
                setAnchor(cursor);
                return;
            case 'Delete':
            case 'Backspace':
                if (editing) {
                    e.preventDefault();
                    clearSelection();
                }
                return;
            case 'F2':
                if (editing) {
                    e.preventDefault();
                    const code = effective(visible[r], d, draft[visible[r].id]);
                    openEditor(code !== null ? (codeById.get(code)?.code ?? '') : '', true);
                }
                return;
        }
        if (ctrl && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            if (editing) undo();
            return;
        }
        if (ctrl && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            setAnchor({ r: 0, d: 1 });
            setCursor({ r: visible.length - 1, d: dayCount });
            return;
        }
        // Typing a code starts editing the active cell, like a spreadsheet.
        if (!ctrl && !e.altKey && e.key.length === 1 && /[\w\-/+]/.test(e.key)) {
            e.preventDefault();
            if (editing) openEditor(e.key.toUpperCase(), false);
            else if (can_edit) setNotice({ text: `Tekan "Edit ${title}" dulu untuk mengetik kode.`, tone: 'warn' });
        }
    };

    const onEditorKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (!cursor) return;
        const { r, d } = cursor;
        const commitAndGo = (pos: Pos | null, fill = false) => {
            e.preventDefault();
            if (!commitEditor(fill)) return;
            editorClosed.current = true;
            if (pos) moveTo(pos, false);
            focusGrid();
        };
        switch (e.key) {
            case 'Enter':
                return commitAndGo(e.ctrlKey ? null : { r: r + (e.shiftKey ? -1 : 1), d }, e.ctrlKey);
            case 'Tab':
                return commitAndGo({ r, d: d + (e.shiftKey ? -1 : 1) });
            case 'ArrowUp':
                return commitAndGo({ r: r - 1, d });
            case 'ArrowDown':
                return commitAndGo({ r: r + 1, d });
            case 'ArrowLeft':
                return commitAndGo({ r, d: d - 1 });
            case 'ArrowRight':
                return commitAndGo({ r, d: d + 1 });
            case 'Escape':
                e.preventDefault();
                editorClosed.current = true;
                setEditor(null);
                focusGrid();
                return;
        }
    };

    // --- mouse: select cells (no painting) -------------------------------------------------

    const posFromEvent = (e: MouseEvent): Pos | null => {
        const cell = (e.target as HTMLElement).closest<HTMLElement>('td[data-day]');
        const tr = cell?.closest<HTMLElement>('tr[data-index]');
        return cell && tr ? { r: Number(tr.dataset.index), d: Number(cell.dataset.day) } : null;
    };

    // --- bulk fills ------------------------------------------------------------------------

    const goPeriod = (value: string) => {
        const params: Record<string, string> = { period: value };
        if (location) params.location = location;
        if (sub) params.sub = sub;
        if (department) params.department = department;
        if (search.trim()) params.q = search.trim();
        router.get(SHEET_PATH[type], params);
    };

    const targets = (target: Target) => (target === 'selected' ? visible.filter((r) => selected.has(r.id)) : visible);

    const fillDays = (fill: FillRequest, codeFor: (row: SheetRow, day: PeriodDay) => number | null, source: Sumber) => {
        const prev = draftRef.current;
        apply(
            targets(fill.target).flatMap((row) =>
                period.days
                    .filter((d) => d.day >= fill.fromDay && d.day <= fill.toDay)
                    .flatMap((d) => {
                        const code = codeFor(row, d);
                        if (code === null) return [];
                        if (!fill.overwrite && effective(row, d.day, prev[row.id]) !== null) return [];
                        return [{ row, day: d.day, change: { code, source } }];
                    }),
            ),
        );
        setDialog(null);
    };

    const applyPattern = (pattern: Pattern, startIso: string, fill: FillRequest) =>
        fillDays(fill, (_row, d) => patternCodeOn(pattern, startIso, d.date), 'manual');
    const copyRoster = (fill: FillRequest) => fillDays(fill, (row, d) => row.plan?.[d.day] ?? null, 'schedule');

    const applyImport = (changes: ImportedChange[]) => {
        const byDate = new Map(period.days.map((d) => [d.date, d.day]));
        apply(
            changes.flatMap((c) => {
                const row = rowById.get(c.employee_id);
                const day = byDate.get(c.date);
                return row && day ? [{ row, day, change: { code: c.shift_code_id, source: 'import' as const } }] : [];
            }),
        );
        setEditing(true);
        setDialog(null);
    };

    const save = () => {
        const changes = Object.entries(draft).flatMap(([id, days]) =>
            Object.entries(days).map(([day, c]) => ({
                employee_id: Number(id),
                date: period.days[Number(day) - 1].date,
                shift_code_id: c.code,
                source: c.source,
            })),
        );
        setError(null);
        setSaving(true);
        savingRef.current = true;
        router.post(`${SHEET_PATH[type]}/save`, { changes } as never, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                resetDraft();
                setEditing(false);
                setEditor(null);
                setSelected(new Set());
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan.'),
            onFinish: () => {
                savingRef.current = false;
                setSaving(false);
            },
        });
    };

    const cancel = () => {
        if (dirty && !confirm(`Buang ${changeCount} perubahan yang belum disimpan?`)) return;
        resetDraft();
        setEditing(false);
        setEditor(null);
        setSelected(new Set());
    };

    const exportUrl = (format: string, shape: string, span: MonthSpan) => {
        const params = new URLSearchParams({ from: span.from, until: span.until, format, shape });
        if (location) params.set('location', location);
        if (sub) params.set('sub', sub);
        if (department) params.set('department', department);
        if (search.trim()) params.set('q', search.trim());
        return `${SHEET_PATH[type]}/export?${params}`;
    };

    // Per day: how many of the shown people work (hadir) that day.
    const workingPerDay = useMemo(
        () =>
            period.days.map((d) =>
                visible.reduce((n, row) => {
                    const code = effective(row, d.day, draft[row.id]);
                    return n + (code !== null && typeOf.get(code) === 'work' ? 1 : 0);
                }, 0),
            ),
        [period.days, visible, draft, typeOf],
    );

    const allSelected = visible.length > 0 && visible.every((r) => selected.has(r.id));
    const left = editing ? { employee_number: COL.check, name: COL.check + COL.employee_number } : { employee_number: 0, name: COL.employee_number };
    const summaryHeaders = isAttendance ? ['% Hadir', '% Sesuai Roster'] : ['Kerja', 'Off', 'Cuti'];
    const cursorRow = cursor ? visible[cursor.r] : undefined;
    const selectionSize = range ? (range.r2 - range.r1 + 1) * (range.d2 - range.d1 + 1) : 0;

    return (
        <>
            <Head title={title} />
            <AttendanceHeader
                title={title}
                subtitle={isAttendance ? 'Kehadiran aktual karyawan per hari, dibandingkan dengan Roster' : 'Rencana kehadiran karyawan per hari'}
            >
                <PeriodPicker period={period} periods={periods} onChange={goPeriod} />
            </AttendanceHeader>

            <div className="flex min-h-0 flex-1 flex-col bg-slate-50">
                {/* Toolbar */}
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-6 py-3">
                    <div className="relative">
                        <Search size={15} className="absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama / NRP / jabatan"
                            className={`${smallInput} w-56 pl-8`}
                        />
                    </div>
                    <select
                        value={location}
                        onChange={(e) => {
                            setLokasi(e.target.value);
                            setSub((s) => subLocationFor(s, e.target.value, sub_locations));
                        }}
                        className={smallInput}
                        aria-label="Lokasi kerja"
                    >
                        <option value="">Semua lokasi</option>
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name}
                            </option>
                        ))}
                    </select>
                    {sub_locations.length > 0 && (
                        <SubLocationSelect value={sub} onChange={setSub} subLocations={sub_locations} locations={locations} location={location} />
                    )}
                    <select
                        value={department}
                        onChange={(e) => setDepartemen(e.target.value)}
                        className={`${smallInput} max-w-52`}
                        aria-label="Departemen"
                    >
                        <option value="">Semua departemen</option>
                        {departments.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.name}
                            </option>
                        ))}
                    </select>
                    <span className="text-xs text-slate-400">{visible.length} karyawan</span>

                    <div className="ml-auto flex items-center gap-2">
                        {can_edit && (
                            <button onClick={() => setDialog('import')} className={secondaryButton}>
                                <Upload size={15} />
                                Import Excel
                            </button>
                        )}
                        {!editing ? (
                            <>
                                <button onClick={() => setDialog('export')} className={secondaryButton}>
                                    <Download size={15} />
                                    Export
                                </button>
                                {can_edit && (
                                    <button
                                        onClick={() => {
                                            setEditing(true);
                                            focusGrid();
                                        }}
                                        className={primaryButton}
                                    >
                                        <Pencil size={15} />
                                        Edit {title}
                                    </button>
                                )}
                            </>
                        ) : (
                            <>
                                {isAttendance ? (
                                    <button onClick={() => setDialog('copy')} className={secondaryButton}>
                                        <Copy size={15} />
                                        Isi dari Roster
                                    </button>
                                ) : (
                                    <button onClick={() => setDialog('pattern')} className={secondaryButton}>
                                        <Repeat size={15} />
                                        Isi Pola
                                    </button>
                                )}
                                <span className="mx-1 h-6 w-px bg-slate-200" />
                                <button onClick={cancel} className="rounded-xl px-3 py-2 text-sm font-bold text-slate-500 hover:bg-slate-100">
                                    Batal
                                </button>
                                <button onClick={save} disabled={!dirty || saving} className={primaryButton}>
                                    {saving ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />}
                                    Simpan{dirty ? ` (${changeCount})` : ''}
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {editing && (
                    <div className="flex shrink-0 items-center gap-3 border-b border-amber-200 bg-amber-50 px-6 py-2 text-xs text-amber-900">
                        <span className="flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-400 px-2 py-0.5 text-[11px] font-black text-amber-950">
                            <Keyboard size={13} />
                            MODE EDIT
                        </span>
                        <span className="min-w-0 truncate">
                            Pilih sel, ketik <b>kode</b> lalu <Kbd>Enter</Kbd> · tempel dari Excel dengan <Kbd>Ctrl</Kbd>+<Kbd>V</Kbd>
                        </span>
                        <ShortcutHelp />
                        {dirty && (
                            <span className="ml-auto shrink-0 rounded-full bg-white px-2.5 py-0.5 text-[11px] font-bold text-amber-800 ring-1 ring-amber-300">
                                {changeCount} perubahan belum disimpan
                            </span>
                        )}
                    </div>
                )}
                <Legend codes={codes} isAttendance={isAttendance} editing={editing} />

                {error && (
                    <div className="mx-6 mt-3 flex items-start justify-between gap-3 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                        <span>{error}</span>
                        <button onClick={() => setError(null)} aria-label="Tutup">
                            <X size={16} />
                        </button>
                    </div>
                )}

                {/* The month: a spreadsheet — click or use the arrow keys, type a code, paste from Excel. */}
                <div
                    ref={grid}
                    tabIndex={0}
                    onKeyDown={onKeyDown}
                    onCopy={onCopy}
                    onCut={onCut}
                    onPaste={onPaste}
                    aria-label={`Tabel ${title}`}
                    className="relative min-h-0 flex-1 overflow-auto outline-none"
                >
                    <table className="border-separate border-spacing-0 text-xs" style={{ minWidth: '100%' }}>
                        <thead className="sticky top-0 z-30">
                            <tr className="bg-slate-100 text-[10px] font-bold tracking-wide text-slate-500 uppercase">
                                {editing && (
                                    <th
                                        className="sticky left-0 z-40 border-b border-slate-200 bg-slate-100"
                                        style={{ width: COL.check, minWidth: COL.check }}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={() => setSelected(allSelected ? new Set() : new Set(visible.map((r) => r.id)))}
                                            aria-label="Centang semua"
                                            className="accent-brand-600"
                                        />
                                    </th>
                                )}
                                <th
                                    className="sticky z-40 border-b border-slate-200 bg-slate-100 px-3 text-left"
                                    style={{ left: left.employee_number, width: COL.employee_number, minWidth: COL.employee_number }}
                                >
                                    NRP
                                </th>
                                <th
                                    className="sticky z-40 border-r border-b border-slate-200 bg-slate-100 px-3 text-left shadow-[2px_0_4px_-2px_rgba(0,0,0,0.1)]"
                                    style={{ left: left.name, width: COL.name, minWidth: COL.name }}
                                >
                                    Nama
                                </th>
                                <th className="border-b border-slate-200 px-3 text-left" style={{ minWidth: 200 }}>
                                    Jabatan
                                </th>
                                <th className="border-r border-b border-slate-200 px-3 text-left" style={{ minWidth: 140 }}>
                                    Departemen
                                </th>
                                {period.days.map((d) => {
                                    const red = d.weekday === 0 || !!d.holiday;
                                    const inSelection = !!range && d.day >= range.d1 && d.day <= range.d2;
                                    return (
                                        <th
                                            key={d.day}
                                            title={d.holiday ? `${fmtDate(d.date)} — ${d.holiday}` : fmtDate(d.date)}
                                            className={`border-r border-b border-slate-200 py-1 text-center ${red ? 'bg-rose-50 text-rose-500' : ''} ${
                                                d.day === todayDay ? 'bg-brand-600! text-white!' : inSelection ? 'bg-brand-100! text-brand-800!' : ''
                                            }`}
                                            style={{ width: DAY_WIDTH, minWidth: DAY_WIDTH }}
                                        >
                                            <span className="block text-[11px] font-black tracking-normal">{d.day}</span>
                                            <span className="block text-[9px] font-semibold normal-case">{WEEKDAY_SHORT[d.weekday]}</span>
                                        </th>
                                    );
                                })}
                                {summaryHeaders.map((h) => (
                                    <th
                                        key={h}
                                        className="border-b border-l border-slate-200 bg-slate-100 px-2 text-center whitespace-nowrap"
                                        style={{ minWidth: 64 }}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody
                            onMouseDown={(e) => {
                                if (e.button !== 0) return;
                                const pos = posFromEvent(e);
                                if (!pos) return;
                                e.preventDefault();
                                if (editor) {
                                    if (!commitEditor()) return;
                                    editorClosed.current = true;
                                }
                                setEditor(null);
                                moveTo(pos, e.shiftKey && !!cursor);
                                dragging.current = true;
                                focusGrid();
                            }}
                            onMouseOver={(e) => {
                                if (!dragging.current) return;
                                const pos = posFromEvent(e);
                                if (pos) moveTo(pos, true);
                            }}
                            onDoubleClick={(e) => {
                                const pos = posFromEvent(e);
                                if (!pos || !editing) return;
                                const row = visible[pos.r];
                                const code = effective(row, pos.d, draft[row.id]);
                                openEditor(code !== null ? (codeById.get(code)?.code ?? '') : '', true);
                            }}
                        >
                            {visible.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5 + period.days.length + summaryHeaders.length}
                                        className="px-6 py-12 text-center text-sm text-slate-400"
                                    >
                                        Tidak ada karyawan yang cocok dengan filter.
                                    </td>
                                </tr>
                            )}
                            {visible.map((row, i) => {
                                const inRange = !!range && i >= range.r1 && i <= range.r2;
                                return (
                                    <SheetRowView
                                        key={row.id}
                                        index={i}
                                        row={row}
                                        draftRow={draft[row.id]}
                                        days={period.days}
                                        codeById={codeById}
                                        typeOf={typeOf}
                                        editing={editing}
                                        selected={selected.has(row.id)}
                                        onSelect={(on) =>
                                            setSelected((prev) => {
                                                const next = new Set(prev);
                                                if (on) next.add(row.id);
                                                else next.delete(row.id);
                                                return next;
                                            })
                                        }
                                        isAttendance={isAttendance}
                                        lastDay={lastDay}
                                        todayDay={todayDay}
                                        left={left}
                                        selFrom={inRange ? range.d1 : 0}
                                        selTo={inRange ? range.d2 : 0}
                                        cursorDay={cursor?.r === i ? cursor.d : 0}
                                    />
                                );
                            })}
                        </tbody>
                        {visible.length > 0 && (
                            <tfoot className="sticky bottom-0 z-30">
                                <tr className="bg-slate-100 text-[10px] font-bold text-slate-500">
                                    {editing && <td className="sticky left-0 z-40 border-t border-slate-200 bg-slate-100" />}
                                    <td
                                        colSpan={2}
                                        className="sticky z-40 border-t border-r border-slate-200 bg-slate-100 px-3 py-2 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.1)]"
                                        style={{ left: left.employee_number }}
                                    >
                                        Jumlah hadir (kerja di lokasi)
                                    </td>
                                    <td colSpan={2} className="border-t border-r border-slate-200" />
                                    {workingPerDay.map((n, i) => (
                                        <td key={i} className="border-t border-r border-slate-200 text-center tabular-nums">
                                            {n || ''}
                                        </td>
                                    ))}
                                    <td colSpan={summaryHeaders.length} className="border-t border-l border-slate-200" />
                                </tr>
                            </tfoot>
                        )}
                    </table>

                    {editor && editorBox && (
                        <input
                            autoFocus
                            value={editor.value}
                            onChange={(e) => setEditor({ value: e.target.value.toUpperCase(), error: false })}
                            onFocus={(e) => {
                                const input = e.currentTarget;
                                if (editor.select) input.select();
                                else input.setSelectionRange(input.value.length, input.value.length);
                            }}
                            onKeyDown={onEditorKey}
                            onBlur={() => {
                                // Leaving the cell another way (e.g. a button): keep a valid code, drop an unknown one.
                                if (editorClosed.current) return;
                                if (!commitEditor()) setEditor(null);
                            }}
                            aria-label="Kode Shift"
                            spellCheck={false}
                            className={`absolute z-[35] rounded-sm bg-white text-center text-[11px] font-black text-slate-800 shadow-lg outline-none ring-2 ${
                                editor.error ? 'ring-rose-500' : 'ring-brand-600'
                            }`}
                            style={{ top: editorBox.top, left: editorBox.left, width: Math.max(editorBox.width, 56), height: editorBox.height }}
                        />
                    )}
                </div>

                {/* Status bar: the active cell, the selection, messages. */}
                <div className="flex h-8 shrink-0 items-center gap-3 border-t border-slate-200 bg-white px-6 text-[11px] text-slate-500">
                    {cursor && cursorRow ? (
                        <span className="truncate">
                            <b className="text-slate-700">{fmtDate(period.days[cursor.d - 1].date)}</b> · {cursorRow.name}
                            {selectionSize > 1 && <> · {selectionSize} sel terpilih</>}
                        </span>
                    ) : (
                        <span>Klik sel atau tekan panah untuk mulai. Ctrl+C menyalin sel terpilih ke Excel.</span>
                    )}
                    {notice && (
                        <span className={`ml-auto truncate font-bold ${notice.tone === 'ok' ? 'text-emerald-700' : 'text-amber-700'}`}>
                            {notice.text}
                        </span>
                    )}
                </div>
            </div>

            {dialog === 'pattern' && (
                <PatternDialog
                    period={period}
                    patterns={patterns}
                    codes={codes}
                    selectedCount={visible.filter((r) => selected.has(r.id)).length}
                    visibleCount={visible.length}
                    onApply={applyPattern}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'copy' && (
                <CopyRosterDialog
                    period={period}
                    lastDay={lastDay}
                    selectedCount={visible.filter((r) => selected.has(r.id)).length}
                    visibleCount={visible.length}
                    onApply={copyRoster}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'import' && (
                <ImportDialog
                    type={type}
                    period={period}
                    periods={periods}
                    templateUrl={(span) => exportUrl('code', 'monthly', span)}
                    hasDraft={dirty}
                    onApply={applyImport}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'export' && (
                <ExportDialog type={type} period={period} periods={periods} exportUrl={exportUrl} onClose={() => setDialog(null)} />
            )}
        </>
    );
}

/** Arrow keys drawn as icons: the glyphs are too thin at this size. */
const ARROW_KEYS: Record<string, typeof ArrowUp> = { '←': ArrowLeft, '↑': ArrowUp, '→': ArrowRight, '↓': ArrowDown };

function Kbd({ children }: { children: React.ReactNode }) {
    const Arrow = typeof children === 'string' ? ARROW_KEYS[children] : undefined;
    return (
        <kbd className="inline-flex h-[18px] min-w-5 items-center justify-center rounded-md border border-b-2 border-slate-300 bg-white px-1.5 font-sans text-[10px] font-bold text-slate-700">
            {Arrow ? <Arrow size={11} strokeWidth={2.5} aria-label={children as string} /> : children}
        </kbd>
    );
}

/** Keyboard shortcuts of the edit mode, grouped, in a panel opened from the edit bar. */
const SHORTCUTS: { title: string; items: { keys: string[][]; text: string }[] }[] = [
    {
        title: 'Mengisi',
        items: [
            { keys: [['A–Z'], ['Enter']], text: 'Ketik Kode Shift / DWS, simpan ke sel' },
            { keys: [['F2']], text: 'Ubah isi sel (atau klik dua kali)' },
            { keys: [['Ctrl', 'Enter']], text: 'Isi semua sel terpilih dengan kode itu' },
            { keys: [['Delete']], text: 'Kosongkan sel terpilih' },
            { keys: [['Esc']], text: 'Batal mengetik' },
        ],
    },
    {
        title: 'Berpindah',
        items: [
            { keys: [['←'], ['↑'], ['→'], ['↓']], text: 'Pindah satu sel' },
            { keys: [['Tab'], ['Enter']], text: 'Ke kanan / ke bawah' },
            { keys: [['Ctrl', '→']], text: 'Ke ujung baris / kolom' },
            { keys: [['Home'], ['End']], text: 'Ke tanggal pertama / terakhir' },
            { keys: [['PgUp'], ['PgDn']], text: 'Naik / turun 15 baris' },
        ],
    },
    {
        title: 'Memilih & Excel',
        items: [
            { keys: [['Shift', '→']], text: 'Perluas pilihan (atau seret mouse)' },
            { keys: [['Ctrl', 'A']], text: 'Pilih seluruh tabel' },
            { keys: [['Ctrl', 'C']], text: 'Salin sel terpilih, tempel di Excel' },
            { keys: [['Ctrl', 'V']], text: 'Tempel blok dari Excel mulai sel aktif' },
            { keys: [['Ctrl', 'Z']], text: 'Batalkan langkah terakhir' },
        ],
    },
];

function ShortcutHelp() {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: globalThis.MouseEvent) => !box.current?.contains(e.target as Node) && setOpen(false);
        const esc = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        window.addEventListener('mousedown', close);
        window.addEventListener('keydown', esc);
        return () => {
            window.removeEventListener('mousedown', close);
            window.removeEventListener('keydown', esc);
        };
    }, [open]);

    return (
        <div ref={box} className="relative shrink-0">
            <button
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className={`flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-bold transition-colors ${
                    open ? 'bg-amber-200 text-amber-950' : 'text-amber-800 hover:bg-amber-100'
                }`}
            >
                <HelpCircle size={13} />
                Pintasan keyboard
            </button>
            {open && (
                <div className="absolute top-full right-0 z-50 mt-2 w-[min(660px,calc(100vw-7rem))] rounded-2xl border border-slate-200 bg-white p-4 text-slate-600 shadow-xl">
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-5">
                        {SHORTCUTS.map((group) => (
                            <div key={group.title}>
                                <p className="mb-2 text-[10px] font-bold tracking-wider text-slate-400 uppercase">{group.title}</p>
                                <ul className="space-y-2">
                                    {group.items.map((item) => (
                                        <li key={item.text} className="space-y-0.5">
                                            <div className="flex flex-wrap items-center gap-1">
                                                {item.keys.map((combo, i) => (
                                                    <span key={i} className="flex items-center gap-0.5">
                                                        {i > 0 && <span className="mr-0.5 text-[10px] text-slate-300">/</span>}
                                                        {combo.map((k, j) => (
                                                            <span key={k} className="flex items-center gap-0.5">
                                                                {j > 0 && <span className="text-[10px] text-slate-400">+</span>}
                                                                <Kbd>{k}</Kbd>
                                                            </span>
                                                        ))}
                                                    </span>
                                                ))}
                                            </div>
                                            <p className="text-[11px] leading-snug">{item.text}</p>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                    <p className="mt-3 border-t border-slate-100 pt-2.5 text-[11px] text-slate-400">
                        Sel kosong atau "-" yang ditempel akan mengosongkan hari itu. Perubahan baru tersimpan setelah tombol <b>Simpan</b>.
                    </p>
                </div>
            )}
        </div>
    );
}

/** The codes (with their DWS) and the marks used in the table. */
function Legend({ codes, isAttendance, editing }: { codes: ShiftCode[]; isAttendance: boolean; editing: boolean }) {
    return (
        <div className="flex shrink-0 flex-wrap items-center gap-1.5 border-b border-slate-200 bg-white px-6 py-2 text-[11px] text-slate-500">
            <span className="mr-1 text-[10px] font-bold tracking-wider text-slate-400 uppercase">Kode</span>
            {codes.map((c) => (
                <span
                    key={c.id}
                    title={[c.description ?? JENIS_LABEL[c.type], c.daily_work_schedule ? `DWS ${c.daily_work_schedule}` : null].filter(Boolean).join(' · ')}
                    className="flex items-center gap-1.5 rounded-lg border border-slate-200 py-0.5 pr-2 pl-0.5"
                >
                    <span className={`min-w-7 rounded-md px-1.5 py-0.5 text-center text-[10px] font-black ${JENIS_CELL[c.type]}`}>{c.code}</span>
                    <span className="max-w-40 truncate">{c.description ?? JENIS_LABEL[c.type]}</span>
                    {editing && c.daily_work_schedule && c.daily_work_schedule !== c.code && <span className="font-mono text-[10px] text-slate-400">{c.daily_work_schedule}</span>}
                </span>
            ))}
            {isAttendance && (
                <span className="ml-2 flex flex-wrap items-center gap-3 border-l border-slate-200 pl-3">
                    <span className="flex items-center gap-1.5">
                        <span className="h-2 w-2 rounded-full bg-rose-500" />
                        beda dengan Roster
                    </span>
                    <span className="flex items-center gap-1.5">
                        <Plane size={11} className="text-sky-600" />
                        dari Cuti &amp; Dinas
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="font-bold text-slate-300">D</span>
                        rencana Roster (belum diisi)
                    </span>
                </span>
            )}
        </div>
    );
}

interface RowProps {
    index: number;
    row: SheetRow;
    draftRow: Record<number, Change> | undefined;
    days: PeriodDay[];
    codeById: Map<number, ShiftCode>;
    typeOf: Map<number, Jenis>;
    editing: boolean;
    selected: boolean;
    onSelect: (on: boolean) => void;
    isAttendance: boolean;
    lastDay: number;
    todayDay: number;
    left: { employee_number: number; name: number };
    /** The selected days of this row (0 = none), and the active cell's day if it is on this row. */
    selFrom: number;
    selTo: number;
    cursorDay: number;
}

/** One employee's month. Memoised: an edit or a move re-renders only the rows it touches. */
const SheetRowView = memo(
    function SheetRowView({
        index,
        row,
        draftRow,
        days,
        codeById,
        typeOf,
        editing,
        selected,
        onSelect,
        isAttendance,
        lastDay,
        todayDay,
        left,
        selFrom,
        selTo,
        cursorDay,
    }: RowProps) {
        const values: Record<number, number | null> = {};
        for (const d of days) values[d.day] = d.day >= row.from && d.day <= row.until ? effective(row, d.day, draftRow) : null;
        const tripDays = new Set(row.trip_days ?? []);

        const summary = (() => {
            if (isAttendance) {
                const s = attendanceStats(values, row.plan ?? {}, typeOf, lastDay);
                const tone = (v: number | null) =>
                    v === null ? 'text-slate-300' : v >= 95 ? 'text-emerald-700' : v >= 85 ? 'text-amber-700' : 'text-rose-600';
                return [
                    <span key="k" className={tone(s.time_clock)}>
                        {pct(s.time_clock)}
                    </span>,
                    <span key="s" className={tone(s.matching)}>
                        {pct(s.matching)}
                    </span>,
                ];
            }
            const count = (kinds: Jenis[]) => Object.values(values).filter((c) => c !== null && kinds.includes(typeOf.get(c)!)).length || '';
            return [count(['work', 'business_trip']), count(['off']), count(['leave'])];
        })();

        const stickyBg = selected || selFrom ? 'bg-brand-50' : 'bg-white group-hover:bg-slate-50';

        return (
            <tr data-index={index} data-id={row.id} className="group">
                {editing && (
                    <td className={`sticky left-0 z-20 border-b border-slate-100 text-center ${stickyBg}`}>
                        <input
                            type="checkbox"
                            checked={selected}
                            onChange={(e) => onSelect(e.target.checked)}
                            aria-label={`Centang ${row.name}`}
                            className="accent-brand-600"
                        />
                    </td>
                )}
                <td
                    className={`sticky z-20 border-b border-slate-100 px-3 font-mono text-[11px] text-slate-500 tabular-nums ${stickyBg}`}
                    style={{ left: left.employee_number }}
                >
                    {row.employee_number}
                </td>
                <td
                    className={`sticky z-20 border-r border-b border-slate-100 px-3 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] ${stickyBg}`}
                    style={{ left: left.name, maxWidth: COL.name }}
                >
                    <div className="truncate font-bold text-slate-700" title={row.name}>
                        {row.name}
                        {row.sub_location && (
                            <span className="ml-1.5 rounded bg-sky-50 px-1 text-[9px] font-bold text-sky-700" title="Sub Lokasi Kerja">
                                {row.sub_location}
                            </span>
                        )}
                        {row.termination ? (
                            <span
                                className="ml-1.5 rounded bg-slate-800 px-1 text-[9px] font-bold text-white uppercase"
                                title={`Keluar ${fmtDate(row.termination.date)}`}
                            >
                                {row.termination.reason}
                            </span>
                        ) : (
                            row.status !== 'active' && (
                                <span className="ml-1.5 rounded bg-slate-100 px-1 text-[9px] font-bold text-slate-500 uppercase">{label(EMPLOYEE_STATUS, row.status)}</span>
                            )
                        )}
                    </div>
                </td>
                <td className="border-b border-slate-100 bg-white px-3 text-slate-500 group-hover:bg-slate-50" style={{ maxWidth: 220 }}>
                    <div className="truncate" title={row.position ?? undefined}>
                        {row.position ?? <span className="text-amber-600">Belum ditempatkan</span>}
                    </div>
                </td>
                <td className="border-r border-b border-slate-100 bg-white px-3 text-slate-500 group-hover:bg-slate-50" style={{ maxWidth: 160 }}>
                    <div className="truncate" title={row.department ?? undefined}>
                        {row.department ?? '-'}
                    </div>
                </td>
                {days.map((d) => {
                    // From the exit date: one black block across the rest of the month, with the reason.
                    if (row.termination && d.day > row.until) {
                        return d.day === row.until + 1 ? (
                            <td
                                key={d.day}
                                colSpan={days.length - row.until}
                                title={`${row.termination.reason} — tidak bekerja sejak ${fmtDate(row.termination.date)}`}
                                className="relative h-8 border-b border-slate-100 bg-slate-900 p-0 text-[10px] font-black tracking-wide text-white uppercase select-none"
                            >
                                {/* Positioned, so a short block (leaving late in the month) never widens the day columns. */}
                                <span className="absolute inset-0 flex items-center overflow-hidden px-2 whitespace-nowrap">
                                    <span className="truncate">
                                        {row.termination.reason} · sejak {fmtDate(row.termination.date)}
                                    </span>
                                </span>
                            </td>
                        ) : null;
                    }
                    const outside = d.day < row.from || d.day > row.until;
                    const code = values[d.day];
                    const shift = code !== null ? codeById.get(code) : undefined;
                    const changed = draftRow?.[d.day] !== undefined;
                    const planned = isAttendance && row.plan?.[d.day] !== undefined ? codeById.get(row.plan[d.day]) : undefined;
                    const differs = isAttendance && !!shift && !!planned && planned.id !== shift.id;
                    const fromTrip = isAttendance && !changed && tripDays.has(d.day);
                    const red = d.weekday === 0 || !!d.holiday;
                    const inSelection = selFrom > 0 && d.day >= selFrom && d.day <= selTo;
                    const active = d.day === cursorDay;

                    const tip = outside
                        ? 'Belum bekerja (sebelum tanggal masuk)'
                        : [
                              fmtDate(d.date),
                              shift ? `${shift.code} — ${shift.description ?? JENIS_LABEL[shift.type]}` : 'Kosong',
                              planned ? `Roster: ${planned.code}` : null,
                              fromTrip ? 'Dari Cuti & Dinas' : null,
                              changed ? 'Belum disimpan' : null,
                          ]
                              .filter(Boolean)
                              .join(' · ');

                    return (
                        <td
                            key={d.day}
                            data-day={d.day}
                            title={tip}
                            className={`relative h-8 cursor-cell border-r border-b border-slate-100 text-center text-[11px] font-black select-none ${
                                outside
                                    ? 'bg-slate-100/70 bg-[repeating-linear-gradient(135deg,transparent_0_4px,rgba(148,163,184,0.18)_4px_6px)]'
                                    : shift
                                      ? JENIS_CELL[shift.type]
                                      : d.day === todayDay
                                        ? 'bg-brand-50/60'
                                        : red
                                          ? 'bg-rose-50/40'
                                          : 'bg-white'
                            } ${changed ? 'ring-2 ring-amber-400 ring-inset' : ''} ${
                                inSelection ? 'shadow-[inset_0_0_0_999px_rgba(88,108,154,0.2)]' : ''
                            } ${active ? 'z-10 outline-2 -outline-offset-2 outline-brand-700' : ''}`}
                        >
                            {!outside && (shift ? shift.code : planned ? <span className="font-bold text-slate-300">{planned.code}</span> : null)}
                            {differs && <span className="absolute top-0.5 right-0.5 h-1.5 w-1.5 rounded-full bg-rose-500" />}
                            {fromTrip && <Plane size={8} className="absolute bottom-0.5 left-0.5 text-sky-600" />}
                        </td>
                    );
                })}
                {summary.map((value, i) => (
                    <td
                        key={i}
                        className="border-b border-l border-slate-100 bg-white px-2 text-center font-bold text-slate-600 tabular-nums group-hover:bg-slate-50"
                    >
                        {value}
                    </td>
                ))}
            </tr>
        );
    },
    (a, b) =>
        a.index === b.index &&
        a.row === b.row &&
        a.draftRow === b.draftRow &&
        a.editing === b.editing &&
        a.selected === b.selected &&
        a.days === b.days &&
        a.codeById === b.codeById &&
        a.lastDay === b.lastDay &&
        a.left.employee_number === b.left.employee_number &&
        a.selFrom === b.selFrom &&
        a.selTo === b.selTo &&
        a.cursorDay === b.cursorDay,
);
