<?php

namespace App\Support\Tenancy;

use App\Models\Branding;
use App\Models\TrialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Demo workspaces: every demo account works in its own database (hris_eloas_t{id}) and
 * its own files (storage/app/tenants/{database}). A request of a workspace points the
 * default "mysql" connection, the "local" disk and the cache there; the main database
 * stays reachable as "central" (sessions, queue, demo accounts).
 */
class Tenant
{
    public const PREFIX = 'hris_eloas_t';

    public static function databaseFor(TrialAccount $account): string
    {
        return self::PREFIX.$account->id;
    }

    /** Points this process at the workspace $database. */
    public static function use(string $database): void
    {
        self::assertName($database);
        self::point($database, self::root($database));
        app()->instance('tenant.database', $database);
    }

    /** Back to the main database and files. */
    public static function central(): void
    {
        self::point((string) config('database.connections.central.database'), storage_path('app/private'));
        app()->forgetInstance('tenant.database');
        app()->forgetInstance(TrialAccount::class);
    }

    /** The workspace database of this request, or null in the main application. */
    public static function current(): ?string
    {
        return app()->bound('tenant.database') ? app('tenant.database') : null;
    }

    /** The demo account of this request's workspace. */
    public static function account(): ?TrialAccount
    {
        $database = self::current();
        if (! $database) {
            return null;
        }
        if (! app()->bound(TrialAccount::class)) {
            app()->instance(TrialAccount::class, TrialAccount::where('database', $database)->first());
        }

        return app(TrialAccount::class);
    }

    public static function create(string $database): void
    {
        self::assertName($database);
        DB::connection('central')->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        File::ensureDirectoryExists(self::root($database));
    }

    /** Removes the workspace database and its files. */
    public static function drop(string $database): void
    {
        self::assertName($database);
        // Workspaces are MySQL databases; the tests' SQLite has none to drop.
        if (DB::connection('central')->getDriverName() === 'mysql') {
            DB::connection('central')->statement("DROP DATABASE IF EXISTS `{$database}`");
        }
        File::deleteDirectory(self::root($database));
    }

    public static function root(string $database): string
    {
        return storage_path("app/tenants/{$database}");
    }

    private static function point(string $database, string $root): void
    {
        config(['database.connections.mysql.database' => $database]);
        DB::purge('mysql');
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        // The database cache store keeps its connection: let it reconnect to the new one.
        Cache::forgetDriver(config('cache.default'));
        Branding::forget();
    }

    private static function assertName(string $database): void
    {
        if (! preg_match('/^'.self::PREFIX.'\d+$/', $database)) {
            throw new \InvalidArgumentException("Bukan nama database workspace: {$database}");
        }
    }
}
