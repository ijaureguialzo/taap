@props(['size' => 26, 'variant' => 'auto'])
@php
    // Wordmark TAAP (brand-identity D1/D3): glifo del maestro taap-faucet.svg
    // (viewBox recortado al bbox del glifo 200..824 x 200..868) + texto.
    // variant: auto (los colores resuelve el CSS por tema) | dark (navbar).
    $cls = ['taap-wordmark', 'd-inline-flex', 'align-items-center', 'gap-2'];
    if ($variant === 'dark') {
        $cls[] = 'taap-wordmark-dark';
    }
    $extra = $attributes->get('class');
    if ($extra) {
        $cls[] = $extra;
    }
@endphp
<span @class($cls) {{ $attributes->except('class') }} style="--taap-wm-size: {{ $size }}px">
    <svg class="wm-faucet" width="1em" height="1.07em" viewBox="200 200 624 668" aria-hidden="true" style="font-size: var(--taap-wm-size)">
        <path class="wm-glyph" d="M 400 200 H 724 A 48 48 0 0 1 724 296 H 400 A 48 48 0 0 1 400 200 Z M 512 256 H 512 A 56 56 0 0 1 568 312 V 348 A 56 56 0 0 1 512 404 H 512 A 56 56 0 0 1 456 348 V 312 A 56 56 0 0 1 512 256 Z M 272 380 H 752 A 72 72 0 0 1 752 524 H 272 A 72 72 0 0 1 272 380 Z M 256 380 H 288 A 56 56 0 0 1 344 436 V 624 A 56 56 0 0 1 288 680 H 256 A 56 56 0 0 1 200 624 V 436 A 56 56 0 0 1 256 380 Z"/>
        <path class="wm-drop" d="M 272 700 C 296 748 336 768 336 812 A 64 64 0 1 1 208 812 C 208 768 248 748 272 700 Z"/>
    </svg>
    <span class="wm-text">TAAP</span>
</span>
