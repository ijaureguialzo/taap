<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $title ?? 'TAAP' }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-light d-flex align-items-center" style="min-height: 100vh">
    <div class="container" style="max-width: 420px">
        <div class="text-center mb-4">
            <h1 class="h2 fw-bold">⚡ TAAP</h1>
            <p class="text-muted mb-0">{{ __('footer.tagline') }}</p>
        </div>
        {{ $slot }}
    </div>
</body>
</html>
