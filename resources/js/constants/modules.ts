import {
    BarChart3,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    ClipboardList,
    Database,
    FileText,
    Gauge,
    HandCoins,
    LayoutGrid,
    Palette,
    Palmtree,
    Network,
    Plane,
    Receipt,
    Settings2,
    ShieldCheck,
    SlidersHorizontal,
    Stethoscope,
    UserCheck,
    UserCog,
    UserPen,
    Users,
} from 'lucide-react';
import type { NavModule } from '@/types';

export const APP_MODULES: NavModule[] = [
    {
        id: 'recruitment',
        label: 'Modul Recruitment',
        icon: Users,
        items: [
            { id: 'tracking', label: 'Recruitment Tracking', icon: LayoutGrid, href: '/recruitment/tracking', menu: 'recruitment' },
            { id: 'applicants', label: 'Pelamar', icon: UserCheck, href: '/recruitment/pelamar', menu: 'recruitment' },
            { id: 'forms', label: 'Pengaturan Form', icon: FileText, href: '/recruitment/form', menu: 'recruitment' },
            { id: 'reports', label: 'Laporan', icon: BarChart3, href: '/recruitment/laporan', menu: 'recruitment' },
        ],
    },
    {
        id: 'manpower',
        label: 'Modul Manpower',
        icon: Building2,
        items: [
            // Mode Organisasi and Mode MPP, on one page.
            { id: 'org-structure', label: 'Struktur Organisasi', icon: Network, href: '/manpower/org-structure', menu: 'org-structure' },
            { id: 'manpower-planning', label: 'Man Power Planning', icon: ClipboardList, disabled: true, menu: 'org-structure' },
            { id: 'manpower-master', label: 'Man Power', icon: UserCog, href: '/manpower/karyawan', menu: 'karyawan' },
            { id: 'pengajuan-data', label: 'Pengajuan Update Data', icon: UserPen, href: '/manpower/pengajuan-data', menu: 'update-data' },
            { id: 'master-data', label: 'Master Data Personalia', icon: Database, href: '/manpower/master-data', menu: 'master-personalia' },
        ],
    },
    {
        id: 'attendance',
        label: 'Modul Attendance',
        icon: CalendarClock,
        items: [
            { id: 'roster', label: 'Roster', icon: CalendarDays, href: '/attendance/roster', menu: 'roster' },
            { id: 'absensi', label: 'Absensi', icon: CalendarCheck, href: '/attendance/absensi', menu: 'absensi' },
            { id: 'trip-leave', label: 'Trip & Leave', icon: Plane, href: '/attendance/trip-leave', menu: 'trip-leave' },
            { id: 'pengajuan-dinas', label: 'Pengajuan Dinas', icon: Receipt, href: '/attendance/pengajuan-dinas', menu: 'pengajuan-dinas' },
            { id: 'attendance-master', label: 'Master Data Attendance', icon: Settings2, href: '/attendance/master-data', menu: 'master-attendance' },
        ],
    },
    {
        id: 'comben',
        label: 'Modul Comben',
        icon: HandCoins,
        items: [
            { id: 'deklarasi', label: 'Deklarasi', icon: Stethoscope, href: '/comben/deklarasi', menu: 'deklarasi' },
            { id: 'monitoring-plafon', label: 'Monitoring Plafon', icon: Gauge, href: '/comben/monitoring', menu: 'monitoring-plafon' },
            { id: 'cuti', label: 'Cuti Tahunan & Besar', icon: Palmtree, href: '/comben/cuti', menu: 'cuti' },
            { id: 'comben-master', label: 'Master Data Comben', icon: Database, href: '/comben/master-data', menu: 'master-comben' },
        ],
    },
    {
        id: 'settings',
        label: 'Settings',
        icon: SlidersHorizontal,
        items: [
            { id: 'users', label: 'Akun & Akses', icon: ShieldCheck, href: '/settings/akun', menu: 'settings' },
            { id: 'white-label', label: 'White Label', icon: Palette, href: '/settings/white-label', menu: 'settings' },
        ],
    },
];
