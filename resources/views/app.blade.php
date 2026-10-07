<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full antialiased">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php($branding = \App\Models\Branding::current())
        <title inertia>{{ $branding->appName() }}</title>
        <meta name="app-name" content="{{ $branding->appName() }}">

        <link rel="icon" href="{{ $branding->toShare()['favicon_url'] }}">
        @fonts
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
        {{-- White label colours: the brand / accent scales of app.css, drawn from the two chosen colours. --}}
        <style>{!! \App\Support\Branding\Palette::css($branding->brandColor(), $branding->accentColor()) !!}</style>
    </head>
    <body class="h-full bg-slate-50 font-sans text-slate-900">
        @inertia
    </body>
</html>
