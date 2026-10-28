import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Award, ClipboardCheck, HandCoins, Home, KeyRound, LogOut, ReceiptText, UserPen } from 'lucide-react';
import { BrandLogo, brandLine, useBranding } from '@/components/BrandLogo';
import { FlashToast } from '@/components/FlashToast';

// module: an add-on package (Modules) the license must include; none = Core.
const NAV: { href: string; label: string; icon: typeof Home; module?: 'talent' | 'lms' }[] = [
    { href: '/self-service', label: 'Beranda', icon: Home },
    { href: '/self-service/payslips', label: 'Slip Gaji', icon: ReceiptText },
    { href: '/self-service/loans', label: 'Pinjaman', icon: HandCoins },
    { href: '/self-service/certifications', label: 'Sertifikat', icon: Award },
    { href: '/self-service/profile', label: 'Data Saya', icon: UserPen },
];

/** Portal Karyawan: a simple top bar instead of the admin sidebar; on a phone the menu is a row of tabs under it. */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const { auth, modules } = usePage().props;
    const url = usePage().url.split('?')[0];
    // Persetujuan: for a superior with pengajuan waiting (and while on the page).
    const approvals = auth.approvals > 0 || url.startsWith('/approvals') ? [{ href: '/approvals', label: 'Persetujuan', icon: ClipboardCheck, badge: auth.approvals }] : [];
    const nav: ((typeof NAV)[number] & { badge?: number })[] = [...NAV.filter((item) => !item.module || modules.includes(item.module)), ...approvals];
    const user = auth.user;
    const branding = useBranding();

    return (
        <div className="flex min-h-screen flex-col overflow-x-clip bg-slate-50">
            <header className="sticky top-0 z-40 border-b-[3px] border-accent-400 bg-white">
                <div className="mx-auto flex h-14 max-w-5xl items-center gap-2 px-3 sm:h-16 sm:gap-3 sm:px-4">
                    <Link href="/self-service" className="flex min-w-0 items-center gap-2 sm:gap-3">
                        <BrandLogo className="h-7 sm:h-8" />
                        <div className="min-w-0 border-l border-slate-200 pl-2 leading-tight sm:pl-3">
                            <p className="truncate text-sm font-black tracking-tight text-brand-700">Portal Karyawan</p>
                            <p className="hidden truncate text-[10px] font-semibold text-slate-400 sm:block">{brandLine(branding)}</p>
                        </div>
                    </Link>
                    <nav className="ml-4 hidden items-center gap-1 sm:flex">
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold whitespace-nowrap ${
                                    url === item.href ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:bg-slate-100'
                                }`}
                            >
                                <item.icon size={15} />
                                {item.label}
                                {!!item.badge && <span className="rounded-full bg-amber-400 px-1.5 text-[11px] font-black text-brand-950">{item.badge}</span>}
                            </Link>
                        ))}
                    </nav>
                    <div className="ml-auto flex shrink-0 items-center gap-0.5 sm:gap-1">
                        {user && <span className="mr-2 hidden max-w-48 truncate text-sm font-bold text-slate-600 lg:block">{user.name}</span>}
                        <Link
                            href="/account/password"
                            title="Ganti password"
                            aria-label="Ganti password"
                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-brand-600"
                        >
                            <KeyRound size={18} />
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            title="Keluar"
                            aria-label="Keluar"
                            className="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600"
                        >
                            <LogOut size={18} />
                        </Link>
                    </div>
                </div>
                {/* Phone: the menu as tabs. */}
                <nav className="grid grid-cols-2 gap-1 border-t border-slate-100 px-3 py-1.5 sm:hidden">
                    {nav.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`flex items-center justify-center gap-1.5 rounded-lg py-1.5 text-sm font-bold ${
                                url === item.href ? 'bg-brand-50 text-brand-700' : 'text-slate-500'
                            }`}
                        >
                            <item.icon size={15} />
                            {item.label}
                            {!!item.badge && <span className="rounded-full bg-amber-400 px-1.5 text-[11px] font-black text-brand-950">{item.badge}</span>}
                        </Link>
                    ))}
                </nav>
            </header>
            <main className="mx-auto w-full max-w-5xl min-w-0 flex-1 px-3 py-4 sm:px-4 sm:py-6">{children}</main>
            <FlashToast />
        </div>
    );
}
