import type { ReactNode } from 'react';
import { FlashToast } from '@/components/FlashToast';
import { Sidebar } from '@/components/Sidebar';
import { APP_MODULES } from '@/constants/modules';

/** Persistent shell for every signed-in page: the sidebar keeps its state across visits. */
export default function AppLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex h-screen overflow-hidden">
            <Sidebar modules={APP_MODULES} />
            <main className="flex h-screen min-w-0 flex-1 flex-col overflow-hidden">{children}</main>
            <FlashToast />
        </div>
    );
}
