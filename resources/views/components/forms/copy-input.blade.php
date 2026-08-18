@props(['text', 'label' => null])

<div class="w-full">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium text-black dark:text-white">{{ $label }}</label>
    @endif
    <div class="relative">
        <input type="text" value="{{ $text }}"
            class="input input-with-copy-button bg-white dark:bg-coolgray-100 dark:read-only:bg-coolgray-100 dark:read-only:text-white"
            readonly
            @keydown.prevent @paste.prevent @cut.prevent @drop.prevent
            @focus="$event.target.select()">
        <x-copy-button :value="$text" class="absolute top-1/2 right-2 -translate-y-1/2" />
    </div>
</div>
