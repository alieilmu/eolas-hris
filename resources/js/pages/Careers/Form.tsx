import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, CheckCircle2, Clock, Loader2, MapPin, Send } from 'lucide-react';
import { ApplicationField } from '@/components/recruitment/ApplicationField';
import { PublicShell } from '@/components/recruitment/PublicShell';
import { fmtDate } from '@/lib/format';
import type { PublicField } from '@/lib/recruitment';

interface Props {
    form: {
        id: number;
        title: string;
        description: string | null;
        position_title: string | null;
        location: string | null;
        closing_date: string | null;
        accepting: boolean;
        fields: PublicField[];
    };
    max_file: string;
    max_bytes: number;
}

/** A vacancy's application form, for anyone; after sending, a thank-you in its place. */
export default function LowonganForm({ form: careers, max_file, max_bytes }: Props) {
    const { flash } = usePage();
    const sent = (flash as { sent?: string }).sent;
    const form = useForm({
        answers: {} as Record<string, string | File | null>,
        agree: false,
        website: '',
    });
    const errors = form.errors as Record<string, string>;
    const answer = (id: number) => form.data.answers[id];
    const set = (id: number, v: string | File | null) => form.setData('answers', { ...form.data.answers, [id]: v });

    const tooBig = careers.fields.find((f) => {
        const v = answer(f.id);
        return v instanceof File && v.size > max_bytes;
    });

    const submit = () =>
        form.post(`/careers/${careers.id}`, {
            forceFormData: true,
            preserveScroll: (page) => Object.keys(page.props.errors ?? {}).length > 0,
            onSuccess: () => form.reset(),
        });

    return (
        <PublicShell>
            <Head title={careers.title} />
            <div className="mx-auto max-w-2xl px-4 py-8 sm:py-12">
                <Link href="/careers" className="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-brand-600 hover:underline">
                    <ArrowLeft size={15} /> Semua lowongan
                </Link>

                <div className="mb-6 rounded-3xl border border-slate-200 border-t-8 border-t-brand-600 bg-white p-6 shadow-sm sm:p-8">
                    <h1 className="text-2xl font-black text-slate-900 sm:text-4xl">{careers.title}</h1>
                    <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm font-semibold text-slate-500">
                        {careers.position_title && <span>{careers.position_title}</span>}
                        {careers.location && (
                            <span className="flex items-center gap-1">
                                <MapPin size={14} className="text-brand-400" /> {careers.location}
                            </span>
                        )}
                        {careers.closing_date && (
                            <span className="flex items-center gap-1">
                                <CalendarClock size={14} className="text-brand-400" /> Hingga {fmtDate(careers.closing_date)}
                            </span>
                        )}
                    </p>
                    {careers.description && (
                        <p className="mt-4 text-sm leading-relaxed whitespace-pre-line text-slate-600">{careers.description}</p>
                    )}
                </div>

                {sent ? (
                    <div className="rounded-3xl border border-emerald-100 bg-white p-8 text-center shadow-sm sm:p-10">
                        <CheckCircle2 size={48} className="mx-auto text-emerald-500" />
                        <h2 className="mt-4 text-2xl font-black text-slate-800">Lamaran terkirim</h2>
                        <p className="mt-2 text-slate-500">
                            Terima kasih, <b className="text-slate-700">{sent}</b>. Lamaran Anda untuk {careers.title} sudah kami terima; tim
                            rekrutmen akan menghubungi bila Anda lolos seleksi berkas.
                        </p>
                        <Link
                            href="/careers"
                            className="mt-6 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800"
                        >
                            Lihat lowongan lain
                        </Link>
                    </div>
                ) : !careers.accepting ? (
                    <div className="rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                        <Clock size={40} className="mx-auto text-amber-400" />
                        <h2 className="mt-3 text-xl font-black text-slate-800">Lowongan ditutup</h2>
                        <p className="mt-1 text-sm text-slate-500">Lowongan ini sudah tidak menerima lamaran baru.</p>
                    </div>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                        noValidate
                    >
                        {errors.form && (
                            <p className="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{errors.form}</p>
                        )}
                        {careers.fields.map((f) => {
                            const v = answer(f.id);
                            return (
                                <ApplicationField
                                    key={f.id}
                                    field={f}
                                    value={typeof v === 'string' ? v : ''}
                                    file={v instanceof File ? v : null}
                                    maxFile={max_file}
                                    error={
                                        errors[`answers.${f.id}`] ??
                                        (v instanceof File && v.size > max_bytes ? `File terlalu besar; paling besar ${max_file}.` : undefined)
                                    }
                                    onChange={(val) => set(f.id, val)}
                                    onFile={(file) => set(f.id, file)}
                                />
                            );
                        })}
                        {/* Hidden from people; bots fill it in. */}
                        <input
                            type="text"
                            name="website"
                            value={form.data.website}
                            onChange={(e) => form.setData('website', e.target.value)}
                            tabIndex={-1}
                            autoComplete="off"
                            className="hidden"
                            aria-hidden
                        />

                        <label className="flex gap-3 rounded-3xl border border-slate-200 bg-white p-5 text-sm text-slate-600 shadow-sm">
                            <input
                                type="checkbox"
                                checked={form.data.agree}
                                onChange={(e) => form.setData('agree', e.target.checked)}
                                className="mt-0.5 h-4 w-4 shrink-0 accent-brand-600"
                            />
                            <span>
                                Data yang saya kirim benar, dan saya setuju data ini disimpan serta diproses untuk keperluan rekrutmen.
                                {errors.agree && <span className="mt-1 block text-xs font-bold text-rose-600">{errors.agree}</span>}
                            </span>
                        </label>

                        {Object.keys(errors).length > 0 && !errors.form && (
                            <p className="rounded-2xl bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                                Ada isian yang perlu diperbaiki; periksa tanda merah di atas.
                            </p>
                        )}
                        <button
                            type="submit"
                            disabled={form.processing || !!tooBig}
                            className="flex w-full items-center justify-center gap-2 rounded-3xl bg-brand-600 py-4 text-lg font-black text-white shadow-lg transition hover:bg-brand-700 disabled:opacity-60"
                        >
                            {form.processing ? <Loader2 size={20} className="animate-spin" /> : <Send size={20} />}
                            {form.processing ? `Mengirim${form.progress ? ` ${form.progress.percentage}%` : '…'}` : 'Kirim lamaran'}
                        </button>
                    </form>
                )}
            </div>
        </PublicShell>
    );
}
