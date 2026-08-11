@props(['target', 'text' => 'Loading records...'])

<div wire:loading.flex wire:target="{{ $target }}"
    {{ $attributes->class(['table-loading-overlay absolute inset-0 z-30 hidden items-center justify-center bg-white/70 backdrop-blur-[1px] dark:bg-black/20']) }}>
    <x-loading aria-label="{{ $text }}" class="[&_.loading-indicator]:size-5" />
</div>
