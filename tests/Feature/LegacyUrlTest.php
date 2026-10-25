<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Page URLs from before the terminology refactor still lead to the page (301). */
class LegacyUrlTest extends TestCase
{
    public function test_old_page_urls_redirect_permanently_to_the_new_ones(): void
    {
        $cases = [
            '/manpower/karyawan' => '/core-hr/employees',
            '/manpower/karyawan/12/profil' => '/core-hr/employees/12/profile',
            '/manpower/karyawan/data-lengkap' => '/core-hr/employees/full-data',
            '/attendance/roster?period=2026-10' => '/time/shift-schedule?period=2026-10',
            '/attendance/kehadiran' => '/time/clock',
            '/comben/cuti' => '/time/leave-balances',
            '/comben/deklarasi?status=diajukan' => '/compensation/claims?status=diajukan',
            '/portal' => '/self-service',
            '/portal/data' => '/self-service/profile',
            '/recruitment/tracking' => '/recruitment/pipeline',
            '/settings/akun-demo' => '/settings/trial-accounts',
            '/lowongan/3' => '/careers/3',
        ];
        foreach ($cases as $old => $new) {
            $this->get($old)->assertStatus(301)->assertRedirect($new);
        }
    }
}
