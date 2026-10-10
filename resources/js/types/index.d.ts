import type { LucideIcon } from 'lucide-react';

/** Admin = Super Admin, HR = Admin (menus and locations from Settings), Karyawan = employee portal. */
export type Role = 'Admin' | 'HR' | 'Karyawan';

export type MenuLevel = 'view' | 'edit';

export interface AuthUser {
    id: number;
    name: string;
    email: string | null;
    role: Role;
    role_label: string;
    karyawan_id: number | null;
}

/** White label (Settings → White Label), shared with every page. */
export interface Branding {
    app_name: string;
    company_name: string | null;
    tagline: string | null;
    brand_color: string;
    accent_color: string;
    /** Null when none was uploaded: the initial of the app name is shown instead. */
    logo_url: string | null;
    logo_full_url: string | null;
    favicon_url: string;
}

export interface Auth {
    user: AuthUser | null;
    can: {
        viewSensitiveData: boolean;
        manageUsers: boolean;
    };
    /** Menu key => level, for the sidebar and the edit buttons. */
    menus: Record<string, MenuLevel>;
    /** Unread notifications (pengajuan from the portal); 0 for employees. */
    notifikasi: number;
}

/** Eloas: the demo workspace the account works in (null in the main application). */
export interface Workspace {
    kode: string;
    perusahaan: string;
    sumber_data: 'demo' | 'kosong';
    sumber_label: string;
    expires_at: string | null;
    days_left: number | null;
}

export interface NavItem {
    id: string;
    label: string;
    icon: LucideIcon;
    href?: string;
    disabled?: boolean;
    /** The access menu (Settings → Akun & Akses) that shows this item; 'settings' = Super Admin. */
    menu?: string;
    /** Sub-modules, shown indented under this item. */
    children?: NavItem[];
    /** Only in the main application, never inside a demo workspace. */
    centralOnly?: boolean;
}

export interface NavModule {
    id: string;
    label: string;
    icon: LucideIcon;
    items: NavItem[];
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: Auth;
            branding: Branding;
            workspace: Workspace | null;
        };
        /** One-off messages set with Inertia::flash() before a redirect. */
        flashDataType: {
            success?: string;
            error?: string;
            warnings?: string[];
            /** Lowongan: the applicant's name after an application was sent. */
            terkirim?: string;
        };
    }
}
