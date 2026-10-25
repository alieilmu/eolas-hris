import { useEffect, useState, type ReactNode } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, ChevronDown, Loader2, MapPin, Network, Plus, Save, Trash2, UserX, X } from 'lucide-react';
import { PlacementDialog } from '@/components/placement/PlacementDialog';
import { placementFrom } from '@/lib/placement';
import { fmtDate } from '@/lib/format';
import { addDaysIso, endDateForMonths, todayIso } from '@/lib/contractDates';
import { DateInput } from '@/components/DateInput';

// --- props ------------------------------------------------------------------------

type FieldValue = string | number | boolean | null;

interface ProfileField {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'select' | 'checkbox' | 'textarea';
    options?: string[];
    /** Rendered read-only — computed server-side, never submitted. */
    computed?: boolean;
    hint?: string;
}

interface ProfileSection {
    id: string;
    title: string;
    /** Rendered by a dedicated child-table editor instead of plain fields. */
    kind?: 'contract' | 'children';
    fields?: ProfileField[];
}

interface PkwtRow {
    id: number;
    sequence_no: number;
    label: string;
    start_date: string;
    end_date: string;
}

interface AnakRow {
    id: number;
    sort_order: number;
    name: string;
    birth_date: string | null;
    education_level: string | null;
    employment_status: string | null;
    marital_status: string | null;
}

interface Props {
    employee: {
        id: number;
        employee_number: string;
        name: string;
        email: string | null;
        status: string;
        /** Null while waiting to be placed in Struktur Organisasi. */
        position_id: number | null;
        position: string | null;
        /** Superior position in the organisation chart and who holds it. */
        superior: { position: string; names: string[] } | null;
        job_level: string | null;
        department: string;
        work_location: string | null;
        work_location_id: number | null;
        sub_location: string | null;
        termination_reason: string | null;
        termination_date: string | null;
    };
    profile: Record<string, FieldValue>;
    computed: Record<string, FieldValue>;
    contract: PkwtRow[];
    children: AnakRow[];
    contract_expiring_soon: boolean;
    /** Digits 4–5 of the NRP disagree with the year of Tanggal Masuk. */
    employee_number_warning: string | null;
    /** A Pengajuan Update Data from Portal Karyawan waiting for HR. */
    data_change: { id: number; quantity: number } | null;
    /** The current placement, to prefill "Ubah Penempatan". */
    placement: Record<string, number | string | null>;
    sections: ProfileSection[];
    options: {
        max_contract: number;
        child_education_level: string[];
        child_employment_status: string[];
        child_marital_status: string[];
    };
}

// --- small building blocks ----------------------------------------------------------

const inputClass = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const rowInputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const addButtonClass =
    'flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition-all hover:bg-slate-50 disabled:opacity-50';

function Banner({ tone, children, onClose }: { tone: 'error' | 'warning' | 'info'; children: ReactNode; onClose?: () => void }) {
    const style = {
        error: 'border-rose-100 bg-rose-50 text-rose-600',
        warning: 'border-amber-100 bg-amber-50 text-amber-700',
        info: 'border-slate-200 bg-white text-slate-600',
    }[tone];

    return (
        <div className={`flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${style}`}>
            {tone !== 'error' && <AlertTriangle size={16} className="mt-0.5 shrink-0" />}
            <div className="flex-1">{children}</div>
            {onClose && (
                <button onClick={onClose} className="shrink-0 opacity-60 hover:opacity-100" aria-label="Tutup">
                    <X size={16} />
                </button>
            )}
        </div>
    );
}

function Label({ children }: { children: ReactNode }) {
    return <label className="mb-1.5 block text-xs font-bold text-slate-500">{children}</label>;
}

const select = (value: string | null, options: string[], onChange: (v: string) => void) => (
    <select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={rowInputClass}>
        <option value="">Pilih...</option>
        {options.map((o) => (
            <option key={o} value={o}>
                {o}
            </option>
        ))}
    </select>
);

/** Server-side changes to child rows keep the rest of the page (and unsaved edits) as they are. */
const childVisit = { preserveScroll: true, preserveState: true } as const;

// --- PKWT and children editors --------------------------------------------------------

