@props(['listUrl', 'canvasUrl', 'onCanvas' => false, 'views' => null])
@php
    $item = 'flex size-7.5 items-center justify-center rounded-md transition-colors';
    $idle = 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg';
    $canvasItem = 'relative flex h-7.5 items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium transition-colors';
    $views ??= ['table' => ['Table view', 'unordered-list'], 'grid' => ['Grid view', 'grid']];
    // One list view (no table / grid choice): the list page shows it selected.
    $singleList = count($views) === 1;
@endphp
{{-- Table / grid / canvas switcher. On the list page, table and grid switch the
     parent's Alpine viewMode; on the canvas page they link back to the list
     (?view= makes the list the remembered view again, see RemembersCanvasView).
     Pages with one list view pass :views="['list' => [...]]". --}}
<div {{ $attributes->merge(['class' => 'view-toggle']) }}>
    @foreach ($views as $mode => [$label, $icon])
        @if ($onCanvas)
            <a href="{{ $listUrl }}?view={{ $mode }}" {{ wireNavigate() }} class="{{ $item }} {{ $idle }}"
                aria-label="{{ $label }}" title="{{ $label }}">
                <x-reicon :name="$icon" class="size-3.5" />
            </a>
        @elseif ($singleList)
            <span class="{{ $item }} control-selected" aria-current="page" aria-label="{{ $label }}"
                title="{{ $label }}">
                <x-reicon :name="$icon" class="size-3.5" />
            </span>
        @else
            <button type="button" x-on:click="setViewMode(@js($mode))" class="{{ $item }}"
                :class="viewMode === @js($mode) ? 'control-selected' : @js($idle)"
                aria-label="{{ $label }}" title="{{ $label }}">
                <x-reicon :name="$icon" class="size-3.5" />
            </button>
        @endif
    @endforeach
    @if ($onCanvas)
        <span class="{{ $canvasItem }} control-selected" aria-current="page" title="Canvas view (beta)">
            <x-reicon name="deploy-canvas" class="size-3.5" />
            Canvas
        </span>
    @else
        {{-- A label and a purple "Try it" badge, so people notice the canvas view. --}}
        <a href="{{ $canvasUrl }}" {{ wireNavigate() }}
            class="{{ $canvasItem }} {{ $idle }}" title="Canvas view (beta): see everything and how it connects">
            <x-reicon name="deploy-canvas" class="size-3.5" />
            Canvas
            <span
                class="ml-0.5 inline-flex items-center rounded-full bg-coollabs px-1.5 py-px text-[10px] leading-4 font-semibold text-white">
                Try it
            </span>
        </a>
    @endif
</div>
