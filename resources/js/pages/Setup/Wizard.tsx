import { useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Building2, Check, CheckCircle2, ChevronRight, FileSpreadsheet, Hash, Loader2, Network, PartyPopper, SkipForward, Upload } from 'lucide-react';
import { CrudTable } from '@/components/settings/CrudTable';
import { PositionImport } from '@/components/settings/PositionImport';
import { EmployeeImportDialog } from '@/components/core_hr/EmployeeImportDialog';
import { EmployeeNumberFormatEditor, type FormatProps } from '@/components/core_hr/EmployeeNumberFormatEditor';
import { segmentPositions } from '@/lib/employee_number';
import type { ColumnGroup } from '@/types/core_hr';

type StepId = 'company' | 'employee_number' | 'organization' | 'migration';
type State = 'done' | 'skipped';

interface WorkLocationRow {
    id: number;
    name: string;
    code: string | null;
    type: string;
    employee_count: number;
    location_count: number;
    position_count: number;
}

interface Props {
    setup: { status: string; steps: Partial<Record<StepId, State>> };
    step_titles: Record<StepId, string>;
    step: StepId | 'done';
    company: { company_name: string | null; tagline: string | null; brand_color: string | null; accent_color: string | null; logo_url: string | null };
    employee_number_format: FormatProps;
    type: { id: number; code: string; name: string; employee_count: number }[];
    location: { id: number; code: string; name: string; work_location_id: number | null }[];
    work_locations: WorkLocationRow[];
    outline: string;
    job_levels: { id: number; name: string; layer: number; source: string }[];
    standard_job_levels: { name: string; layer: number }[];
    counts: { employees: number; positions: number; work_locations: number };
    groups: ColumnGroup[];
}

const ICONS: Record<StepId, typeof Hash> = { company: Building2, employee_number: Hash, organization: Network, migration: FileSpreadsheet };
const visit = { preserveScroll: true, preserveState: true } as const;
const card = 'rounded-3xl border border-slate-200 bg-white p-6 shadow-sm';
const input = 'w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-brand-500';
const primary = 'inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-brand-700 disabled:opacity-50';
const ghost = 'inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold text-slate-500 hover:bg-slate-100';
const str = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));

const mark = (step: StepId, state: State) => router.post(`/setup/steps/${step}`, { state });

export default function SetupWizard(props: Props) {
    const steps = Object.keys(props.step_titles) as StepId[];

    return (
        <>
            <Head title="Sesi Awal" />
            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50">
                <div className="mx-auto max-w-5xl space-y-5 p-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h1 className="text-xl font-bold text-slate-800">Sesi Awal</h1>
                            <p className="mt-0.5 text-sm text-slate-500">
                                Siapkan data dasar perusahaan Anda. Setiap langkah bisa dilewati dan diubah lagi kapan saja di menu Pengaturan.
                            </p>
                        </div>
                        <button onClick={() => confirm('Lewati semua langkah sesi awal? Anda bisa membukanya lagi dari Administrasi → Sesi Awal.') && router.post('/setup/dismiss')} className={ghost}>
                            <SkipForward size={15} /> Lewati semua
                        </button>
                    </div>

                    <ol className="flex flex-wrap gap-2">
                        {[...steps, 'done' as const].map((id, i) => {
                            const state = id === 'done' ? undefined : props.setup.steps[id];
                            const Icon = id === 'done' ? PartyPopper : ICONS[id];
                            const active = props.step === id;
                            return (
                                <li key={id}>
                                    <button
                                        onClick={() => router.get('/setup', { step: id }, visit)}
                                        className={`flex items-center gap-2 rounded-2xl border px-3 py-2 text-sm font-bold ${
                                            active ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'
                                        }`}
                                    >
                                        <span
                                            className={`flex h-6 w-6 items-center justify-center rounded-full text-xs ${
                                                state === 'done' ? 'bg-emerald-500 text-white' : state === 'skipped' ? 'bg-slate-300 text-white' : 'bg-slate-100 text-slate-500'
                                            }`}
                                        >
                                            {state === 'done' ? <Check size={13} /> : state === 'skipped' ? <SkipForward size={11} /> : i + 1}
                                        </span>
                                        <Icon size={15} />
                                        {id === 'done' ? 'Selesai' : props.step_titles[id]}
                                    </button>
                                </li>
                            );
                        })}
                    </ol>

                    {props.step === 'company' && <CompanyStep {...props} />}
                    {props.step === 'employee_number' && <EmployeeNumberStep {...props} />}
                    {props.step === 'organization' && <OrganizationStep {...props} />}
                    {props.step === 'migration' && <MigrationStep {...props} />}
                    {props.step === 'done' && <DoneStep {...props} />}
                </div>
            </div>
        </>
    );
}

