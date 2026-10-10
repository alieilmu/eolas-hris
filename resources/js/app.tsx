import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AppLayout from '@/layouts/AppLayout';
import PortalLayout from '@/layouts/PortalLayout';
import type { Auth } from '@/types';

// White label: the name set in Settings → White Label, written into the page head by the server.
const appName = document.querySelector<HTMLMetaElement>('meta[name="app-name"]')?.content || import.meta.env.VITE_APP_NAME || 'HRIS';
const accent = getComputedStyle(document.documentElement).getPropertyValue('--color-accent-400').trim();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        return pages[`./pages/${name}.tsx`] as never;
    },
    // Auth pages, the public vacancies, and error pages seen while signed out, stand alone; everything else sits in the sidebar shell.
    layout: (name, page) => {
        const user = (page.props as { auth?: Auth }).auth?.user;
        if (name.startsWith('Auth/') || name.startsWith('Lowongan/') || name === 'Landing' || name.startsWith('Demo/') || (name === 'Error' && !user))
            return undefined;
        // Employees: the portal, without the admin sidebar.
        return name.startsWith('Portal/') || user?.role === 'Karyawan' ? PortalLayout : AppLayout;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: accent || '#86efac',
    },
});
