import { Head } from '@inertiajs/react';
import { Hammer } from 'lucide-react';

/** Stand-in for modules that are not rebuilt on Laravel yet. */
export default function ComingSoon({ title }: { title: string }) {
    return (
        <>
            <Head title={title} />

            <header className="flex h-16 shrink-0 items-center border-b-[3px] border-accent-400 bg-white px-8">
                <h1 className="text-xl font-bold tracking-tight text-slate-800">{title}</h1>
            </header>

            <div className="flex flex-1 flex-col items-center justify-center bg-slate-50 px-6 text-center">
                <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                    <Hammer size={26} />
                </div>
                <h2 className="mb-1 font-bold text-slate-800">Sedang dibangun ulang</h2>
                <p className="max-w-sm text-sm text-slate-500">
                    Modul <span className="font-bold">{title}</span> sedang dipindahkan ke Laravel dan akan tersedia di tahap berikutnya.
                </p>
            </div>
        </>
    );
}