function StepCard({ step, title, intro, children, onNext, nextLabel = 'Lanjut', busy }: { step: StepId; title: string; intro: ReactNode; children: ReactNode; onNext?: () => void; nextLabel?: string; busy?: boolean }) {
    return (
        <section className={`${card} space-y-5`}>
            <div>
                <h2 className="text-lg font-bold text-slate-800">{title}</h2>
                <div className="mt-1 text-sm text-slate-500">{intro}</div>
            </div>
            {children}
            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                <button onClick={() => mark(step, 'skipped')} className={ghost}>
                    Lewati langkah ini
                </button>
                <button onClick={onNext ?? (() => mark(step, 'done'))} disabled={busy} className={primary}>
                    {busy ? <Loader2 size={15} className="animate-spin" /> : <ChevronRight size={15} />} {nextLabel}
                </button>
            </div>
        </section>
    );
}

// --- 1. Profil perusahaan --------------------------------------------------------------------

function CompanyStep({ company }: Props) {
    const [form, setForm] = useState({
        company_name: company.company_name ?? '',
        tagline: company.tagline ?? '',
        brand_color: company.brand_color ?? '#15803d',
        accent_color: company.accent_color ?? '#86efac',
    });
    const [logo, setLogo] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const save = () =>
        router.post('/settings/branding', { ...form, ...(logo ? { logo } : {}) } as never, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
            onSuccess: () => mark('company', 'done'),
        });

    return (
        <StepCard step="company" title="Profil perusahaan" intro="Nama dan warna ini tampil di seluruh aplikasi, login, dan slip. Logo boleh menyusul." onNext={save} nextLabel="Simpan & lanjut" busy={busy}>
            <div className="grid gap-4 sm:grid-cols-2">
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Nama perusahaan *</span>
                    <input value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} className={input} />
                </label>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Tagline</span>
                    <input value={form.tagline} onChange={(e) => setForm({ ...form, tagline: e.target.value })} placeholder="mis. Garmen ekspor sejak 1998" className={input} />
                </label>
                <div className="flex gap-6">
                    {(['brand_color', 'accent_color'] as const).map((k) => (
                        <label key={k} className="block">
                            <span className="mb-1.5 block text-xs font-bold text-slate-500">{k === 'brand_color' ? 'Warna utama' : 'Warna aksen'}</span>
                            <input type="color" value={form[k]} onChange={(e) => setForm({ ...form, [k]: e.target.value })} className="h-10 w-20 cursor-pointer rounded-lg border border-slate-200" />
                        </label>
                    ))}
                </div>
                <label className="block">
                    <span className="mb-1.5 block text-xs font-bold text-slate-500">Logo (PNG/JPG/WEBP, maks. 2 MB)</span>
                    <div className="flex items-center gap-3">
                        {company.logo_url && !logo && <img src={company.logo_url} alt="" className="h-10 w-10 rounded-lg object-contain" />}
                        <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => setLogo(e.target.files?.[0] ?? null)} className="text-sm" />
                    </div>
                </label>
            </div>
            {error && <p className="rounded-xl bg-rose-50 px-4 py-2 text-sm text-rose-600">{error}</p>}
        </StepCard>
    );
}

