<!DOCTYPE html>
@php $themeAttr = in_array(($t = request()->cookie('taap-theme')), ['light', 'dark'], true) ? $t : null; @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="js"@if ($themeAttr) data-bs-theme="{{ $themeAttr }}"@endif>
@props(['title' => null])
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title>
        {{ $title ? trim($title).' · ' : '' }}TAAP
    </title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{-- Tema (brand-identity D4): resuelve la preferencia (localStorage ->
         sistema -> claro) ANTES del primer pintado: sin flash. El
         conmutador vive en app.js. --}}
    {{-- spa-theme-persistence D2: el tema resuelto se espeja a la cookie
         taap-theme para que el <html> servido lo llega y la navegacion
         wire:navigate (que borra lo que el HTML recibido no trae y no
         re-ejecuta este script) lo conserve. --}}
    <script>
        (function () {
            var saved = localStorage.getItem('taap-theme');
            var theme = (saved === 'light' || saved === 'dark') ? saved
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
            document.cookie = 'taap-theme=' + theme + '; path=/; max-age=31536000';
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $reverbCfg = json_encode([
        'key' => config('broadcasting.connections.reverb.key'),
        'secret' => config('broadcasting.connections.reverb.secret'),
        'appId' => config('broadcasting.connections.reverb.app_id'),
        'host' => config('taap.public.host'),
        'port' => (int) config('taap.public.port'),
        'scheme' => config('taap.public.scheme'),
        'authEndpoint' => url('/api/v1/broadcasting/auth'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
@endphp
<body class="bg-light min-vh-100 d-flex flex-column"
      data-reverb='{{ $reverbCfg }}'>

@auth
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('agents.index') }}">
                <x-wordmark variant="dark" size="26" />
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="{{ route('agents.index') }}"><x-icon name="robot" class="me-1" />{{ __('nav.agents') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('models.index') }}"><x-icon name="box-seam" class="me-1" />{{ __('nav.models') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('providers.index') }}"><x-icon name="cloud-arrow-down" class="me-1" />{{ __('nav.providers') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('mcp.index') }}"><x-icon name="plug" class="me-1" />{{ __('nav.mcp') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('files.index') }}"><x-icon name="folder2" class="me-1" />{{ __('nav.files') }}</a></li>
                    @if (auth()->user()->isAdmin())
                        <li class="nav-item"><a class="nav-link" href="{{ route('users.index') }}"><x-icon name="people" class="me-1" />{{ __('nav.users') }}</a></li>
                        <li class="nav-item"><a class="nav-link" href="{{ route('audit.index') }}"><x-icon name="clock-history" class="me-1" />{{ __('nav.audit') }}</a></li>
                    @endif
                </ul>
                <span class="navbar-text me-3 d-none d-md-inline">
                    <span class="badge bg-{{ auth()->user()->isAdmin() ? 'warning' : 'secondary' }}">
                        <x-icon :name="auth()->user()->isAdmin() ? 'patch-check' : 'person-bounding'" class="me-1" />
                        {{ auth()->user()->isAdmin() ? __('badge.admin') : __('badge.user') }}
                    </span>
                </span>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            {{ auth()->user()->name }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text text-muted small">{{ auth()->user()->email }}</span></li>
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}">
                                    <x-icon name="person-circle" class="me-2" />
                                    {{ __('nav.profile') }}
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li><span class="dropdown-item-text text-muted small text-uppercase">{{ __('nav.theme') }}</span></li>
                            <li>
                                <button class="dropdown-item" type="button" data-taap-theme-set="auto">
                                    <x-icon name="circle-half" class="me-2" />
                                    {{ __('nav.theme.auto') }}
                                </button>
                            </li>
                            <li>
                                <button class="dropdown-item" type="button" data-taap-theme-set="light">
                                    <x-icon name="sun" class="me-2" />
                                    {{ __('nav.theme.light') }}
                                </button>
                            </li>
                            <li>
                                <button class="dropdown-item" type="button" data-taap-theme-set="dark">
                                    <x-icon name="moon-stars" class="me-2" />
                                    {{ __('nav.theme.dark') }}
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="dropdown-item" type="submit">{{ __('nav.logout') }}</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
@endauth

<main class="flex-grow-1 py-4">
    <div class="container-fluid px-4">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        {{ $slot }}
    </div>
</main>

<footer class="bg-dark text-secondary-foreground py-2">
    <div class="container-fluid px-4 small">
        TAAP · {{ __('footer.tagline') }}
    </div>
</footer>

</body>
</html>
