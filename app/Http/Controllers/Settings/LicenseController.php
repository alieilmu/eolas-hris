<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Audit\Audit;
use App\Support\Edition;
use App\Support\License\License;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Administrasi → Lisensi (client edition): the license in use, and putting in a new license file (Super Admin). */
class LicenseController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless(Edition::client(), 404);

        return Inertia::render('Settings/License', [
            'license' => License::summary(),
            'can_upload' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Edition::client() && $request->user()->isAdmin(), 404);
        $request->validate(['file' => ['required', 'file', 'max:64']], ['file.required' => 'Pilih file license.json dari Eolas.']);
        $contents = (string) file_get_contents($request->file('file')->getRealPath());
        $checked = License::check($contents);
        if (! $checked['valid']) {
            throw ValidationException::withMessages(['file' => $checked['reason']]);
        }
        File::ensureDirectoryExists(dirname(License::path()));
        File::put(License::path(), $contents);
        License::forget();
        Audit::log('license.installed', null, "{$checked['license_id']} · {$checked['company']} · {$checked['max_employees']} karyawan · modul ".implode('+', Modules::normalize($checked['modules'] ?? null))." · support s.d. {$checked['support_until']}");
        Inertia::flash('success', "Lisensi {$checked['company']} terpasang.");

        return redirect()->route('settings.license');
    }
}