/** One contract period; a date is saved as soon as the input loses focus. */
function ContractEditor({ row, base, onError }: { row: PkwtRow; base: string; onError: (message: string) => void }) {
    const [contract_start, setKontrak] = useState(row.start_date);
    const [ends, setBerakhir] = useState(row.end_date);

    useEffect(() => {
        setKontrak(row.start_date);
        setBerakhir(row.end_date);
    }, [row.start_date, row.end_date]);

    const save = () => {
        if (contract_start === row.start_date && ends === row.end_date) return;
        router.put(
            `${base}/contract/${row.id}`,
            { start_date: contract_start, end_date: ends },
            {
                ...childVisit,
                onError: (errors) => {
                    onError(Object.values(errors)[0] ?? 'Gagal memperbarui periode PKWT.');
                    setKontrak(row.start_date);
                    setBerakhir(row.end_date);
                },
            },
        );
    };

    const remove = () => {
        if (confirm(`Hapus ${row.label} (${fmtDate(row.start_date)} s/d ${fmtDate(row.end_date)})?`)) {
            router.delete(`${base}/contract/${row.id}`, childVisit);
        }
    };

    return (
        <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <div className="w-24">
                <Label>Periode</Label>
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700">{row.label}</div>
            </div>
            <div className="min-w-[160px] flex-1">
                <Label>Tanggal Kontrak</Label>
                <DateInput value={contract_start} onChange={setKontrak} onBlur={save} className={rowInputClass} />
            </div>
            <div className="min-w-[160px] flex-1">
                <Label>Tanggal Berakhir</Label>
                <DateInput value={ends} onChange={setBerakhir} onBlur={save} className={rowInputClass} />
            </div>
            <button
                onClick={remove}
                title="Hapus periode"
                className="rounded-xl p-2.5 text-slate-400 transition-all hover:bg-rose-50 hover:text-rose-600"
            >
                <Trash2 size={16} />
            </button>
        </div>
    );
}

/** One child; text is saved on blur, choices as soon as they change. */
function ChildrenEditor({ row, base, options, onError }: { row: AnakRow; base: string; options: Props['options']; onError: (message: string) => void }) {
    const [draft, setDraft] = useState(row);

    useEffect(() => setDraft(row), [row]);

    const save = (next: AnakRow) => {
        const { id, sort_order, ...payload } = next;
        if (JSON.stringify(next) === JSON.stringify(row)) return;
        router.put(`${base}/children/${id}`, payload, {
            ...childVisit,
            onError: (errors) => {
                onError(Object.values(errors)[0] ?? `Gagal memperbarui data anak ke-${sort_order}.`);
                setDraft(row);
            },
        });
    };

    const change = (key: keyof AnakRow, value: string, commit = false) => {
        const next = { ...draft, [key]: value === '' ? null : value };
        setDraft(next);
        if (commit) save(next);
    };

    const remove = () => {
        if (confirm(`Hapus data anak ke-${row.sort_order} (${row.name})?`)) {
            router.delete(`${base}/children/${row.id}`, childVisit);
        }
    };

    return (
        <div className="space-y-3 rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <div className="flex items-center justify-between">
                <span className="text-xs font-bold tracking-wide text-slate-500 uppercase">Anak ke-{row.sort_order}</span>
                <button
                    onClick={remove}
                    title="Hapus data anak"
                    className="rounded-lg p-2 text-slate-400 transition-all hover:bg-rose-50 hover:text-rose-600"
                >
                    <Trash2 size={14} />
                </button>
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                <div>
                    <Label>Nama</Label>
                    <input
                        type="text"
                        value={draft.name ?? ''}
                        onChange={(e) => change('name', e.target.value)}
                        onBlur={() => save(draft)}
                        className={rowInputClass}
                    />
                </div>
                <div>
                    <Label>Tanggal Lahir</Label>
                    <DateInput
                        value={draft.birth_date ?? ''}
                        onChange={(v) => change('birth_date', v)}
                        onBlur={() => save(draft)}
                        className={rowInputClass}
                    />
                </div>
                <div>
                    <Label>Pendidikan</Label>
                    {select(draft.education_level, options.child_education_level, (v) => change('education_level', v, true))}
                </div>
                <div>
                    <Label>Status Bekerja</Label>
                    {select(draft.employment_status, options.child_employment_status, (v) => change('employment_status', v, true))}
                </div>
                <div>
                    <Label>Status Menikah</Label>
                    {select(draft.marital_status, options.child_marital_status, (v) => change('marital_status', v, true))}
                </div>
            </div>
        </div>
    );
}

// --- page ------------------------------------------------------------------------------

