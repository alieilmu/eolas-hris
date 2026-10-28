<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Demo server: ended workspaces removed, the waiting line moved on (service "scheduler").
Schedule::command('eolas:prune-workspaces')->hourly()->withoutOverlapping();

// Sertifikasi & Izin: reminders 90/60/30 days before expiry, every morning.
Schedule::command('eolas:certifications:remind')->dailyAt('07:00')->withoutOverlapping();

// Workflow: approval steps past their time limit are reminded, then passed up to superiors.
Schedule::command('eolas:workflow:escalate')->hourly()->withoutOverlapping();
