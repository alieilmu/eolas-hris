<?php

namespace App\Support\Kehadiran;

use Carbon\CarbonImmutable;

/**
 * The ADMS ("iclock") push protocol of ZKTeco-compatible attendance machines (ZKTeco,
 * Solution, Fingerspot, …): the machine asks GET /iclock/cdata for its options, then
 * POSTs its attendance log (table=ATTLOG) as lines "PIN \t yyyy-mm-dd hh:mm:ss \t status
 * \t verify \t workcode …", and polls /iclock/getrequest for commands.
 */
class Adms
{
    /** The options answer to a machine's handshake. */
    public static function options(string $sn, ?string $stamp): string
    {
        return implode("\n", [
            "GET OPTION FROM: {$sn}",
            'ATTLOGStamp='.($stamp ?: 'None'),
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog',
            'Realtime=1',
            'Encrypt=None',
        ])."\n";
    }

    /** @return list<array{pin: string, waktu: string, status: ?int, verify: ?string}> */
    public static function attlog(string $body): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            $f = array_map('trim', explode("\t", $line));
            if (count($f) < 2 || $f[0] === '' || ! ($waktu = self::waktu($f[1]))) {
                continue;
            }
            $rows[] = ['pin' => $f[0], 'waktu' => $waktu, 'status' => isset($f[2]) && is_numeric($f[2]) ? (int) $f[2] : null, 'verify' => $f[3] ?? null];
        }

        return $rows;
    }

    /** "2026-10-24 07:01:22" (or another readable date-time) as Y-m-d H:i:s, else null. */
    public static function waktu(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'] as $format) {
            $d = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($d && $d->format($format) === $value) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
