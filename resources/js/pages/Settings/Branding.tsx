import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ImageUp, Loader2, LogIn, RotateCcw, Save, Trash2, Users } from 'lucide-react';
import { AttendanceHeader, primaryButton, secondaryButton, smallInput } from '@/components/time/ui';
import { BrandLogo, LoginMark } from '@/components/BrandLogo';
import { isHex, paletteVars } from '@/lib/branding';
import type { Branding } from '@/types';

type Jenis = 'logo' | 'logo-full' | 'favicon';

interface Props {
    values: { app_name: string; company_name: string; tagline: string; brand_color: string; accent_color: string };
    /** URL of each uploaded file, null when none. */
    files: Record<Jenis, string | null>;
    defaults: { app_name: string; brand_color: string; accent_color: string };
}

const FILES: { type: Jenis; label: string; hint: string; accept: string }[] = [
    {
        type: 'logo',
        label: 'Logo',
        hint: 'Di sidebar, Portal Karyawan dan form Deklarasi. Sebaiknya persegi / mendatar, latar transparan. PNG, JPG, WEBP maks. 2 MB.',
        accept: 'image/png,image/jpeg,image/webp',
    },
    {
        type: 'logo-full',
        label: 'Logo lengkap (halaman login)',
        hint: 'Logo dengan nama perusahaan. Bila kosong, login memakai Logo + nama perusahaan. Maks. 2 MB.',
        accept: 'image/png,image/jpeg,image/webp',
    },
    {
        type: 'favicon',
        label: 'Favicon (ikon tab browser)',
        hint: 'PNG / ICO persegi, mis. 64×64, maks. 512 KB. Bila kosong, dibuat otomatis dari huruf pertama nama aplikasi.',
        accept: 'image/png,image/x-icon,image/vnd.microsoft.icon,.ico,image/webp',
    },
];

/** Colour pairs to start from. */
const PRESETS: [string, string, string][] = [
    ['Slate & kuning', '#586c9a', '#fcd100'],
    ['Biru & oranye', '#1f5fa8', '#f59e0b'],
    ['Hijau & emas', '#1f7a55', '#e3b505'],
    ['Merah & abu', '#b42318', '#f2c94c'],
    ['Ungu & toska', '#5b3f9a', '#2dd4bf'],
    ['Abu netral', '#475569', '#f2b705'],
];

