@php
    $rp = fn ($v) => number_format((int) $v, 0, ',', '.');
    $brand = $branding->brandColor();
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>1721-A1 {{ $year }} {{ $r['name'] }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1e293b; }
    .head { border-bottom: 3px solid {{ $brand }}; padding-bottom: 8px; margin-bottom: 12px; }
    .title { font-size: 14px; font-weight: bold; color: {{ $brand }}; }
    .muted { color: #64748b; }
    table { width: 100%; border-collapse: collapse; }
    .info td { padding: 2px 0; }
    .lines td { padding: 4px 6px; border-bottom: 1px solid #eef2f0; }
    .lines .n { width: 22px; color: #64748b; }
    .lines .amt { text-align: right; width: 120px; white-space: nowrap; }
    .lines .sub td { font-weight: bold; background: #f4f7f5; }
    .section td { font-weight: bold; color: {{ $brand }}; padding-top: 10px; border-bottom: 1.5px solid #cbd5e1; }
    .note { margin-top: 14px; font-size: 8.5px; color: #64748b; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; }
</style>
</head>
<body>
<table class="head"><tr>
    <td><b style="font-size: 11px">{{ $branding->company_name }}</b><br><span class="muted">NPWP pemotong {{ $settings->company_tax_id ?: '-' }}</span></td>
    <td style="text-align: right"><div class="title">RINGKASAN 1721-A1</div><div>Tahun pajak {{ $year }}</div>
        <div class="muted">Masa {{ $months[$r['month_start']] }} – {{ $months[$r['month_end']] }} {{ $year }} ({{ $r['months'] }} bulan){{ $r['complete'] ? '' : ' · BELUM LENGKAP' }}</div></td>
</tr></table>

<table class="info">
    <tr><td class="muted" style="width: 20%">Nama</td><td><b>{{ $r['name'] }}</b></td><td class="muted" style="width: 20%">NRP</td><td>{{ $r['employee_number'] }}</td></tr>
    <tr><td class="muted">NPWP / NIK</td><td>{{ $r['tax_id'] ?: $r['national_id'] ?: '-' }}</td><td class="muted">Status PTKP</td><td>{{ $r['ptkp'] }}</td></tr>
    <tr><td class="muted">Jabatan</td><td>{{ $r['position'] }}</td><td class="muted">Lokasi</td><td>{{ $r['location'] }}</td></tr>
</table>

<table class="lines" style="margin-top: 10px">
    <tr class="section"><td colspan="3">Penghasilan bruto</td></tr>
    @foreach ([1, 2, 3, 4, 5, 6, 7] as $n)<tr><td class="n">{{ $n }}</td><td>{{ $labels[$n] }}</td><td class="amt">{{ $rp($r['lines'][$n]) }}</td></tr>@endforeach
    <tr class="sub"><td class="n">8</td><td>{{ $labels[8] }}</td><td class="amt">{{ $rp($r['lines'][8]) }}</td></tr>
    <tr class="section"><td colspan="3">Pengurangan</td></tr>
    @foreach ([9, 10] as $n)<tr><td class="n">{{ $n }}</td><td>{{ $labels[$n] }}</td><td class="amt">{{ $rp($r['lines'][$n]) }}</td></tr>@endforeach
    <tr class="sub"><td class="n">11</td><td>{{ $labels[11] }}</td><td class="amt">{{ $rp($r['lines'][11]) }}</td></tr>
    <tr class="section"><td colspan="3">Penghitungan PPh 21</td></tr>
    @foreach ([12, 13, 14, 15, 16, 17, 18, 19, 20] as $n)<tr class="{{ in_array($n, [19, 20]) ? 'sub' : '' }}"><td class="n">{{ $n }}</td><td>{{ $labels[$n] }}</td><td class="amt">{{ $rp($r['lines'][$n]) }}</td></tr>@endforeach
</table>

<div class="note">
    Ringkasan ini disusun dari slip gaji yang sudah disetujui di {{ $branding->appName() }}. Bukti potong 1721-A1 resmi diterbitkan pemberi kerja melalui Coretax DJP.
    @if (! $r['complete']) Tahun pajak ini belum lengkap (gaji Desember belum disetujui): PPh 21 setahun dihitung saat Desember; sampai saat itu baris 17 dan 19 sama dengan yang sudah dipotong. @endif
    @if ($r['difference'] !== 0) Selisih PPh terutang dan yang sudah dipotong: Rp {{ $rp($r['difference']) }}. @endif
    Dicetak {{ now()->format('d.m.Y H:i') }}.
</div>
</body>
</html>
