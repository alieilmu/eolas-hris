import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Glasses, Loader2, Lock, Plus, Save, Stethoscope, X } from 'lucide-react';
import { BrandLogo, useBranding } from '@/components/BrandLogo';
import { DateInput } from '@/components/DateInput';
import { MoneyInput } from '@/components/compensation/MoneyInput';
import { Modal, ghostButton, primaryButton } from '@/components/time/ui';
import { AttachmentPicker } from '@/components/attachment/AttachmentPicker';
import { JENIS_ROWS, rupiah, type DeklarasiRow, type JenisItem, type PlafonInfo } from '@/lib/compensation';
import { fmtDate } from '@/lib/format';
import { draftPayload, emptyDraft, notaCount, type LampiranLimits } from '@/lib/attachment';
import { todayIso } from '@/lib/contractDates';

export interface DeklarasiPerson {
    id: number;
    employee_number: string;
    name: string;
    position: string | null;
}

/** A column of the form: the employee, the spouse, or one child; uid keeps its amounts when columns are removed. */
type Column = { uid: number } & ({ beneficiary: 'employee' } | { beneficiary: 'spouse' } | { beneficiary: 'children'; childId: number | null });

const cell = 'border border-slate-300 px-2 py-1.5';
const input =
    'w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-slate-100';

function columnsFor(claim?: DeklarasiRow): Column[] {
    const children = [...new Set((claim?.items ?? []).filter((i) => i.beneficiary === 'children').map((i) => i.employee_child_id))];
    return [
        { uid: 0, beneficiary: 'employee' },
        { uid: 1, beneficiary: 'spouse' },
        ...(children.length ? children : [null]).map((id, i) => ({ uid: 2 + i, beneficiary: 'children' as const, childId: id })),
    ];
}

const keyOf = (type: JenisItem, uid: number) => `${type}|${uid}`;

/**
 * Deklarasi Pengobatan / Kacamata as on the paper form: who, which receipts, and per
 * row (Perawatan, Pengobatan, Kacamata bingkai / lensa) the amount for the employee,
 * the spouse and each child — checked live against what is left of the plafon.
 */
