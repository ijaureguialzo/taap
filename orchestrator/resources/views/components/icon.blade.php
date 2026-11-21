@props(['name', 'size' => null, 'label' => null])

{{--
  Icono (brand-identity D2/D6): renderiza del set Bootstrap Icons
  (<i class="bi bi-{name}">). Sin `label` es decorativo (aria-hidden);
  con `label` es funcional y el texto queda para lectores de pantalla.
  `size`: sm (75 %) | lg (150 %) | xl (200 %).
--}}
@php
    $cls = ['bi', 'bi-' . $name];
    if ($size === 'sm') {
        $cls[] = 'bi-icon-sm';
    } elseif ($size === 'lg') {
        $cls[] = 'bi-icon-lg';
    } elseif ($size === 'xl') {
        $cls[] = 'bi-icon-xl';
    }
    $extra = $attributes->get('class');
    if ($extra) {
        $cls[] = $extra;
    }
@endphp
<i @class($cls) @if(! $label) aria-hidden="true" @endif {{ $attributes->except('class', 'size', 'label') }}>
    @if($label)<span class="sr-only">{{ $label }}</span>@endif
</i>
