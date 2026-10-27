import type { LucideIcon } from 'lucide-react';

/** super_admin = Super Admin, admin = Admin (menus and locations from Settings), employee = self-service portal. */
export type Role = 'super_admin' | 'admin' | 'employee';

export type MenuLevel = 'view' | 'edit';

export interface AuthUser {
    id: number;
    name: string;
    email: string | null;
    role: Role;
    role_label: string;
    employee_id: number | null;
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
    notifications: number;
}

/** Eolas: the demo workspace the account works in (null in the main application). */
export interface Workspace {
    code: string;
    company_name: string;
    data_source: 'demo' | 'blank';
    source_label: string;
    expires_at: string | null;
    days_left: number | null;
}

export interface NavItem {
    id: string;
    label: string;
    icon: LucideIcon;
    href?: string;
    disabled?: boolean;
    /** The access menu (Administrasi → Pengguna & Akses) that shows this item; 'settings' = Super Admin. */
    /** The menu (or any of the menus) the account needs; "settings" = may manage users. */
    menu?: string | string[];
    /** Sub-modules, shown indented under this item. */
    children?: NavItem[];
    /** Only in the main application, never inside a demo workspace. */
    centralOnly?: boolean;
    /** Only in a workspace that has a first-run setup (sesi awal). */
    setupOnly?: boolean;
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
            /** Sesi awal of this workspace (Super Admin only): pending | completed | dismissed, or null when there is none. */
            setup: 'pending' | 'completed' | 'dismissed' | null;
        };
        /** One-off messages set with Inertia::flash() before a redirect. */
        flashDataType: {
            success?: string;
            error?: string;
            warnings?: string[];
            /** Lowongan: the applicant's name after an application was sent. */
            sent?: string;
        };
    }
}
