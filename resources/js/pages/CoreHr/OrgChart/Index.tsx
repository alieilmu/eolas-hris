import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { WorkforcePlanningMode, type MppModeProps } from '@/components/orgchart/WorkforcePlanningMode';
import { OrganizationMode, type OrganisasiModeProps } from '@/components/orgchart/OrganizationMode';
import { LocationTabs, OrgHeader, type OrgMode } from '@/components/orgchart/OrgLayout';

type Props = OrganisasiModeProps & Omit<MppModeProps, 'onSetup'>;

const modeFromUrl = (): OrgMode => (new URLSearchParams(window.location.search).get('mode') === 'organization' ? 'organization' : 'workforce_planning');

/**
 * Struktur Organisasi, per work location, in two modes on one page: Mode Organisasi arranges the
 * structure, Mode MPP fills it with people. Switching modes does not reload the page — both work
 * on the same data, so changes made in one show in the other right away.
 */
export default function OrgStructurePage(props: Props) {
    const [mode, setMode] = useState<OrgMode>(modeFromUrl);

    const switchMode = (next: OrgMode) => {
        setMode(next);
        // Remember the mode in the address (for a reload or a shared link) without visiting it.
        const url = new URL(window.location.href);
        url.searchParams.set('mode', next);
        url.searchParams.delete('focus');
        window.history.replaceState(window.history.state, '', url);
    };

    return (
        <>
            <Head title={mode === 'organization' ? 'Struktur Organisasi — Mode Organisasi' : 'Struktur Organisasi — Mode MPP'} />
            <OrgHeader mode={mode} onMode={switchMode} />
            <LocationTabs
                locations={props.locations}
                activeId={props.location?.id}
                mode={mode}
                badge={(l) => (mode === 'organization' ? (l.needs_setup ? 'setup' : l.position_count) : l.people_count)}
            />
            {mode === 'organization' ? <OrganizationMode {...props} /> : <WorkforcePlanningMode {...props} onSetup={() => switchMode('organization')} />}
        </>
    );
}
