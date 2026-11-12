@props([
    // Tooltip and screen-reader text, for example "2 recommendations" or "Sentinel is out of sync".
    'label' => 'Needs attention',
])

{{-- A yellow "!" on a menu item: the user should do something on that page. Status and severity use their own dots and badges. --}}
<span role="img" aria-label="{{ $label }}" data-tooltip="{{ $label }}"
    {{ $attributes->class(['ml-auto shrink-0 px-0.5 text-lg leading-none font-black text-warning [text-shadow:0_0_6px_rgb(234_179_8/0.6)]']) }}>!</span>
