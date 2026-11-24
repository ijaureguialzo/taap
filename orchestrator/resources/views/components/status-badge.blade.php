@props(['status', 'agentId' => null])
@php
    // Mapa icono<->estado (brand-identity D6): el icono via del estado.
    $icons = [
        'starting' => 'arrow-repeat',
        'working' => 'lightning-charge',
        'waiting-approval' => 'hourglass-split',
        'error' => 'exclamation-octagon',
        'stopped' => 'stop-circle',
        'offline' => 'cloud-slash',
        'idle' => 'pause-circle',
    ];
    // 4.1: mapa COMPLETO estado -> {icono, etiqueta} renderizado por el
    // servidor e inyectado en el nodo: el JS actualiza icono+clase+texto
    // en vivo por cada status.change SIN round-trip (antes solo el texto
    // se actualizaba y el icono/colores quedaban en el estado anterior
    // hasta el siguiente render).
    $map = collect($icons)->mapWithKeys(fn (string $icon, string $state) => [
        $state => ['icon' => $icon, 'label' => __('status.' . $state)],
    ]);
    $icon = $icons[$status->value] ?? 'pause-circle';
@endphp
<span class="badge status-{{ $status->value }} live-status"
        @if ($agentId) data-agent-id="{{ $agentId }}" @endif
        data-status-value="{{ $status->value }}"
        data-status-map='{{ $map->toJson(JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) }}'>
    <x-icon :name="$icon" class="me-1" data-status-icon />
    <span data-status-label data-status-value="{{ $status->value }}">{{ __('status.' . $status->value) }}</span>
</span>
