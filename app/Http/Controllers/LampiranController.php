<?php

namespace App\Http\Controllers;

use App\Models\Deklarasi;
use App\Models\Lampiran;
use App\Models\PengajuanUpdateData;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opens a nota / signed form inline, for the employee it belongs to or an admin who
 * may see its menu (Deklarasi / Monitoring, or Trip & Leave / Pengajuan Dinas) and
 * whose locations include that employee.
 */
class LampiranController extends Controller
{
    public function __invoke(Request $request, Lampiran $lampiran): StreamedResponse
    {
        // Through the record's own access scope: someone outside the account's locations is not found.
        $owner = $lampiran->lampirable()->first();
        abort_unless($owner instanceof Deklarasi || $owner instanceof Trip || $owner instanceof PengajuanUpdateData, 404);

        $user = $request->user();
        $allowed = $user->isKaryawan()
            ? $owner->karyawan_id === $user->karyawan_id
            : match (true) {
                $owner instanceof Deklarasi => $user->canView('deklarasi', 'monitoring-plafon'),
                $owner instanceof Trip => $user->canView('trip-leave', 'pengajuan-dinas'),
                default => $user->canView('update-data', 'karyawan'),
            };
        abort_unless($allowed, 403);
        abort_unless(Storage::disk(Lampiran::DISK)->exists($lampiran->path), 404);

        return Storage::disk(Lampiran::DISK)->response($lampiran->path, $lampiran->nama, [
            'Content-Type' => $lampiran->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