// --- 2. Nomor Induk --------------------------------------------------------------------------

function EmployeeNumberStep({ employee_number_format: format, type, location, work_locations }: Props) {
    const has = (t: 'category' | 'point_of_hire') => format.mode === 'pattern' && format.segments.some((s) => s.type === t);
    const where = (t: 'category' | 'point_of_hire') => segmentPositions(format).find((p) => p.segment.type === t);

    return (
        <StepCard
            step="employee_number"
            title="Format Nomor Induk (NRP)"
            intro={
                <>
                    Atur susunan NRP sesuai kebiasaan perusahaan — mis. diawali <b>TY</b>, kode jenis karyawan dari huruf, tahun masuk 4 digit — atau pilih
                    <b> Bebas</b> bila NRP dari sistem lama tidak berpola. NRP dicek saat karyawan ditambah atau di-import, jadi atur ini sebelum migrasi data.
                </>
            }
        >
            <EmployeeNumberFormatEditor format={format} />
            {has('category') && (
                <div className="space-y-2">
                    <h3 className="text-sm font-bold text-slate-700">
                        Kode jenis karyawan <span className="font-normal text-slate-400">· karakter ke-{where('category')?.where} NRP</span>
                    </h3>
                    <CrudTable
                        title="Jenis Karyawan"
                        rows={type}
                        baseUrl="/core-hr/settings/employee-categories"
                        fields={[
                            { key: 'code', label: 'Kode', placeholder: 'mis. PJ', width: 'w-24' },
                            { key: 'name', label: 'Nama', placeholder: 'mis. Penjahit' },
                        ]}
                        toForm={(r) => ({ code: r.code, name: r.name })}
                        emptyForm={{ code: '', name: '' }}
                        deleteBlockedReason={(r) => (r.employee_count ? `Dipakai NRP ${r.employee_count} karyawan` : null)}
                        describe={(r) => `jenis karyawan ${r.code} — ${r.name}`}
                    />
                </div>
            )}
            {has('point_of_hire') && (
                <div className="space-y-2">
                    <h3 className="text-sm font-bold text-slate-700">
                        Kode lokasi penerimaan <span className="font-normal text-slate-400">· karakter ke-{where('point_of_hire')?.where} NRP</span>
                    </h3>
                    <CrudTable
                        title="Lokasi Penerimaan"
                        rows={location}
                        baseUrl="/core-hr/settings/points-of-hire"
                        fields={[
                            { key: 'code', label: 'Kode', placeholder: 'mis. 01', width: 'w-24' },
                            { key: 'name', label: 'Nama', placeholder: 'mis. Jakarta' },
                            { key: 'work_location_id', label: 'Lokasi Kerja', type: 'select', nullable: true, options: work_locations.map((w) => ({ value: w.id, label: w.name })) },
                        ]}
                        toForm={(r) => ({ code: r.code, name: r.name, work_location_id: str(r.work_location_id) })}
                        emptyForm={{ code: '', name: '', work_location_id: '' }}
                        describe={(r) => `lokasi penerimaan ${r.code} — ${r.name}`}
                    />
                </div>
            )}
        </StepCard>
    );
}

// --- 3. Organisasi ---------------------------------------------------------------------------

const OUTLINE_EXAMPLE = `Direktur Utama
  Operasional
    Produksi
      Cutting
      Sewing
    Quality Control
  Umum & Keuangan
    HRGA
      Personalia
    Finance`;

