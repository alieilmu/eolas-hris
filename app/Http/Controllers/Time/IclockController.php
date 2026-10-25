<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Support\TimeClock\Adms;
use App\Support\TimeClock\AttendanceIngest;
use App\Support\TimeClock\DeviceRoute;
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
        $device = $this->device($request);
        if (! $device) {
            return $this->text('ERROR: SN', 400);
        }
        if ($request->isMethod('get')) {
            return $this->text(Adms::options($device->serial_number, $device->attlog_stamp));
        }

        $table = strtoupper((string) $request->query('table'));
        if ($table !== 'ATTLOG') {
            return $this->text('OK'); // operation log, user info, photos: not used
        }
        if ($device->status !== 'active') {
            return $this->text('ERROR: device not active');
        }
        $rows = Adms::attlog($request->getContent());
        AttendanceIngest::store($rows, $device, 'device');
        if ($stamp = $request->query('Stamp')) {
            $device->update(['attlog_stamp' => (string) $stamp]);
        }

        return $this->text('OK: '.count($rows));
    }

    public function getrequest(Request $request): Response
    {
        $this->device($request);

        return $this->text('OK');
    }

    public function devicecmd(Request $request): Response
    {
        $this->device($request);

        return $this->text('OK');
    }

    /** The machine of this request (opening its workspace), recorded as "baru" when unknown. */
    private function device(Request $request): ?AttendanceDevice
    {
        $sn = trim((string) $request->query('SN'));
        if ($sn === '' || strlen($sn) > 60 || ! preg_match('/^[A-Za-z0-9._-]+$/', $sn)) {
            return null;
        }
        $route = DeviceRoute::database($sn);
        if ($route && $route['database']) {
            Tenant::use($route['database']);
        }
        $device = AttendanceDevice::firstOrCreate(['serial_number' => $sn], ['name' => "Mesin baru {$sn}", 'status' => 'new']);
        if (! $route) {
            DeviceRoute::claim($sn);
        }
        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        return $device;
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain']);
    }
}
