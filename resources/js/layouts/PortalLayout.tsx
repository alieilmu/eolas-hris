import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Home, KeyRound, LogOut, UserPen } from 'lucide-react';
import { FlashToast } from '@/components/FlashToast';

/** Portal Karyawan: a simple top bar instead of the admin sidebar. */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage().props;
    const url = usePage().url.split('?')[0];
    const user = auth.user;

    return (
        <div className="flex min-h-screen flex-col bg-slate-50">
            <header className="sticky top-0 z-40 border-b-[3px] border-accent-400 bg-white">
                <div className="mx-auto flex h-16 max-w-5xl items-center gap-3 px-4">
                    <Link href="/portal" className="flex items-center gap-3">
                        <img src="/images/riung-logo.webp" alt="Riung" className="h-8 w-auto" />
                        <div className="border-l border-slate-200 pl-3 leading-tight">
                            <p className="text-sm font-black tracking-tight text-brand-700">Portal Karyawan</p>
                            <p className="text-[10px] font-semibold text-riung-gray">HR Puspita · PT Riung Mitra Lestari</p>
                        </div>
                    </Link>
                    <nav className="ml-4 hidden items-center gap-1 sm:flex">
                        {[
                            { href: '/portal', label: 'Beranda', icon: Home },
                            { href: '/portal/data', label: 'Data Saya', icon: UserPen },
                        ].map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold whitespace-nowrap ${
                                    url === item.href ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'
                                }`}
                            >
                                <item.icon size={15} />
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto flex items-center gap-1">
                        {user && <span className="mr-2 hidden truncate text-sm font-bold text-slate-600 lg:block">{user.name}</span>}
                        <Link
                            href="/akun/password"
                            title="Ganti password"
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                        >
                            <KeyRound size={18} />
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            title="Keluar"
                            className="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                        >
                            <LogOut size={18} />
                        </Link>
                    </div>
                </div>
            </header>
            <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6">{children}</main>
            <FlashToast />
        </div>
    );
}
