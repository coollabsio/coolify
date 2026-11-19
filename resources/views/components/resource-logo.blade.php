@props(['resource', 'iconClass' => 'size-4'])

{{-- The resource logo (see resource_logo()). Shows the favicon once it loads, and the resource type icon when there is no logo or it fails to load. --}}
@php
    $logo = resource_logo($resource);
    $icon = match (true) {
        $resource instanceof \App\Models\Application => 'browser-code',
        $resource instanceof \App\Models\Service => 'layers',
        default => 'database',
    };
@endphp

<span class="contents" data-resource-logo>
    @if ($logo)
        <span class="flex size-full items-center justify-center" data-resource-logo-image>
            <img src="{{ $logo['src'] }}" alt="" data-fallback="{{ $logo['fallback'] }}"
                onerror="resourceLogoError(this)"
                @class(['size-full object-contain', 'p-1' => $logo['padded'], 'dark:hidden' => $logo['dark']]) />
            @if ($logo['dark'])
                <img src="{{ $logo['dark'] }}" alt="" onerror="resourceLogoError(this)"
                    class="hidden size-full object-contain dark:block" />
            @endif
        </span>
        @if ($logo['favicon'])
            <img src="{{ $logo['favicon'] }}" alt="" hidden data-resource-logo-favicon
                onload="resourceLogoLoaded(this)" onerror="resourceLogoError(this)"
                class="size-full object-contain p-1" />
        @endif
    @endif
    <x-reicon :name="$icon" :class="$iconClass" data-resource-logo-icon :hidden="(bool) $logo" />
</span>