function OrganizationStep({ work_locations, outline, job_levels, standard_job_levels }: Props) {
    const [text, setText] = useState(outline);
    const [standard, setStandard] = useState(job_levels.length === 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const save = () =>
        router.post('/setup/organization', { outline: text, standard_job_levels: standard }, {
            ...visit,
            onStart: () => {
                setBusy(true);
                setError(null);
            },
            onFinish: () => setBusy(false),
            onError: (e) => setError(Object.values(e)[0] ?? 'Gagal menyimpan.'),
        });

    return (
        <StepCard step="organization" title="Lokasi kerja & struktur organisasi" intro="Lokasi kerja, unit organisasi, dan level jabatan (SubGroup) menjadi dasar nama jabatan dan penempatan karyawan.">
            <div className="space-y-2">
                <h3 className="text-sm font-bold text-slate-700">Lokasi kerja</h3>
                <p className="text-xs text-slate-500">Kantor pusat, pabrik, cabang, site, atau proyek. Tipe Head Office memakai gelar kantor pusat di nama jabatan.</p>
                <CrudTable
                    title="Lokasi Kerja"
                    rows={work_locations}
                    baseUrl="/core-hr/settings/work-locations"
                    fields={[
                        { key: 'name', label: 'Nama Lokasi', placeholder: 'mis. Pabrik Cikarang' },
                        { key: 'code', label: 'Kode', placeholder: 'mis. CKR', width: 'w-28' },
                        {
                            key: 'type',
                            label: 'Tipe',
                            type: 'select',
                            options: [
                                { value: 'head_office', label: 'Head Office' },
                                { value: 'site', label: 'Site / Cabang / Pabrik' },
                            ],
                        },
                    ]}
                    toForm={(r) => ({ name: r.name, code: str(r.code), type: r.type })}
                    emptyForm={{ name: '', code: '', type: 'site' }}
                    deleteBlockedReason={(r) => (r.employee_count ? `Masih dipakai ${r.employee_count} karyawan` : r.position_count ? `Masih punya ${r.position_count} jabatan` : null)}
                    describe={(r) => `lokasi kerja ${r.name}`}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_16rem]">
                <div className="space-y-2">
                    <h3 className="text-sm font-bold text-slate-700">Struktur organisasi</h3>
                    <p className="text-xs text-slate-500">
                        Satu unit per baris; menjorok (spasi/tab) = di bawah unit di atasnya: Direktorat → Divisi → Departemen → Section. Bisa ditempel dari Excel/Word; unit yang
                        sudah ada tidak digandakan.
                    </p>
                    <textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={14}
                        placeholder={OUTLINE_EXAMPLE}
                        className={`${input} font-mono text-[13px] leading-6`}
                        onKeyDown={(e) => {
                            if (e.key === 'Tab') {
                                e.preventDefault();
                                const el = e.currentTarget;
                                const at = el.selectionStart;
                                setText(text.slice(0, at) + '  ' + text.slice(el.selectionEnd));
                                requestAnimationFrame(() => el.setSelectionRange(at + 2, at + 2));
                            }
                        }}
                    />
                    {!text.trim() && (
                        <button type="button" onClick={() => setText(OUTLINE_EXAMPLE)} className="text-xs font-bold text-brand-600 hover:underline">
                            Isi dengan contoh lalu ubah
                        </button>
                    )}
                </div>
                <div className="space-y-2">
                    <h3 className="text-sm font-bold text-slate-700">Level jabatan (SubGroup)</h3>
                    {job_levels.length ? (
                        <ul className="space-y-1 text-sm text-slate-600">
                            {job_levels.map((j) => (
                                <li key={j.id}>
                                    {j.name} <span className="text-xs text-slate-400">· layer {j.layer}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-xs text-slate-500">Belum ada.</p>
                    )}
                    <label className="flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                        <input type="checkbox" checked={standard} onChange={(e) => setStandard(e.target.checked)} className="mt-0.5" />
                        <span>
                            Tambahkan level standar: {standard_job_levels.map((j) => j.name).join(', ')}. Bisa diubah di Pengaturan Core HR → SubGroup.
                        </span>
                    </label>
                </div>
            </div>
            {error && <p className="rounded-xl bg-rose-50 px-4 py-2 text-sm text-rose-600">{error}</p>}
            <button type="button" onClick={save} disabled={busy || (!text.trim() && !standard)} className={primary}>
                {busy ? <Loader2 size={15} className="animate-spin" /> : <Check size={15} />} Simpan struktur
            </button>
        </StepCard>
    );
}

// --- 4. Migrasi data -------------------------------------------------------------------------

function MigrationStep({ counts, groups }: Props) {
    const [importing, setImporting] = useState(false);
    const reload = () => router.reload({ only: ['counts'] });

    return (
        <StepCard
            step="migration"
            title="Migrasi data"
            intro="Pindahkan data dari sistem atau Excel lama. Unduh template, isi, lalu import — setiap file diperiksa dan dipratinjau dulu sebelum disimpan. Boleh dilewati dan dilakukan nanti."
        >
            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-3 rounded-2xl border border-slate-200 p-4">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-700">1. Jabatan</h3>
                        <span className="text-xs text-slate-400">{counts.positions} jabatan</span>
                    </div>
                    <p className="text-xs text-slate-500">Daftar jabatan per lokasi kerja dan unit. Opsional — jabatan juga terbentuk otomatis saat karyawan di-import.</p>
                    <PositionImport onImported={reload} />
                </div>
                <div className="space-y-3 rounded-2xl border border-slate-200 p-4">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-700">2. Karyawan</h3>
                        <span className="text-xs text-slate-400">{counts.employees} karyawan</span>
                    </div>
                    <p className="text-xs text-slate-500">Data lengkap karyawan (identitas, penempatan, keluarga, rekening, dll.). NRP diperiksa sesuai Format Nomor Induk.</p>
                    <div className="flex flex-wrap gap-2">
                        <a href="/core-hr/employees/excel/template" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">
                            <FileSpreadsheet size={14} /> Unduh template
                        </a>
                        <button onClick={() => setImporting(true)} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3 py-2 text-xs font-bold text-white hover:bg-brand-700">
                            <Upload size={14} /> Import karyawan
                        </button>
                    </div>
                </div>
            </div>
            {importing && <EmployeeImportDialog groups={groups} currentCount={counts.employees} onClose={() => setImporting(false)} onImported={reload} />}
        </StepCard>
    );
}

// --- Selesai ---------------------------------------------------------------------------------

function DoneStep({ setup, step_titles, counts }: Props) {
    return (
        <section className={`${card} space-y-5 text-center`}>
            <CheckCircle2 size={44} className="mx-auto text-emerald-500" />
            <div>
                <h2 className="text-lg font-bold text-slate-800">Data dasar siap</h2>
                <p className="mt-1 text-sm text-slate-500">
                    {counts.work_locations} lokasi kerja · {counts.positions} jabatan · {counts.employees} karyawan
                </p>
            </div>
            <ul className="mx-auto max-w-sm space-y-1 text-left text-sm">
                {(Object.keys(step_titles) as StepId[]).map((id) => (
                    <li key={id} className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2">
                        <span className="text-slate-600">{step_titles[id]}</span>
                        <span className={`text-xs font-bold ${setup.steps[id] === 'done' ? 'text-emerald-600' : 'text-slate-400'}`}>
                            {setup.steps[id] === 'done' ? 'Selesai' : setup.steps[id] === 'skipped' ? 'Dilewati' : 'Belum'}
                        </span>
                    </li>
                ))}
            </ul>
            <p className="text-xs text-slate-400">Langkah yang dilewati bisa dilengkapi di Pengaturan Core HR, Data Karyawan, dan Administrasi → Branding.</p>
            <button onClick={() => router.post('/setup/finish')} className={primary}>
                <PartyPopper size={15} /> Selesai & buka Dashboard
            </button>
        </section>
    );
}
