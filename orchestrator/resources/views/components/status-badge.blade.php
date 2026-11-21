@props(['status', 'agentId' => null])
@php
    // Mapa icono<->estado (brand-identity D6): el icono via del estado,
    // que llega por Livewire/Echo en cada re-render.
    $icon = match ($status->value) {
        'starting' => 'arrow-repeat',
        'working' => 'lightning-charge',
        'waiting-approval' => 'hourglass-split',
        'error' => 'exclamation-octagon',
        'stopped' => 'stop-circle',
        'offline' => 'cloud-slash',
        default => 'pause-circle',
    };
@endphp
<span class="badge status-{{ $status->value }} live-status"
        @if ($agentId) data-agent-id="{{ $agentId }}" @endif data-status-value="{{ $status->value }}">
    <x-icon :name="$icon" class="me-1" />
    <span data-status-label data-status-value="{{ $status->value }}">{{ __('status.' . $status->value) }}</span>
</span>
