import { Link, usePage } from '@inertiajs/react';
import { LayoutDashboard, Table2, UserX } from 'lucide-react';

const TABS = [
    { href: '/manpower/karyawan', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/manpower/karyawan/data-lengkap', label: 'Data Lengkap', icon: Table2 },
    { href: '/manpower/karyawan/keluar', label: 'Karyawan Keluar', icon: UserX },
];

/** "Man Power" title bar with the three view tabs; each tab is its own URL. */
export function KaryawanHeader() {
    const path = usePage().url.split('?')[0];

    return (
        <header className="flex h-16 shrink-0 items-center justify-between border-b-[3px] border-accent-400 bg-white px-8">
            <h1 className="text-xl font-bold tracking-tight text-slate-800">Man Power</h1>

            <nav className="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                {TABS.map(({ href, label, icon: Icon }) => (
                    <Link
                        key={href}
                        href={href}
                        prefetch
                        preserveScroll
                        className={`flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-sm font-bold transition-all ${
                            path === href ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'
                        }`}
                    >
                        <Icon size={15} />
                        {label}
                    </Link>
                ))}
            </nav>
        </header>
    );
}
