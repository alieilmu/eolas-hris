@php
    $rp = fn ($v) => $v === null ? '' : number_format((int) $v, 0, ',', '.');
    $brand = $branding->brandColor();
    $methods = ['gross' => 'Gross', 'gross_up' => 'Gross-up', 'net' => 'Net'];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Slip Gaji {{ $data['employee']['name'] }} {{ $run->period }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
    .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 8px; margin-bottom: 12px; }
    .head td { vertical-align: middle; }
    .title { font-size: 15px; font-weight: bold; color: {{ $brand }}; }
    .muted { color: #64748b; }
    table { width: 100%; border-collapse: collapse; }
    .info td { padding: 2px 0; }
    .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; }
    .lines td { padding: 3px 0; border-bottom: 1px solid #f1f5f9; }
    .lines .amt { text-align: right; white-space: nowrap; }
    .lines .total td { font-weight: bold; border-top: 1.5px solid #cbd5e1; border-bottom: none; }
    h3 { font-size: 11px; margin: 0 0 4px; color: #334155; }
    .note { color: #94a3b8; font-size: 8.5px; }
    .thp { background: {{ $brand }}; color: #fff; padding: 9px 12px; border-radius: 6px; font-size: 13px; font-weight: bold; margin-top: 10px; }
    .neg { color: #be123c; }
    .foot { margin-top: 18px; font-size: 8.5px; color: #94a3b8; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width: 55%">
            @if ($logo)<img src="{{ $logo }}" style="height: 34px"><br>@endif
            <span style="font-weight: bold; font-size: 11px">{{ $branding->company_name }}</span>
        </td>
        <td style="text-align: right">
            <div class="title">SLIP GAJI</div>
            <div>{{ $data['period']['label'] ?? $run->period }}</div>
            <div class="muted">Periode {{ $data['period']['range'] ?? '' }}</div>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td style="width: 50%; vertical-align: top">
            <table>
                <tr><td class="muted" style="width: 32%">Nama</td><td><b>{{ $data['employee']['name'] }}</b></td></tr>
                <tr><td class="muted">NRP</td><td>{{ $data['employee']['employee_number'] }}</td></tr>
                <tr><td class="muted">Jabatan</td><td>{{ $data['employee']['position'] }}</td></tr>
                <tr><td class="muted">Lokasi</td><td>{{ $data['employee']['location'] }}</td></tr>
            </table>
        </td>
        <td style="width: 50%; vertical-align: top">
            <table>
                <tr><td class="muted" style="width: 38%">PTKP / TER</td><td>{{ $data['ptkp'] }} / {{ $data['ter_category'] }}</td></tr>
                <tr><td class="muted">Metode PPh21</td><td>{{ $methods[$data['tax_method']] ?? $data['tax_method'] }}</td></tr>
                <tr><td class="muted">Rekening</td><td>{{ implode(' · ', $data['bank']) ?: '-' }}</td></tr>
                <tr><td class="muted">Tanggal bayar</td><td>{{ $run->pay_date?->format('d.m.Y') ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin-top: 12px">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 6px">
            <div class="box">
                <h3>Pendapatan</h3>
                <table class="lines">
                    @foreach ($data['earnings'] as $l)
                        <tr><td>{{ $l['label'] }} @if (! empty($l['note']))<span class="note">{{ $l['note'] }}</span>@endif</td><td class="amt {{ $l['amount'] < 0 ? 'neg' : '' }}">{{ $rp($l['amount']) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total pendapatan</td><td class="amt">{{ $rp($data['total_earnings']) }}</td></tr>
                </table>
            </div>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 6px">
            <div class="box">
                <h3>Potongan</h3>
                <table class="lines">
                    @foreach ($data['deductions'] as $l)
                        <tr><td>{{ $l['label'] }} @if (! empty($l['note']))<span class="note">{{ $l['note'] }}</span>@endif</td><td class="amt">{{ $rp($l['amount']) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total potongan</td><td class="amt">{{ $rp($data['total_deductions']) }}</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="thp">
    <table><tr><td>TAKE HOME PAY</td><td style="text-align: right">Rp {{ $rp($data['take_home_pay']) }}</td></tr></table>
</div>

<table style="margin-top: 12px">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 6px">
            <div class="box">
                <h3>Ditanggung perusahaan</h3>
                <table class="lines">
                    @foreach ($data['company'] as $l)
                        <tr><td>{{ $l['label'] }} @if (! empty($l['note']))<span class="note">{{ $l['note'] }}</span>@endif</td><td class="amt">{{ $rp($l['amount']) }}</td></tr>
                    @endforeach
                </table>
            </div>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 6px">
            <div class="box">
                <h3>{{ $data['mode'] === 'annual' ? 'PPh21 setahun (Pasal 17)' : 'PPh21 (TER bulanan)' }}</h3>
                <table class="lines">
                    @foreach ($data['tax_steps'] as $l)
                        <tr><td>{{ $l['label'] }} @if (! empty($l['note']))<span class="note">{{ $l['note'] }}</span>@endif</td><td class="amt">{{ $rp($l['amount']) }}</td></tr>
                    @endforeach
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="foot">
    Slip ini dibuat oleh sistem {{ $branding->appName() }} dan sah tanpa tanda tangan. Bersifat rahasia, hanya untuk karyawan yang bersangkutan.
    Dicetak {{ now()->format('d.m.Y H:i') }}.
</div>
</body>
</html>
