<?php

namespace App\Console\Commands;

use App\Support\License\License;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Production team only: writes a signed license.json for a client. Needs Eolas' private key
 * (never shipped to clients).
 */
class IssueLicense extends Command
{
    protected $signature = 'eolas:license:issue
        {company : nama perusahaan klien}
        {--tier=starter : starter, growth, enterprise atau custom}
        {--max= : jumlah karyawan aktif maksimum (bawaan sesuai tier)}
        {--support-until= : tanggal akhir support Y-m-d (bawaan: 1 tahun)}
        {--key= : file kunci privat Eolas}
        {--out=license.json : file hasil}';

    protected $description = 'Membuat file lisensi Eolas untuk klien on-premise (tim Production)';

    private const TIER_MAX = ['starter' => 100, 'growth' => 300, 'enterprise' => 1000];

    public function handle(): int
    {
        $keyFile = $this->option('key') ?: env('EOLAS_LICENSE_PRIVATE_KEY_FILE');
        if (! $keyFile || ! is_readable($keyFile)) {
            $this->error('Kunci privat tidak ditemukan. Pakai --key=/path/eolas-license-private.key');

            return self::FAILURE;
        }
        $tier = (string) $this->option('tier');
        if (! array_key_exists($tier, License::TIERS)) {
            $this->error('Tier harus salah satu dari: '.implode(', ', array_keys(License::TIERS)));

            return self::FAILURE;
        }
        $max = (int) ($this->option('max') ?: (self::TIER_MAX[$tier] ?? 0));
        if ($max < 1) {
            $this->error('Isi --max untuk tier custom.');

            return self::FAILURE;
        }
        $until = $this->option('support-until') ? CarbonImmutable::parse($this->option('support-until')) : CarbonImmutable::today()->addYear();
        $payload = [
            'license_id' => 'EOL-'.strtoupper(Str::random(8)),
            'company' => (string) $this->argument('company'),
            'tier' => $tier,
            'max_employees' => $max,
            'support_until' => $until->toDateString(),
            'issued_at' => CarbonImmutable::now()->toDateString(),
        ];
        file_put_contents($this->option('out'), License::sign($payload, trim(file_get_contents($keyFile))));
        $this->info("Lisensi {$payload['license_id']} untuk {$payload['company']}: {$max} karyawan, support s.d. {$payload['support_until']} → ".$this->option('out'));

        return self::SUCCESS;
    }
}
