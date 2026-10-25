/** Indonesian labels for the system values (stored in English codes). */
export const EMPLOYEE_STATUS: Record<string, string> = { active: 'Aktif', inactive: 'Nonaktif', terminated: 'Keluar' };
export const REQUEST_TYPE: Record<string, string> = { business_trip: 'dinas', leave: 'cuti' };
export const APPROVAL_STATUS: Record<string, string> = { pending: 'menunggu', approved: 'disetujui', rejected: 'ditolak' };
export const LOCATION_TYPE: Record<string, string> = { head_office: 'Head Office', site: 'Site' };

export const label = (map: Record<string, string>, value: string | null | undefined) => (value ? (map[value] ?? value) : '');
