import { Head, Link } from '@inertiajs/react';
import { FileQuestion, ServerCrash, ShieldOff, TimerOff } from 'lucide-react';

const MESSAGES: Record<number, { title: string; text: string; icon: typeof ShieldOff }> = {
    403: {
        title: 'Akses Ditolak',
        text: 'Halaman ini berisi data pribadi karyawan dan hanya dapat diakses oleh role Admin atau HR.',
        icon: ShieldOff,
    },
    404: { title: 'Halaman Tidak Ditemukan', text: 'Alamat yang dibuka tidak ada atau sudah dipindahkan.', icon: FileQuestion },
    419: { title: 'Sesi Kedaluwarsa', text: 'Halaman terlalu lama dibiarkan. Muat ulang lalu coba lagi.', icon: TimerOff },
    500: { title: 'Terjadi Kesalahan', text: 'Ada masalah di server. Coba lagi beberapa saat lagi.', icon: ServerCrash },
    503: { title: 'Sedang Pemeliharaan', text: 'Aplikasi sedang dalam pemeliharaan. Coba lagi nanti.', icon: ServerCrash },
};

export default function Error({ status }: { status: number }) {
    const { title, text, icon: Icon } = MESSAGES[status] ?? MESSAGES[500];

    return (
        <div className="flex min-h-full flex-1 flex-col items-center justify-center bg-slate-50 px-6 py-24 text-center">
            <Head title={title} />
            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-500">
                <Icon size={26} />
            </div>
            <p className="mb-1 text-xs font-bold tracking-widest text-slate-400">{status}</p>
            <h2 className="mb-1 font-bold text-slate-800">{title}</h2>
            <p className="max-w-sm text-sm text-slate-500">{text}</p>
            <Link href="/" className="mt-6 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white hover:bg-brand-700">
                Kembali ke beranda
            </Link>
        </div>
    );
}
