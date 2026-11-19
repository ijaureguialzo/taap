<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
        'authEndpoint' => url('/broadcasting/auth'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
@endphp
<body class="bg-light min-vh-100 d-flex flex-column"
      data-reverb='{{ $reverbCfg }}'>

@auth
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="{{ route('agents.index') }}">
                ⚡ TAAP
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="nav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="{{ route('agents.index') }}">{{ __('nav.agents') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('models.index') }}">{{ __('nav.models') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('providers.index') }}">{{ __('nav.providers') }}</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('mcp.index') }}">{{ __('nav.mcp') }}</a></li>
                    @if (auth()->user()->isAdmin())
                        <li class="nav-item"><a class="nav-link" href="{{ route('users.index') }}">{{ __('nav.users') }}</a></li>
                    @endif
                </ul>
                <span class="navbar-text me-3 d-none d-md-inline">
                    <span class="badge bg-{{ auth()->user()->isAdmin() ? 'warning text-dark' : 'secondary' }}">
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
