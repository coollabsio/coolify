@props(['listUrl' => null, 'canvasUrl' => null, 'onCanvas' => false, 'views' => null])
@php
    $idle = 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:hover:text-white';
    $canvasItem = 'relative flex h-7.5 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 text-[12px] font-medium transition-colors';
    $views ??= ['table' => ['Table view', 'unordered-list'], 'grid' => ['Grid view', 'grid']];
    // One list view (no table / grid choice): nothing to switch to on the list page.
    $singleList = count($views) === 1;
@endphp
{{-- Table / grid / canvas switcher. On the list page, an icon button switches the
     parent's Alpine viewMode to the other view; the canvas page shows one "List view" link back
     (?view= makes the list the remembered view again, see RemembersCanvasView).
     Pages with one list view pass :views="['list' => [...]]". Without a
     canvasUrl it is only a table / grid switcher. --}}
@if ($onCanvas || ! $singleList || filled($canvasUrl))
<div {{ $attributes->merge(['class' => 'view-toggle']) }}>
    @if ($onCanvas)
        {{-- On the canvas only the way back: one labelled link to the list (its first view). --}}
        @php [$label, $icon] = reset($views); @endphp
        <a href="{{ $listUrl }}?view={{ array_key_first($views) }}" {{ wireNavigate() }} class="{{ $canvasItem }} {{ $idle }}"
            title="List view">
            <x-reicon :name="$icon" class="size-3.5" />
            List view
        </a>
    @else
        {{-- Only the view that is not current shows, as an icon: the button switches to that view.
             The fixed-width slot keeps its width before Alpine starts, so the toolbar does not jump. --}}
        @unless ($singleList || count($views) === 0)
            <span class="flex w-7.5 shrink-0">
            @foreach ($views as $mode => [$label, $icon])
                <button type="button" x-on:click="setViewMode(@js($mode))" x-show="viewMode !== @js($mode)" x-cloak
                    class="{{ $canvasItem }} {{ $idle }} w-7.5 justify-center px-0!"
                    title="{{ $label }}" aria-label="{{ $label }}">
                    <x-reicon :name="$icon" class="size-3.5" />
                </button>
            @endforeach
            </span>
        @endunless
        @if (filled($canvasUrl))
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
    @endif
</div>
@endif
