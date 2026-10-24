<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /** Always shown as an in-app page. */
    private const USER_FACING_ERRORS = [403, 404, 419];

    /** Shown as an in-app page only when debug mode is off, so local stack traces stay visible. */
    private const SERVER_ERRORS = [500, 503];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // "central": the main database, kept when a request switches "mysql" to a demo workspace
        // (App\Support\Tenancy\Tenant); sessions, the queue and the demo accounts live there.
        config(['database.connections.central' => config('database.connections.'.config('database.default'))]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // An in-memory SQLite database (the tests) exists once per connection: "central" must be
        // the very same connection, not a second, empty database.
        if (config('database.connections.'.config('database.default').'.database') === ':memory:') {
            DB::extend('central', fn () => DB::connection(config('database.default')));
        }

        // Menus ticked for the account in Settings → Akun & Akses (the Super Admin has all).
        Gate::define('view-menu', fn (User $user, string ...$menus) => $user->canView(...$menus));
        Gate::define('edit-menu', fn (User $user, string ...$menus) => $user->canEdit(...$menus));

        // NIK, NPWP, BPJS, bank accounts, family data, exits and imports: who may see Man Power.
        Gate::define('view-sensitive-data', fn (User $user) => $user->canAccessSensitiveData());

        // Settings: accounts and their access.
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());

        // The employee portal: an employee's own account.
        Gate::define('portal', fn (User $user) => $user->isKaryawan() && $user->karyawan_id !== null);

        // Public application forms (Lowongan): a few applications per visitor per hour.
        RateLimiter::for('lamaran', fn (Request $request) => Limit::perHour(10)->by($request->ip())->response(
            fn () => back()->withErrors(['form' => 'Terlalu banyak lamaran dari jaringan ini; coba lagi dalam satu jam.'])));

        // Attendance machines push every few seconds; a generous ceiling per address.
        RateLimiter::for('iclock', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        // Pengajuan Akun Demo: a few requests per visitor per hour.
        RateLimiter::for('demo', fn (Request $request) => Limit::perHour(5)->by($request->ip())->response(
            fn () => back()->withErrors(['email' => 'Terlalu banyak pengajuan dari jaringan ini; coba lagi dalam satu jam.'])));

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $status = $response->statusCode();
            $request = $response->request;

            // A change refused (e.g. a menu given as "lihat" only): stay on the page and say why.
            if ($status === 403 && $request->inertia() && ! $request->isMethodSafe()) {
                Inertia::flash('error', 'Akun Anda hanya bisa melihat menu ini, tidak bisa mengubah data. Hubungi Super Admin untuk akses ubah.');

                return back();
            }

            $asPage = in_array($status, self::USER_FACING_ERRORS, true)
                || (! config('app.debug') && in_array($status, self::SERVER_ERRORS, true));

            if (! $asPage || ($request->expectsJson() && ! $request->inertia())) {
                return null;
            }

            return $response->render('Error', ['status' => $status])->withSharedData();
        });
    }
}
