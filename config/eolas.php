<?php

return [
    // "saas": the hosted demo service. "client": one company on its own server, under a license.
    'edition' => env('EOLAS_EDITION', 'saas'),

    // Client edition: where the license file is (default storage/app/license.json).
    'license_path' => env('EOLAS_LICENSE_PATH'),

    // Pengajuan akun demo: active at once (true) or after the Super Admin approves it (false).
    'demo_auto_approve' => (bool) env('DEMO_AUTO_APPROVE', true),

    // How long a demo workspace stays open.
    'demo_days' => (int) env('DEMO_DAYS', 14),

    // Workspaces open at once (being prepared or active, not ended): the server is shared, so
    // requests beyond this wait in line and are prepared as places free up.
    'demo_max_active' => (int) env('DEMO_MAX_ACTIVE', 30),

    // Days an ended demo keeps its workspace (to be extended) before it is removed.
    // The request itself is kept: it is a lead.
    'demo_keep_days' => (int) env('DEMO_KEEP_DAYS', 7),
];
