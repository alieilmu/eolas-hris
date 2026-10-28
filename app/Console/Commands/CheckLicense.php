<?php

namespace App\Console\Commands;

use App\Support\License\License;
use Illuminate\Console\Command;

/** Exit 0 when the license is valid (and, with --for-update, support still runs): used by eolas-update. */
class CheckLicense extends Command
{
    protected $signature = 'eolas:license:check {--for-update : juga cek masa support}';

    protected $description = 'Memeriksa lisensi instalasi ini';

    public function handle(): int
    {
        $s = License::summary();
        if (! $s['valid']) {
            $this->error($s['reason']);

            return self::FAILURE;
        }
        $this->line("{$s['license_id']} · {$s['company']} · {$s['active_employees']}/{$s['max_employees']} karyawan aktif · modul ".implode('+', array_column($s['modules'], 'key'))." · support s.d. {$s['support_until']}");
        if ($this->option('for-update') && ! $s['supported']) {
            $this->error('Masa support sudah berakhir: perpanjang support untuk mendapat update.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
