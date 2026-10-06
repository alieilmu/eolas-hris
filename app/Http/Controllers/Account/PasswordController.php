<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Every account changes its own password here; a first-time password must be changed before anything else. */
class PasswordController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Account/Password', [
            'must_change' => $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)],
        ], [
            'current_password.current_password' => 'Password saat ini salah.',
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
        ], ['current_password' => 'Password saat ini', 'password' => 'Password baru']);

        $request->user()->update(['password' => $request->input('password'), 'must_change_password' => false]);
        Inertia::flash('success', 'Password berhasil diganti.');

        return redirect('/');
    }
}
