<!DOCTYPE html>
{{-- Shown by the service worker when a page cannot be reached. The card stands alone; the
     script (kept by the service worker with its build files) replaces it with the Absen HP
     button for an employee who uses it on this phone. --}}
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="{{ $b->brandColor() }}">
        <title>Tidak ada koneksi - {{ $b->appName() }}</title>
        <style>
            *{box-sizing:border-box}
            body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
                font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#0f172a}
            .card{width:100%;max-width:360px;text-align:center;background:#fff;border:1px solid #e2e8f0;border-top:4px solid {{ $b->accentColor() }};
                border-radius:24px;padding:32px 24px;box-shadow:0 1px 2px rgba(15,23,42,.06)}
            img{width:72px;height:72px;border-radius:18px}
            h1{font-size:18px;margin:16px 0 6px}
            p{font-size:14px;line-height:1.5;color:#64748b;margin:0}
            .card button{margin-top:20px;width:100%;border:0;border-radius:14px;padding:12px 16px;font-size:14px;font-weight:700;
                color:#fff;background:{{ $b->brandColor() }};cursor:pointer}
            small{display:block;margin-top:14px;font-size:12px;color:#94a3b8}
            #offline-clock{width:100%}
            #offline-clock:empty{display:none}
        </style>
        @vite(['resources/css/app.css', 'resources/js/offline.tsx'])
        <style>{!! \App\Support\Branding\Palette::css($b->brandColor(), $b->accentColor()) !!}</style>
    </head>
    <body>
        <div id="offline-clock"></div>
        <div class="card" id="offline-plain">
            <img src="{{ $b->appIconUrl(192) }}" alt="">
            <h1>Tidak ada koneksi internet</h1>
            <p>{{ $b->appName() }} butuh koneksi untuk membuka halaman ini. Periksa sinyal atau Wi-Fi, lalu coba lagi.</p>
            <button type="button" onclick="location.reload()">Coba lagi</button>
            <small>Data Anda tidak disimpan di HP ini, kecuali absen yang diambil tanpa sinyal sampai terkirim.</small>
        </div>
    </body>
</html>
