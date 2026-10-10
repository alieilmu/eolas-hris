<?php

return [
    // Pengajuan akun demo: active at once (true) or after the Super Admin approves it (false).
    'demo_auto_approve' => (bool) env('DEMO_AUTO_APPROVE', true),

    // How long a demo workspace stays open.
    'demo_days' => (int) env('DEMO_DAYS', 14),
];
