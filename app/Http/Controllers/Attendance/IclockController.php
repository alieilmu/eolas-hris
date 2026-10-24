<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\MesinAbsen;
use App\Support\Kehadiran\Adms;
use App\Support\Kehadiran\Ingest;
use App\Support\Kehadiran\MesinRoute;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The ADMS endpoint attendance machines push to (/iclock/…, no session or CSRF; see
 * routes/iclock.php). A machine is known by its serial number: only an "aktif" one has its
 * attendance log taken; an unknown one is recorded as "baru" for HR to accept, and its log
 * is not acknowledged, so the machine keeps it and sends it again once accepted.
 */
class IclockController extends Controller
{
    public function cdata(Request $request): Response
    {
        $mesin = $this->mesin($request);
        if (! $mesin) {
            return $this->text('ERROR: SN', 400);
        }
        if ($request->isMethod('get')) {
            return $this->text(Adms::options($mesin->serial_number, $mesin->attlog_stamp));
        }

        $table = strtoupper((string) $request->query('table'));
        if ($table !== 'ATTLOG') {
            return $this->text('OK'); // operation log, user info, photos: not used
        }
        if ($mesin->status !== 'aktif') {
            return $this->text('ERROR: device not active');
        }
        $rows = Adms::attlog($request->getContent());
        Ingest::store($rows, $mesin, 'mesin');
        if ($stamp = $request->query('Stamp')) {
            $mesin->update(['attlog_stamp' => (string) $stamp]);
        }

        return $this->text('OK: '.count($rows));
    }

    public function getrequest(Request $request): Response
    {
        $this->mesin($request);

        return $this->text('OK');
    }

    public function devicecmd(Request $request): Response
    {
        $this->mesin($request);

        return $this->text('OK');
    }

    /** The machine of this request (opening its workspace), recorded as "baru" when unknown. */
    private function mesin(Request $request): ?MesinAbsen
    {
        $sn = trim((string) $request->query('SN'));
        if ($sn === '' || strlen($sn) > 60 || ! preg_match('/^[A-Za-z0-9._-]+$/', $sn)) {
            return null;
        }
        $route = MesinRoute::database($sn);
        if ($route && $route['database']) {
            Tenant::use($route['database']);
        }
        $mesin = MesinAbsen::firstOrCreate(['serial_number' => $sn], ['nama' => "Mesin baru {$sn}", 'status' => 'baru']);
        if (! $route) {
            MesinRoute::claim($sn);
        }
        $mesin->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        return $mesin;
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain']);
    }
}
