<?php

namespace Database\Seeders;

use App\Models\LokasiPenerimaan;
use App\Models\LumpsumCuti;
use App\Models\Trip;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Cuti\Lumpsum;
use Illuminate\Database\Seeder;

/**
 * Lumpsum Cuti Lapangan for the demo (hired far from the site = more), the lumpsum of the
 * cuti lapangan already there, and some tickets already bought. Runs inside DemoSeeder, or:
 *
 *     php artisan db:seed --class=DemoLumpsumSeeder
 */
class DemoLumpsumSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WorkLocation::all() as $site) {
            foreach (LokasiPenerimaan::all() as $poh) {
                $lokal = $poh->work_location_id === $site->id;
                foreach (['Staff' => $lokal ? 750000 : 3500000, 'Non Staff' => $lokal ? 500000 : 2500000] as $klasifikasi => $nominal) {
                    LumpsumCuti::updateOrCreate(
                        ['work_location_id' => $site->id, 'lokasi_penerimaan_id' => $poh->id, 'klasifikasi' => $klasifikasi],
                        ['nominal' => $nominal],
                    );
                }
            }
        }

        foreach (Trip::with('karyawan')->where('kategori_cuti', 'lapangan')->whereNull('lumpsum')->get() as $trip) {
            $trip->update(['lumpsum' => Lumpsum::nominal($trip->karyawan, 'lapangan')]);
        }

        // Tickets of the trips already over were bought; the coming ones partly.
        $admin = User::where('role', User::ROLE_HR)->first() ?? User::first();
        foreach (Trip::where('status', 'disetujui')->where(fn ($q) => $q->where('tiket_pesawat', true)->orWhere('tiket_kereta', true))->orderBy('id')->get() as $i => $trip) {
            $lewat = $trip->tanggal_awal->isPast();
            if ($lewat || $i % 2 === 0) {
                $trip->update(['tiket_pergi_dibeli_at' => $trip->created_at, 'tiket_pergi_dibeli_by' => $admin?->id]);
            }
            if ($trip->pesawat_pp && $lewat) {
                $trip->update(['tiket_pulang_dibeli_at' => $trip->created_at, 'tiket_pulang_dibeli_by' => $admin?->id]);
            }
        }
    }
}