export function BenefitClaimForm({
    people,
    providerOptions,
    claim,
    self,
    limits,
    onClose,
}: {
    people: DeklarasiPerson[];
    providerOptions: string[];
    claim?: DeklarasiRow;
    /** The employee portal: the employee's own deklarasi, sent to HR for verification, dated today (`tanggal`) and not changeable. */
    self?: { person: DeklarasiPerson; infoUrl: string; submitUrl: string; date: string };
    /** Upload limits of the server, for the nota photos / PDFs. */
    limits: LampiranLimits;
    onClose: () => void;
}) {
    const branding = useBranding();
    const [employee_number, setNrp] = useState(self?.person.employee_number ?? claim?.employee_number ?? '');
    const [date, setTanggal] = useState(self?.date ?? claim?.date ?? todayIso());
    const [provider_name, setTempat] = useState(claim?.provider_name ?? '');
    // The nota: each photo and each PDF page counts as one kwitansi.
    const [attachment, setLampiran] = useState(() => emptyDraft(claim?.attachment ?? []));
    const receipt = notaCount(attachment);
    const [description, setKeterangan] = useState(claim?.description ?? '');
    const [columns, setColumns] = useState<Column[]>(() => columnsFor(claim));
    const [values, setValues] = useState<Record<string, number | null>>(() => {
        const cols = columnsFor(claim);
        const init: Record<string, number | null> = {};
        for (const item of claim?.items ?? []) {
            const col = cols.find((c) => c.beneficiary === item.beneficiary && (c.beneficiary !== 'children' || c.childId === item.employee_child_id));
            if (col) init[keyOf(item.type, col.uid)] = (init[keyOf(item.type, col.uid)] ?? 0) + item.amount;
        }
        return init;
    });
    const [info, setInfo] = useState<PlafonInfo | null>(null);
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);

    const person = useMemo(() => people.find((p) => p.employee_number === employee_number.trim()), [people, employee_number]);
    const employeeId = person?.id ?? (claim && claim.employee_number === employee_number.trim() ? claim.employee_id : undefined);

    // Who the employee is, the family and what is left — for this date's plafon year.
    useEffect(() => {
        if (!employeeId || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            setInfo(null);
            return;
        }
        const controller = new AbortController();
        const params = new URLSearchParams({ date });
        if (claim) params.set('except', String(claim.id));
        setLoading(true);
        const url = self ? `${self.infoUrl}?${params}` : `/compensation/employee/${employeeId}/benefit-limit?${params}`;
        fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((r) => r.json())
            .then((data: PlafonInfo) => setInfo(data))
            .catch(() => {})
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [employeeId, date, claim]);

    const members = info?.optical ?? [];
    const children = members.filter((m) => m.beneficiary === 'children');
    const hasSpouse = members.some((m) => m.beneficiary === 'spouse');

    // A new employee: the first child goes in the first Anak column.
    useEffect(() => {
        if (!info) return;
        setColumns((cols) =>
            cols.map((c) => (c.beneficiary === 'children' && c.childId === null && children[0] ? { ...c, childId: children[0].child_id } : c)),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [info?.employee.id]);

    const enabled = (c: Column) => (c.beneficiary === 'spouse' ? hasSpouse : c.beneficiary === 'children' ? c.childId !== null : true);
    const amount = (type: JenisItem, col: number) => (enabled(columns[col]) ? (values[keyOf(type, columns[col].uid)] ?? 0) : 0);
    const columnTotal = (col: number) => JENIS_ROWS.reduce((n, r) => n + amount(r.value, col), 0);
    const rowTotal = (type: JenisItem) => columns.reduce((n, _c, i) => n + amount(type, i), 0);
    const grandTotal = columns.reduce((n, _c, i) => n + columnTotal(i), 0);
    const pengobatanClaim = rowTotal('treatment') + rowTotal('medical');

    const memberKey = (c: Column) => (c.beneficiary === 'children' ? `children:${c.childId}` : c.beneficiary);
    const kacamataClaim = (key: string, type: 'frame' | 'lens') =>
        columns.reduce((n, c, i) => n + (memberKey(c) === key ? amount(type, i) : 0), 0);

    const overPengobatan = info && info.medical.remaining !== null && pengobatanClaim > info.medical.remaining;
    const overKacamata = members.some((m) =>
        (['frame', 'lens'] as const).some((j) => m[j].remaining !== null && kacamataClaim(m.key, j) > (m[j].remaining ?? 0)),
    );

    const submit = () => {
        if (!employeeId) return;
        const items = columns.flatMap((c, i) =>
            JENIS_ROWS.flatMap((r) => {
                const claimed = amount(r.value, i);
                return claimed > 0
                    ? [{ type: r.value, beneficiary: c.beneficiary, employee_child_id: c.beneficiary === 'children' ? c.childId : null, amount: claimed }]
                    : [];
            }),
        );
        const data = { employee_id: employeeId, date, provider_name, description: description || null, items, ...draftPayload(attachment) } as never;
        const options = {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: onClose,
            onError: (e: Record<string, string>) => setErrors(Object.values(e)),
        };
        setErrors([]);
        if (self) router.post(self.submitUrl, data, options);
        // Files go as multipart, which PHP only reads on POST: the update is a POST with _method=put.
        else if (claim) router.post(`/compensation/claims/${claim.id}`, { ...(data as object), _method: 'put' } as never, options);
        else router.post('/compensation/claims', data, options);
    };

    const header = (c: Column, i: number) => {
        if (c.beneficiary === 'employee') return 'KARYAWAN';
        if (c.beneficiary === 'spouse') return 'ISTRI / SUAMI';
        return (
            <div className="flex items-center gap-1">
                <select
                    value={c.childId ?? ''}
                    onChange={(e) =>
                        setColumns((cols) =>
                            cols.map((col, j) =>
                                j === i ? { uid: col.uid, beneficiary: 'children', childId: e.target.value ? Number(e.target.value) : null } : col,
                            ),
                        )
                    }
                    disabled={!children.length}
                    aria-label="Pilih anak"
                    className="w-full min-w-0 rounded-md border border-slate-300 bg-white px-1.5 py-1 text-[11px] font-bold text-slate-700 normal-case"
                >
                    {!children.length && <option value="">ANAK (tidak ada)</option>}
                    {children.map((m) => (
                        <option key={m.key} value={m.child_id ?? ''}>
                            {m.relationship}: {m.name}
                        </option>
                    ))}
                </select>
                {columns.filter((x) => x.beneficiary === 'children').length > 1 && (
                    <button
                        onClick={() => {
                            setColumns((cols) => cols.filter((_x, j) => j !== i));
                            setValues((v) => Object.fromEntries(Object.entries(v).filter(([k]) => !k.endsWith(`|${c.uid}`))));
                        }}
                        title="Hapus kolom anak"
                        className="rounded p-0.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                    >
                        <X size={12} />
                    </button>
                )}
            </div>
        );
    };

    return (
        <Modal
            title={self ? 'Ajukan Deklarasi Pengobatan / Kacamata' : claim ? `Ubah Deklarasi ${claim.claim_number}` : 'Deklarasi Baru'}
            subtitle={
                self
                    ? 'Dikirim ke HR untuk diverifikasi; nominalnya langsung ditahan dari sisa plafon. Lampirkan foto / PDF semua nota.'
                    : 'Isi seperti formulir kertas dan lampirkan foto / PDF nota; sisa plafon dihitung langsung.'
            }
            onClose={onClose}
            width="max-w-5xl"
            footer={
                <>
                    <button onClick={onClose} className={ghostButton}>
                        Batal
                    </button>
                    <button
                        onClick={submit}
                        disabled={!employeeId || !grandTotal || !receipt || saving || !!overPengobatan || overKacamata}
                        className={primaryButton}
                    >
                        {saving ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                        {self ? 'Kirim ke HR' : 'Simpan deklarasi'}
                    </button>
                </>
            }
        >
            <div className="grid gap-5 lg:grid-cols-[1fr_280px] [&>*]:min-w-0">
                {/* The form */}
                <div className="space-y-4 rounded-2xl border border-slate-300 bg-white p-3 sm:p-5">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-slate-200 pb-3">
                        <BrandLogo className="h-7" />
                        <div className="leading-tight">
                            <p className="text-[11px] font-bold text-slate-500 uppercase">{branding.company_name ?? branding.app_name}</p>
                            {branding.tagline && <p className="text-[10px] text-slate-400">{branding.tagline}</p>}
                        </div>
                        <h3 className="w-full text-sm font-black tracking-wide text-slate-800 sm:ml-auto sm:w-auto">
                            DEKLARASI PENGOBATAN / KACAMATA
                        </h3>
                    </div>

                    <div className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        <Line label="Nama Karyawan">
                            <span className={`block px-1 py-1.5 text-sm font-bold ${info ? 'text-slate-800' : 'text-slate-300'}`}>
                                {info?.employee.name ?? person?.name ?? '— isi NRP —'}
                            </span>
                        </Line>
                        <Line label="Departemen">
                            <span className="block px-1 py-1.5 text-sm text-slate-700">{info?.employee.department ?? '-'}</span>
                        </Line>
                        <Line label="NRP / Jabatan">
                            <div className="flex items-center gap-2 py-1">
                                <input
                                    list="claim-people"
                                    value={employee_number}
                                    onChange={(e) => setNrp(e.target.value.replace(/\s.*$/, ''))}
                                    placeholder="Ketik NRP / nama"
                                    autoComplete="off"
                                    autoFocus={!claim && !self}
                                    readOnly={!!self}
                                    className={`${input} min-w-0 flex-1 font-mono`}
                                />
                                {loading && <Loader2 size={14} className="shrink-0 animate-spin text-slate-400" />}
                            </div>
                            <p className="truncate px-1 pb-1 text-[11px] text-slate-500" title={info?.employee.position ?? undefined}>
                                {info?.employee.position ?? person?.position ?? 'Jabatan muncul setelah NRP diisi'}
                            </p>
                            <datalist id="claim-people">
                                {people.map((p) => (
                                    <option key={p.id} value={p.employee_number}>
                                        {p.name}
                                        {p.position ? ` — ${p.position}` : ''}
                                    </option>
                                ))}
                            </datalist>
                        </Line>
                        <Line label="Jumlah Kwitansi">
                            <span className="flex items-center gap-2 px-1 py-1.5 text-sm">
                                <b className={receipt ? 'text-slate-800' : 'text-slate-300'}>{receipt}</b>
                                <span className="text-[11px] text-slate-400">otomatis dari lampiran nota</span>
                            </span>
                        </Line>
                    </div>

                    {info?.warning && (
                        <p className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <AlertTriangle size={14} className="mt-0.5 shrink-0" />
                            {info.warning}
                        </p>
                    )}

                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="bg-slate-200 text-xs font-black text-slate-700">
                                    <th className={`${cell} w-44 text-left`}>JENIS</th>
                                    {columns.map((c, i) => (
                                        <th key={c.uid} className={`${cell} min-w-28 sm:min-w-36 ${enabled(c) ? '' : 'text-slate-400'}`}>
                                            {header(c, i)}
                                        </th>
                                    ))}
                                    <th className={`${cell} w-10 bg-slate-100`}>
                                        <button
                                            onClick={() =>
                                                setColumns((cols) => [
                                                    ...cols,
                                                    { uid: Math.max(...cols.map((x) => x.uid)) + 1, beneficiary: 'children', childId: null },
                                                ])
                                            }
                                            disabled={children.length <= columns.filter((c) => c.beneficiary === 'children').length}
                                            title="Tambah kolom anak"
                                            className="rounded p-1 text-brand-600 hover:bg-white disabled:opacity-30"
                                        >
                                            <Plus size={14} />
                                        </button>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {JENIS_ROWS.map((row) => (
                                    <tr key={row.value}>
                                        <td className={`${cell} font-bold text-slate-700`}>
                                            <span className="flex items-center gap-1.5">
                                                {row.benefit_limit === 'medical' ? (
                                                    <Stethoscope size={13} className="text-emerald-600" />
                                                ) : (
                                                    <Glasses size={13} className="text-sky-600" />
                                                )}
                                                {row.label}
                                            </span>
                                        </td>
                                        {columns.map((c, i) => (
                                            <td key={c.uid} className={`${cell} ${enabled(c) ? '' : 'bg-slate-50'}`}>
                                                {enabled(c) ? (
                                                    <MoneyInput
                                                        value={values[keyOf(row.value, c.uid)] ?? null}
                                                        onChange={(v) => setValues((prev) => ({ ...prev, [keyOf(row.value, c.uid)]: v }))}
                                                        aria-label={`${row.label} ${c.beneficiary}`}
                                                        className="w-full rounded-md border-0 bg-transparent px-1 py-0.5 outline-none focus:bg-brand-50 focus:ring-2 focus:ring-brand-500"
                                                    />
                                                ) : (
                                                    <span className="block text-center text-slate-300">—</span>
                                                )}
                                            </td>
                                        ))}
                                        <td className={`${cell} bg-slate-50`} />
                                    </tr>
                                ))}
                                <tr className="bg-slate-50 font-black text-slate-800">
                                    <td className={cell}>TOTAL</td>
                                    {columns.map((_c, i) => (
                                        <td key={i} className={`${cell} text-right tabular-nums`}>
                                            {columnTotal(i) ? rupiah(columnTotal(i)) : ''}
                                        </td>
                                    ))}
                                    <td className={`${cell} text-right whitespace-nowrap text-brand-700 tabular-nums`}>{rupiah(grandTotal)}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <AttachmentPicker
                        value={attachment}
                        onChange={setLampiran}
                        limits={limits}
                        label="Foto / PDF nota"
                        countLabel="nota"
                        hint="Satu foto = satu nota; PDF dihitung per halaman."
                    />

                    <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                        <div className="space-y-2">
                            <input
                                value={description}
                                onChange={(e) => setKeterangan(e.target.value)}
                                placeholder="Keterangan (opsional) — mis. nama klinik / apotek"
                                className={input}
                            />
                            <div className="grid grid-cols-3 gap-2 pt-2 text-center text-[11px] text-slate-400">
                                {['Diajukan oleh,', 'Diketahui oleh,', 'Disetujui oleh,'].map((s) => (
                                    <div key={s} className="h-14 rounded-lg border border-dashed border-slate-200 pt-1">
                                        {s}
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="w-60 space-y-1.5">
                            <div className="flex items-center gap-1.5">
                                <input
                                    list="claim-provider_name"
                                    value={provider_name}
                                    onChange={(e) => setTempat(e.target.value)}
                                    placeholder="Tempat"
                                    className={`${input} flex-1`}
                                />
                                <span className="text-slate-400">,</span>
                            </div>
                            <datalist id="claim-provider_name">
                                {providerOptions.map((t) => (
                                    <option key={t} value={t} />
                                ))}
                            </datalist>
                            {self ? (
                                <p
                                    className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-100 px-2.5 py-1.5 text-sm font-bold text-slate-700 tabular-nums"
                                    aria-label="Tanggal deklarasi"
                                    title="Tanggal deklarasi mengikuti tanggal hari ini"
                                >
                                    {fmtDate(date)}
                                    <span className="flex items-center gap-1 text-[10px] font-semibold text-slate-400">
                                        <Lock size={11} /> hari ini
                                    </span>
                                </p>
                            ) : (
                                <DateInput value={date} onChange={setTanggal} className={input} aria-label="Tanggal deklarasi" />
                            )}
                            {info && (
                                <p className="text-[11px] text-slate-500">
                                    Bulan buku <b className="text-slate-700">{info.accounting_month.label}</b>
                                    <span className="block text-slate-400">({info.accounting_month.span})</span>
                                </p>
                            )}
                        </div>
                    </div>

                    {errors.length > 0 && (
                        <div className="space-y-1 rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            {errors.map((e, i) => (
                                <p key={i}>{e}</p>
                            ))}
                        </div>
                    )}
                </div>

                {/* What is left */}
                <aside className="space-y-3">
                    {!info ? (
                        <p className="rounded-2xl border border-dashed border-slate-200 p-4 text-center text-xs text-slate-400">
                            Isi NRP untuk melihat sisa plafon.
                        </p>
                    ) : (
                        <>
                            <div className="rounded-2xl border border-slate-200 p-4">
                                <p className="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                                    <Stethoscope size={14} className="text-emerald-600" />
                                    Plafon Pengobatan {info.year}
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-400">
                                    {info.ptkp_status} · {info.family ? 'Keluarga' : 'Lajang'}
                                    {info.medical.layer && ` · ${info.medical.layer}`}
                                </p>
                                <Remaining allowance={info.medical} claim={pengobatanClaim} />
                            </div>
                            <div className="rounded-2xl border border-slate-200 p-4">
                                <p className="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                                    <Glasses size={14} className="text-sky-600" />
                                    Plafon Kacamata (per anggota)
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-400">Bingkai 2 tahun sekali · lensa 1 tahun sekali</p>
                                <div className="mt-2 space-y-2.5">
                                    {members.map((m) => (
                                        <div key={m.key} className="border-t border-slate-100 pt-2">
                                            <p className="truncate text-xs font-bold text-slate-700" title={m.name}>
                                                {m.name} <span className="font-normal text-slate-400">· {m.relationship}</span>
                                            </p>
                                            <MiniRemaining label="Bingkai" allowance={m.frame} claim={kacamataClaim(m.key, 'frame')} />
                                            <MiniRemaining label="Lensa" allowance={m.lens} claim={kacamataClaim(m.key, 'lens')} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </>
                    )}
                </aside>
            </div>
        </Modal>
    );
}

function Line({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-[96px_minmax(0,1fr)] items-center gap-2 sm:grid-cols-[120px_minmax(0,1fr)]">
            <span className="text-xs font-bold text-slate-500">{label}</span>
            <div className="min-w-0 rounded-lg border border-slate-300 px-1">{children}</div>
        </div>
    );
}

function Remaining({
    allowance,
    claim,
}: {
    allowance: { benefit_limit: number | null; used: number; pending?: number; remaining: number | null };
    claim: number;
}) {
    if (allowance.benefit_limit === null) return <p className="mt-2 text-xs text-amber-700">Belum ada plafon untuk SubGroup ini.</p>;
    const after = (allowance.remaining ?? 0) - claim;
    const used = Math.min(100, ((allowance.used + (allowance.pending ?? 0) + claim) / Math.max(allowance.benefit_limit, 1)) * 100);
    return (
        <div className="mt-2 space-y-1.5 text-xs">
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${after < 0 ? 'bg-rose-500' : 'bg-emerald-500'}`} style={{ width: `${used}%` }} />
            </div>
            <Row label="Plafon" value={rupiah(allowance.benefit_limit)} />
            <Row label="Sudah dipakai" value={rupiah(allowance.used)} />
            {!!allowance.pending && <Row label="Menunggu verifikasi" value={rupiah(allowance.pending)} tone="text-amber-700" />}
            <Row label="Deklarasi ini" value={rupiah(claim)} />
            <Row label="Sisa setelah ini" value={rupiah(after)} strong tone={after < 0 ? 'text-rose-600' : 'text-emerald-700'} />
        </div>
    );
}

function MiniRemaining({
    label,
    allowance,
    claim,
}: {
    label: string;
    allowance: { benefit_limit: number | null; remaining: number | null; last?: string | null };
    claim: number;
}) {
    if (allowance.benefit_limit === null) return null;
    const after = (allowance.remaining ?? 0) - claim;
    return (
        <div className="mt-0.5 flex justify-between text-[11px]">
            <span className="text-slate-500">
                {label}
                {allowance.last && <span className="text-slate-400"> (terakhir {allowance.last.slice(0, 4)})</span>}
            </span>
            <span className={`font-bold tabular-nums ${after < 0 ? 'text-rose-600' : claim ? 'text-emerald-700' : 'text-slate-600'}`}>
                {rupiah(after)} / {rupiah(allowance.benefit_limit)}
            </span>
        </div>
    );
}

function Row({ label, value, strong, tone = 'text-slate-700' }: { label: string; value: string; strong?: boolean; tone?: string }) {
    return (
        <div className="flex justify-between">
            <span className="text-slate-500">{label}</span>
            <span className={`tabular-nums ${strong ? 'font-black' : 'font-bold'} ${tone}`}>{value}</span>
        </div>
    );
}
