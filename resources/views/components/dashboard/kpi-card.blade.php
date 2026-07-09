@props([
    'label',
    'total',
    'active',
    'inactive',
    'href' => null,
])

@php
    $cardClass = 'coolbox flex flex-col justify-center gap-2 p-4 min-h-28';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ wireNavigate() }} {{ $attributes->merge(['class' => $cardClass . ' cursor-pointer group']) }}>
@else
    <div {{ $attributes->merge(['class' => $cardClass]) }}>
@endif
        <div class="text-3xl font-bold dark:text-white">{{ $total }}</div>
        <div class="box-title">{{ $label }}</div>
        <div class="flex items-center gap-2 text-sm font-medium">
            <span class="text-success">{{ $active }}</span>
            <span class="text-neutral-500">/</span>
            <span class="text-error">{{ $inactive }}</span>
            <span class="text-xs text-neutral-500 font-normal">Active / Inactive</span>
        </div>
@if ($href)
    </a>
@else
    </div>
@endif
