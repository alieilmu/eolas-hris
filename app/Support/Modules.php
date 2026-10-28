<?php

namespace App\Support;

use App\Support\License\License;

/**
 * The packages Eolas is sold in. Core is every module there is today (and all of Fase 1);
 * Talent and LMS are add-ons a client buys separately. A client installation gets the modules
 * its license names (a license without "modules" means Core only); the hosted demo service
 * and its workspaces get them all.
 */
class Modules
{
    public const CORE = 'core';

    public const LABELS = [
        'core' => 'Core (HR, Waktu, Kompensasi & Payroll, Rekrutmen)',
        'talent' => 'Talent (KPI, Kompetensi, Jalur Karier, Suksesi)',
        'lms' => 'LMS (Kursus, Ujian, Sertifikat)',
    ];

    /** Add-on => the access menus (Menu::MODULES) it brings; every other menu is Core. */
    public const MENUS = [
        'talent' => [],
        'lms' => [],
    ];

    /** @var array<string, string> menu => add-on, registered by tests before the add-ons have menus. */
    private static array $testMenus = [];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The modules this installation may use.
     *
     * @return list<string>
     */
    public static function active(): array
    {
        if (! Edition::client()) {
            return self::keys();
        }
        $license = License::check();

        return $license['valid'] ? self::normalize($license['modules'] ?? null) : [self::CORE];
    }

    public static function enabled(string $module): bool
    {
        return in_array($module, self::active(), true);
    }

    /** The module a menu belongs to. */
    public static function ofMenu(string $menu): string
    {
        if (isset(self::$testMenus[$menu])) {
            return self::$testMenus[$menu];
        }
        foreach (self::MENUS as $module => $menus) {
            if (in_array($menu, $menus, true)) {
                return $module;
            }
        }

        return self::CORE;
    }

    public static function menuEnabled(string $menu): bool
    {
        return self::enabled(self::ofMenu($menu));
    }

    /**
     * Known modules only, Core always first: what a license file or --modules gives.
     *
     * @return list<string>
     */
    public static function normalize(mixed $modules): array
    {
        $given = is_array($modules) ? array_map('strval', $modules) : [];

        return array_values(array_filter(self::keys(), fn (string $m) => $m === self::CORE || in_array($m, $given, true)));
    }

    /** @param  array<string, string>  $menus  menu => add-on */
    public static function assignMenusForTesting(array $menus): void
    {
        self::$testMenus = $menus;
    }
}