export default function Profile({
    employee,
    profile,
    computed,
    contract,
    children,
    contract_expiring_soon: expiringSoon,
    employee_number_warning: nrpWarning,
    data_change: pengajuanUpdate,
    placement,
    sections,
    options,
}: Props) {
    const { flash } = usePage();
    const base = `/core-hr/employees/${employee.id}`;
    const form = useForm(profile);

    const [openSections, setOpenSections] = useState<Record<string, boolean>>({
        [sections[0].id]: true,
    });
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [childError, setChildError] = useState<string | null>(null);
    const [addingChild, setAddingChild] = useState(false);
    const [placing, setPlacing] = useState(false);

    const termination = employee.status === 'terminated';
    const backHref = termination ? '/core-hr/employees/termination' : '/core-hr/employees/full-data';
    const errorKeys = Object.keys(form.errors);

    // A save can come back with non-blocking warnings (account holder name, NRP year) —
    // the NRP one is also shown permanently below, so it is not repeated here.
    useEffect(() => setWarnings((flash?.warnings ?? []).filter((w) => w !== nrpWarning)), [flash, nrpWarning]);

    // Open every section that holds a validation error, so it can be found.
    useEffect(() => {
        if (errorKeys.length === 0) return;
        setOpenSections((prev) => {
            const next = { ...prev };
            sections.forEach((s) => {
                if (s.fields?.some((f) => errorKeys.includes(f.key))) next[s.id] = true;
            });
            return next;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.errors]);

    const save = () =>
        form.put(`${base}/profile`, {
            preserveScroll: true,
            onSuccess: () => {
                setSavedAt(
                    new Date().toLocaleTimeString('id-ID', {
                        hour: '2-digit',
                        minute: '2-digit',
                    }),
                );
                form.setDefaults();
            },
        });

    const addPkwt = () => {
        // Continues from the latest contract: starts the day after it ends, runs 12 months.
        const latestEnd = contract.reduce<string | null>((max, p) => (!max || p.end_date > max ? p.end_date : max), null);
        const start = latestEnd ? addDaysIso(latestEnd, 1) : todayIso();
        setChildError(null);
        router.post(
            `${base}/contract`,
            {
                start_date: start,
                end_date: endDateForMonths(start, 12),
            },
            {
                ...childVisit,
                onError: (errors) => setChildError(Object.values(errors)[0] ?? 'Gagal menambah periode PKWT.'),
            },
        );
    };

    const addAnak = () => {
        setChildError(null);
        router.post(
            `${base}/children`,
            { name: 'Anak Baru' },
            {
                ...childVisit,
                onStart: () => setAddingChild(true),
                onFinish: () => setAddingChild(false),
                onError: (errors) => setChildError(Object.values(errors)[0] ?? 'Gagal menambah data anak.'),
            },
        );
    };

    const renderField = (field: ProfileField) => {
        if (field.computed) {
            const value = computed[field.key];
            return (
                <div className="w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2.5 text-sm font-bold text-slate-600">
                    {value === undefined || value === null || value === '' ? '-' : field.key === 'age' ? `${value} tahun` : String(value)}
                </div>
            );
        }

        const value = form.data[field.key];
        const invalid = form.errors[field.key] ? 'border-rose-300 ring-1 ring-rose-200' : '';
        const set = (v: FieldValue) => form.setData(field.key, v);

        switch (field.type) {
            case 'checkbox':
                return (
                    <label className="flex items-center gap-2 px-3 py-2.5">
                        <input
                            type="checkbox"
                            checked={Boolean(value)}
                            onChange={(e) => set(e.target.checked)}
                            className="h-4 w-4 accent-brand-600"
                        />
                        <span className="text-sm text-slate-600">Ya</span>
                    </label>
                );
            case 'select':
                return (
                    <select value={(value as string) ?? ''} onChange={(e) => set(e.target.value || null)} className={`${inputClass} ${invalid}`}>
                        <option value="">Pilih...</option>
                        {field.options?.map((o) => (
                            <option key={o} value={o}>
                                {o}
                            </option>
                        ))}
                    </select>
                );
            case 'textarea':
                return (
                    <textarea
                        value={(value as string) ?? ''}
                        onChange={(e) => set(e.target.value)}
                        rows={2}
                        className={`${inputClass} resize-none ${invalid}`}
                    />
                );
            case 'date':
                return <DateInput value={(value as string) ?? ''} onChange={set} className={`${inputClass} ${invalid}`} />;
            default:
                return (
                    <input
                        type={field.type === 'number' ? 'number' : 'text'}
                        value={value === null || value === undefined ? '' : String(value)}
                        onChange={(e) => set(e.target.value)}
                        className={`${inputClass} ${invalid}`}
                    />
                );
        }
    };

    const saveButton = (icon: ReactNode) => (
        <button
            onClick={save}
            disabled={form.processing}
            className="flex shrink-0 items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-brand-100 transition-all hover:bg-brand-700 disabled:opacity-50"
        >
            {form.processing ? <Loader2 className="animate-spin" size={16} /> : icon}
            Simpan Profil
        </button>
    );

    return (
        <>
            <Head title={`Profil ${employee.name}`} />

            <header className="flex h-16 shrink-0 items-center gap-4 border-b-[3px] border-accent-400 bg-white px-8">
                <Link
                    href={backHref}
                    className="-ml-2 rounded-lg p-2 text-slate-400 transition-all hover:bg-slate-100 hover:text-slate-700"
                    title="Kembali"
                >
                    <ArrowLeft size={18} />
                </Link>
                <h1 className="text-xl font-bold tracking-tight text-slate-800">Profil Lengkap Karyawan</h1>
            </header>

            <div className="scrollbar-hide min-h-0 flex-1 overflow-y-auto bg-slate-50">
                <div className="mx-8 mt-4 space-y-4 pb-10">
                    <div className="sticky top-0 z-10 flex items-center justify-between gap-4 bg-slate-50/95 py-3 backdrop-blur-sm">
                        <div className="min-w-0">
                            <p className="truncate text-sm font-bold text-slate-700">
                                {employee.name} <span className="font-medium text-slate-400">• NRP {employee.employee_number}</span>
                                {termination && (
                                    <span className="ml-2 rounded-full border border-rose-100 bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-600">
                                        Keluar
                                    </span>
                                )}
                                {employee.status === 'inactive' && (
                                    <span className="ml-2 rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">
                                        Nonaktif
                                    </span>
                                )}
                                {!termination && !employee.position_id && (
                                    <span
                                        title="Jabatannya belum ada di Plan SO, atau belum ditempatkan di Struktur Organisasi"
                                        className="ml-2 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700"
                                    >
                                        Belum ditempatkan
                                    </span>
                                )}
                            </p>
                            <p className="truncate text-[11px] text-slate-400">
                                {employee.position ?? 'Belum menjabat'}
                                {employee.job_level ? ` (${employee.job_level})` : ''}
                                {employee.department ? ` • ${employee.department}` : ''} • {employee.work_location}
                                {employee.sub_location ? ` · ${employee.sub_location}` : ''}
                                {employee.superior && (
                                    <>
                                        {' '}
                                        • Atasan: {employee.superior.position}
                                        {employee.superior.names.length ? ` (${employee.superior.names.join(', ')})` : ' (kosong)'}
                                    </>
                                )}{' '}
                                •{' '}
                                <Link
                                    href={`/core-hr/org-chart?location=${employee.work_location_id ?? ''}${employee.position_id ? `&focus=${employee.position_id}` : ''}`}
                                    className="inline-flex items-center gap-0.5 font-bold text-brand-500 hover:text-brand-700"
                                >
                                    <Network size={10} />
                                    {employee.position_id ? 'Lihat di bagan' : 'Tempatkan di bagan'}
                                </Link>
                                {!termination && (
                                    <>
                                        {' '}
                                        •{' '}
                                        <button
                                            onClick={() => setPlacing(true)}
                                            className="inline-flex items-center gap-0.5 font-bold text-brand-500 hover:text-brand-700"
                                        >
                                            <MapPin size={10} />
                                            Ubah Penempatan
                                        </button>
                                    </>
                                )}
                            </p>
                            {form.isDirty ? (
                                <p className="text-[11px] font-bold text-amber-600">Ada perubahan yang belum disimpan</p>
                            ) : (
                                savedAt && <p className="text-[11px] font-bold text-emerald-600">Tersimpan {savedAt}</p>
                            )}
                        </div>
                        {saveButton(<Save size={16} />)}
                    </div>

                    {placing && (
                        <PlacementDialog
                            title="Ubah Penempatan"
                            subtitle={`${employee.name} • sekarang ${employee.position} (${employee.work_location})`}
                            initial={placementFrom(placement)}
                            method="put"
                            url={`${base}/placement`}
                            onClose={() => setPlacing(false)}
                        />
                    )}

                    {termination && (
                        <Banner tone="info">
                            <span className="flex items-center gap-2">
                                <UserX size={16} className="shrink-0 text-rose-500" />
                                Karyawan ini tercatat keluar ({employee.termination_reason}, {fmtDate(employee.termination_date)}). Datanya tetap bisa
                                dilengkapi.
                            </span>
                        </Banner>
                    )}

                    {errorKeys.length > 0 && (
                        <Banner tone="error">
                            Profil belum tersimpan — periksa {errorKeys.length} isian yang ditandai merah:
                            <ul className="mt-1 list-disc pl-5 text-xs">
                                {errorKeys.slice(0, 5).map((key) => (
                                    <li key={key}>{form.errors[key]}</li>
                                ))}
                            </ul>
                        </Banner>
                    )}

                    {childError && (
                        <Banner tone="error" onClose={() => setChildError(null)}>
                            {childError}
                        </Banner>
                    )}

                    {warnings.map((w, i) => (
                        <Banner key={i} tone="warning" onClose={() => setWarnings((prev) => prev.filter((_, j) => j !== i))}>
                            {w}
                        </Banner>
                    ))}

                    {expiringSoon && <Banner tone="warning">Periode PKWT terakhir berakhir dalam 30 hari ke depan.</Banner>}

                    {nrpWarning && <Banner tone="warning">{nrpWarning}</Banner>}
                    {pengajuanUpdate && (
                        <Banner tone="info">
                            Karyawan ini mengajukan {pengajuanUpdate.quantity} perubahan data lewat Portal Karyawan.{' '}
                            <Link href="/core-hr/change-requests" className="font-bold underline">
                                Periksa pengajuan
                            </Link>
                        </Banner>
                    )}

                    {sections.map((section) => {
                        const isOpen = !!openSections[section.id];
                        const hasError = section.fields?.some((f) => form.errors[f.key]);

                        return (
                            <div key={section.id} className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                                <button
                                    onClick={() =>
                                        setOpenSections((prev) => ({
                                            ...prev,
                                            [section.id]: !prev[section.id],
                                        }))
                                    }
                                    className="flex w-full items-center justify-between px-6 py-4 transition-colors hover:bg-slate-50/80"
                                    aria-expanded={isOpen}
                                >
                                    <span className="text-sm font-bold text-slate-800">
                                        {section.title}
                                        {section.kind === 'contract' && (
                                            <span className="ml-2 text-xs font-medium text-slate-400">({contract.length} periode)</span>
                                        )}
                                        {section.kind === 'children' && (
                                            <span className="ml-2 text-xs font-medium text-slate-400">({children.length} anak)</span>
                                        )}
                                        {hasError && <span className="ml-2 text-xs font-bold text-rose-500">• ada isian yang salah</span>}
                                    </span>
                                    <ChevronDown size={18} className={`text-slate-400 transition-transform ${isOpen ? '' : '-rotate-90'}`} />
                                </button>

                                {isOpen && (
                                    <div className="border-t border-slate-100 px-6 pt-5 pb-6">
                                        {section.kind === 'contract' ? (
                                            <div className="space-y-3">
                                                {contract.length === 0 && <p className="text-sm text-slate-400 italic">Belum ada periode PKWT.</p>}
                                                {contract.map((p) => (
                                                    <ContractEditor key={p.id} row={p} base={base} onError={setChildError} />
                                                ))}
                                                <div className="flex items-center gap-3">
                                                    <button onClick={addPkwt} disabled={contract.length >= options.max_contract} className={addButtonClass}>
                                                        <Plus size={16} />
                                                        Tambah Periode
                                                    </button>
                                                    <span className="text-[11px] text-slate-400">
                                                        {contract.length >= options.max_contract
                                                            ? `Sudah ${options.max_contract} periode — gunakan Pengangkatan Pegawai untuk status tetap.`
                                                            : 'Periode baru dimulai sehari setelah kontrak terakhir, 12 bulan; tanggalnya bisa diubah.'}
                                                    </span>
                                                </div>
                                            </div>
                                        ) : section.kind === 'children' ? (
                                            <div className="space-y-3">
                                                {children.length === 0 && <p className="text-sm text-slate-400 italic">Belum ada data anak.</p>}
                                                {children.map((a) => (
                                                    <ChildrenEditor key={a.id} row={a} base={base} options={options} onError={setChildError} />
                                                ))}
                                                <button onClick={addAnak} disabled={addingChild} className={addButtonClass}>
                                                    {addingChild ? <Loader2 className="animate-spin" size={16} /> : <Plus size={16} />}
                                                    Tambah Anak
                                                </button>
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                                {section.fields?.map((field) => (
                                                    <div key={field.key}>
                                                        <Label>
                                                            {field.label}
                                                            {field.computed && (
                                                                <span className="ml-1.5 text-[10px] font-bold text-brand-500 normal-case">
                                                                    (otomatis)
                                                                </span>
                                                            )}
                                                        </Label>
                                                        {renderField(field)}
                                                        {form.errors[field.key] ? (
                                                            <p className="mt-1 text-[11px] text-rose-600">{form.errors[field.key]}</p>
                                                        ) : (
                                                            field.hint && <p className="mt-1 text-[11px] text-slate-400">{field.hint}</p>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}

                    <div className="flex justify-end pt-2">{saveButton(<CheckCircle2 size={16} />)}</div>
                </div>
            </div>
        </>
    );
}