/** Settings → White Label: names, logos, favicon and colours, with a live preview before saving. */
export default function BrandingPage({ values, files, defaults }: Props) {
    const form = useForm({
        ...values,
        logo: null as File | null,
        'logo-full': null as File | null,
        favicon: null as File | null,
        delete: [] as Jenis[],
    });
    const d = form.data;

    // Local previews of the files picked but not saved yet.
    const [previews, setPreviews] = useState<Partial<Record<Jenis, string>>>({});
    useEffect(() => () => Object.values(previews).forEach((u) => u && URL.revokeObjectURL(u)), [previews]);
    const pick = (type: Jenis, file: File | null) => {
        form.setData((data) => ({ ...data, [type]: file, delete: data.delete.filter((j) => j !== type) }));
        setPreviews((p) => ({ ...p, [type]: file ? URL.createObjectURL(file) : undefined }));
    };
    const remove = (type: Jenis) => {
        form.setData((data) => ({ ...data, [type]: null, delete: [...new Set([...data.delete, type])] }));
        setPreviews((p) => ({ ...p, [type]: undefined }));
    };
    const current = (type: Jenis) => previews[type] ?? (d.delete.includes(type) ? null : files[type]);

    const preview: Branding = useMemo(
        () => ({
            app_name: d.app_name.trim() || defaults.app_name,
            company_name: d.company_name.trim() || null,
            tagline: d.tagline.trim() || null,
            brand_color: d.brand_color,
            accent_color: d.accent_color,
            logo_url: current('logo'),
            logo_full_url: current('logo-full'),
            favicon_url: current('favicon') ?? '',
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [d, previews, files],
    );

    const submit = () =>
        form.post('/settings/branding', {
            forceFormData: true,
            preserveScroll: true,
            // The colours and the favicon sit in the page head: load it again to see them everywhere.
            onSuccess: () => window.location.reload(),
        });

    const colorsOk = isHex(d.brand_color) && isHex(d.accent_color);

    return (
        <>
            <Head title="Branding" />
            <AttendanceHeader
                title="Branding"
                subtitle="Nama, logo, favicon dan warna aplikasi — untuk dipakai perusahaan lain tanpa mengubah kode"
            >
                <button onClick={submit} disabled={form.processing || !colorsOk} className={primaryButton}>
                    {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Save size={16} />}
                    Simpan
                </button>
            </AttendanceHeader>

            <div className="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px] [&>*]:min-w-0">
                    <div className="space-y-5">
                        <Card title="Identitas">
                            <div className="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                                <Field label="Nama aplikasi" error={form.errors.app_name} hint={`Kosong = "${defaults.app_name}" (APP_NAME di .env)`}>
                                    <input
                                        value={d.app_name}
                                        onChange={(e) => form.setData('app_name', e.target.value)}
                                        maxLength={60}
                                        placeholder={defaults.app_name}
                                        className={`${smallInput} w-full`}
                                    />
                                </Field>
                                <Field label="Nama perusahaan" error={form.errors.company_name}>
                                    <input
                                        value={d.company_name}
                                        onChange={(e) => form.setData('company_name', e.target.value)}
                                        maxLength={120}
                                        placeholder="PT …"
                                        className={`${smallInput} w-full`}
                                    />
                                </Field>
                                <Field
                                    label="Tagline / bidang usaha"
                                    error={form.errors.tagline}
                                    hint="Di bawah nama perusahaan, mis. di form Deklarasi"
                                >
                                    <input
                                        value={d.tagline}
                                        onChange={(e) => form.setData('tagline', e.target.value)}
                                        maxLength={120}
                                        placeholder="mis. Mining Contractor"
                                        className={`${smallInput} w-full`}
                                    />
                                </Field>
                            </div>
                        </Card>

                        <Card
                            title="Warna"
                            action={
                                <button
                                    onClick={() =>
                                        form.setData((data) => ({ ...data, brand_color: defaults.brand_color, accent_color: defaults.accent_color }))
                                    }
                                    className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100"
                                >
                                    <RotateCcw size={13} />
                                    Warna bawaan
                                </button>
                            }
                        >
                            <div className="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                                <ColorField
                                    label="Warna utama"
                                    hint="Sidebar, tombol, tautan, judul"
                                    value={d.brand_color}
                                    onChange={(v) => form.setData('brand_color', v)}
                                    error={form.errors.brand_color}
                                />
                                <ColorField
                                    label="Warna aksen"
                                    hint="Garis kepala halaman, menu aktif, sorotan"
                                    value={d.accent_color}
                                    onChange={(v) => form.setData('accent_color', v)}
                                    error={form.errors.accent_color}
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {PRESETS.map(([label, brand, accent]) => (
                                    <button
                                        key={label}
                                        onClick={() => form.setData((data) => ({ ...data, brand_color: brand, accent_color: accent }))}
                                        className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:border-slate-300"
                                    >
                                        <span className="flex">
                                            <span className="h-4 w-4 rounded-l-md" style={{ background: brand }} />
                                            <span className="h-4 w-4 rounded-r-md" style={{ background: accent }} />
                                        </span>
                                        {label}
                                    </button>
                                ))}
                            </div>
                            {colorsOk && <Scale brand={d.brand_color} accent={d.accent_color} />}
                        </Card>

                        <Card title="Logo & favicon">
                            <div className="divide-y divide-slate-100">
                                {FILES.map((f) => (
                                    <FileRow
                                        key={f.type}
                                        {...f}
                                        url={current(f.type)}
                                        dark={f.type === 'favicon'}
                                        error={form.errors[f.type]}
                                        onPick={(file) => pick(f.type, file)}
                                        onRemove={() => remove(f.type)}
                                    />
                                ))}
                            </div>
                        </Card>
                    </div>

                    <div className="space-y-3 xl:sticky xl:top-0 xl:self-start">
                        <p className="text-[11px] font-bold tracking-wide text-slate-500 uppercase">Pratinjau (belum disimpan)</p>
                        <Preview b={preview} />
                    </div>
                </div>
            </div>
        </>
    );
}

function Card({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div className="mb-4 flex items-center justify-between gap-2">
                <h2 className="text-sm font-bold text-slate-800">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-[11px] font-bold text-slate-500">{label}</span>
            {children}
            {error ? (
                <span className="mt-1 block text-xs font-bold text-rose-600">{error}</span>
            ) : (
                hint && <span className="mt-1 block text-[11px] text-slate-400">{hint}</span>
            )}
        </label>
    );
}

function ColorField({
    label,
    hint,
    value,
    onChange,
    error,
}: {
    label: string;
    hint: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <Field label={label} hint={hint} error={error ?? (isHex(value) ? undefined : 'Kode hex 6 digit, mis. #3f5f8f')}>
            <span className="flex items-center gap-2">
                <input
                    type="color"
                    value={isHex(value) ? value : '#000000'}
                    onChange={(e) => onChange(e.target.value)}
                    className="h-9 w-12 shrink-0 cursor-pointer rounded-lg border border-slate-200 bg-white p-0.5"
                    aria-label={`${label} (pilih)`}
                />
                <input
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    maxLength={7}
                    className={`${smallInput} w-full font-mono uppercase`}
                    aria-label={`${label} (hex)`}
                />
            </span>
        </Field>
    );
}

/** The scales the two colours become. */
function Scale({ brand, accent }: { brand: string; accent: string }) {
    const vars = paletteVars(brand, accent) as Record<string, string>;
    const row = (name: 'brand' | 'accent') => {
        const steps = Object.keys(vars).filter((k) => k.startsWith(`--color-${name}-`));
        return (
            <div className="flex overflow-hidden rounded-lg ring-1 ring-slate-200">
                {steps.map((k) => (
                    <span key={k} title={`${k.replace('--color-', '')} ${vars[k]}`} className="h-6 flex-1" style={{ background: vars[k] }} />
                ))}
            </div>
        );
    };
    return (
        <div className="mt-4 space-y-1.5">
            {row('brand')}
            {row('accent')}
        </div>
    );
}

function FileRow({
    label,
    hint,
    accept,
    url,
    dark,
    error,
    onPick,
    onRemove,
}: {
    label: string;
    hint: string;
    accept: string;
    url: string | null;
    dark: boolean;
    error?: string;
    onPick: (f: File | null) => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-4 py-3 first:pt-0 last:pb-0">
            <div
                className={`flex h-16 w-28 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 ${dark ? 'bg-slate-100' : 'bg-white'}`}
            >
                {url ? (
                    <img src={url} alt={label} className="max-h-14 max-w-24 object-contain" />
                ) : (
                    <span className="text-[11px] text-slate-400">Bawaan</span>
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-bold text-slate-700">{label}</p>
                <p className="text-[11px] text-slate-400">{hint}</p>
                {error && <p className="mt-1 text-xs font-bold text-rose-600">{error}</p>}
            </div>
            <div className="flex gap-2">
                <label className={`${secondaryButton} cursor-pointer`}>
                    <ImageUp size={15} />
                    {url ? 'Ganti' : 'Unggah'}
                    <input type="file" accept={accept} className="hidden" onChange={(e) => onPick(e.target.files?.[0] ?? null)} />
                </label>
                {url && (
                    <button
                        onClick={onRemove}
                        title="Hapus (kembali ke bawaan)"
                        className="rounded-xl p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                    >
                        <Trash2 size={16} />
                    </button>
                )}
            </div>
        </div>
    );
}

/** The sidebar head, a page with buttons, and the login card, in the colours and logos of the form. */
function Preview({ b }: { b: Branding }) {
    return (
        <div style={paletteVars(b.brand_color, b.accent_color)} className="space-y-4">
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="flex">
                    <div className="w-40 shrink-0 bg-brand-900 p-2 text-white">
                        <div className="space-y-1 text-[11px] font-bold">
                            <p className="flex items-center gap-1.5 px-2 py-1 text-brand-100">
                                <Users size={12} className="text-accent-400" />
                                Man Power
                            </p>
                            <p className="rounded-md bg-accent-400 px-2 py-1 text-brand-950">Data Karyawan</p>
                            <p className="px-2 py-1 text-brand-200">Struktur Organisasi</p>
                            <p className="px-2 py-1 text-brand-200">Cuti &amp; Dinas</p>
                        </div>
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex h-12 items-center gap-2 border-b-[3px] border-accent-400 px-3">
                            <BrandLogo branding={b} className="h-7" />
                            <div className="min-w-0 border-l border-slate-200 pl-2 leading-tight">
                                <p className="truncate text-xs font-black text-brand-700">{b.app_name}</p>
                                {b.company_name && <p className="truncate text-[9px] font-semibold text-slate-400">{b.company_name}</p>}
                            </div>
                        </div>
                        <div className="space-y-2 bg-slate-50 p-3">
                            <p className="text-xs font-bold text-slate-800">Data Karyawan</p>
                            <div className="flex flex-wrap gap-1.5">
                                <span className="rounded-lg bg-brand-600 px-2.5 py-1 text-[11px] font-bold text-white">Simpan</span>
                                <span className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-bold text-slate-600">
                                    Batal
                                </span>
                                <span className="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-bold text-brand-700">Aktif</span>
                            </div>
                            <p className="text-[11px] text-slate-500">
                                Tautan <span className="font-bold text-brand-600 underline">lihat detail</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="rounded-2xl bg-gradient-to-br from-brand-50 via-slate-50 to-accent-50 p-5">
                <div className="mb-3 flex flex-col items-center gap-2">
                    {b.logo_full_url ? (
                        <img src={b.logo_full_url} alt={b.company_name ?? b.app_name} className="max-h-16 w-40 object-contain" />
                    ) : (
                        <LoginMark b={b} />
                    )}
                    <span className="rounded-full bg-brand-600 px-2.5 py-0.5 text-[10px] font-bold text-white">{b.app_name}</span>
                </div>
                <div className="space-y-2 rounded-2xl border border-slate-200 border-t-4 border-t-accent-400 bg-white p-4 shadow-sm">
                    <p className="text-sm font-bold text-slate-800">Masuk</p>
                    <div className="h-7 rounded-lg border border-slate-200 bg-slate-50" />
                    <div className="h-7 rounded-lg border border-slate-200 bg-slate-50" />
                    <span className="flex items-center justify-center gap-1.5 rounded-lg bg-brand-600 py-1.5 text-[11px] font-bold text-white">
                        <LogIn size={12} />
                        Masuk
                    </span>
                </div>
            </div>
        </div>
    );
}
