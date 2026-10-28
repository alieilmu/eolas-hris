import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, BadgeCheck, FileKey, Loader2, Lock, Upload } from 'lucide-react';
import { AttendanceHeader, primaryButton } from '@/components/time/ui';

interface Summary {
    valid: boolean;
    reason: string | null;
    license_id: string | null;
    company: string | null;
    tier: string | null;
    tier_label: string | null;
    max_employees: number | null;
    active_employees: number;
    support_until: string | null;
    issued_at: string | null;
    supported: boolean;
    modules: { key: string; label: string }[];
}

const ADDONS: Record<string, string> = { talent: 'Talent (KPI, Kompetensi, Jalur Karier, Suksesi)', lms: 'LMS (Kursus, Ujian, Sertifikat)' };

/** Administrasi → Lisensi (client installation): the license in use, the employee count against it, a new license file. */
export default function License({ license, can_upload }: { license: Summary; can_upload: boolean }) {
    const form = useForm<{ file: File | null }>({ file: null });
    const used = license.max_employees ? Math.min(100, (license.active_employees / license.max_employees) * 100) : 0;

    return (
        <>
            <Head title="Lisensi" />
            <AttendanceHeader title="Lisensi" subtitle="Lisensi Eolas HRIS untuk instalasi di server perusahaan Anda" />
            <main className="flex-1 overflow-y-auto bg-slate-50 p-6">
                <div className="mx-auto max-w-2xl space-y-4">
                    {license.valid ? (
                        <section className="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div className="flex items-start gap-3">
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                                    <BadgeCheck size={22} />
                                </span>
                                <div>
                                    <h2 className="text-lg font-bold text-slate-800">{license.company}</h2>
                                    <p className="text-sm text-slate-500">
                                        {license.tier_label} · {license.license_id} · terbit {license.issued_at}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-slate-500">Karyawan aktif</span>
                                    <span className="font-bold text-slate-800 tabular-nums">
                                        {license.active_employees} / {license.max_employees}
                                    </span>
                                </div>
                                <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className={`h-full ${used >= 90 ? 'bg-amber-500' : 'bg-brand-500'}`} style={{ width: `${used}%` }} />
                                </div>
                                {used >= 90 && (
                                    <p className="mt-1.5 text-xs text-amber-700">
                                        {license.max_employees !== null && license.active_employees >= license.max_employees
                                            ? 'Batas lisensi tercapai: karyawan baru tidak bisa ditambahkan. Hubungi Eolas untuk menambah kapasitas.'
                                            : 'Hampir mencapai batas lisensi. Karyawan baru tidak bisa ditambahkan setelah batas tercapai; hubungi Eolas untuk menambah kapasitas.'}
                                    </p>
                                )}
                            </div>
                            <div>
                                <p className="text-sm text-slate-500">Modul</p>
                                <ul className="mt-1.5 space-y-1 text-sm">
                                    {license.modules.map((m) => (
                                        <li key={m.key} className="flex items-center gap-2 font-semibold text-slate-700">
                                            <BadgeCheck size={15} className="text-brand-600" /> {m.label}
                                        </li>
                                    ))}
                                    {Object.entries(ADDONS)
                                        .filter(([key]) => !license.modules.some((m) => m.key === key))
                                        .map(([key, label]) => (
                                            <li key={key} className="flex items-center gap-2 text-slate-400">
                                                <Lock size={14} /> {label} · belum termasuk lisensi
                                            </li>
                                        ))}
                                </ul>
                            </div>
                            <div className={`rounded-xl px-3 py-2 text-sm ${license.supported ? 'bg-brand-50 text-brand-800' : 'bg-amber-50 text-amber-800'}`}>
                                {license.supported
                                    ? `Support & update berlaku sampai ${license.support_until}.`
                                    : `Masa support berakhir ${license.support_until}. Aplikasi tetap berjalan, tetapi update tidak bisa dipasang sebelum support diperpanjang.`}
                            </div>
                        </section>
                    ) : (
                        <section className="flex items-start gap-3 rounded-3xl border border-rose-200 bg-rose-50 p-6">
                            <AlertTriangle size={22} className="mt-0.5 shrink-0 text-rose-600" />
                            <div>
                                <h2 className="font-bold text-rose-800">{license.reason}</h2>
                                <p className="mt-1 text-sm text-rose-700">
                                    Aplikasi bisa dipakai setelah file lisensi dari Eolas dipasang di sini. {can_upload ? '' : 'Minta Super Admin memasangnya.'}
                                </p>
                            </div>
                        </section>
                    )}

                    {can_upload && (
                        <section className="space-y-3 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="flex items-center gap-2 font-bold text-slate-700">
                                <FileKey size={17} /> {license.valid ? 'Ganti lisensi' : 'Pasang lisensi'}
                            </h3>
                            <p className="text-sm text-slate-500">Unggah file license.json yang dikirim Eolas (mis. saat perpanjangan support atau penambahan kapasitas).</p>
                            <input type="file" accept=".json,application/json" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="block w-full text-sm" />
                            {form.errors.file && <p className="text-sm font-semibold text-rose-600">{form.errors.file}</p>}
                            <button onClick={() => form.post('/settings/license', { forceFormData: true })} disabled={!form.data.file || form.processing} className={primaryButton}>
                                {form.processing ? <Loader2 size={15} className="animate-spin" /> : <Upload size={15} />} Pasang
                            </button>
                        </section>
                    )}
                </div>
            </main>
        </>
    );
}
