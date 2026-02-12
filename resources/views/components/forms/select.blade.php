<div class="w-full">
    @if ($label)
        <label
            class="flex gap-1 items-center mb-1 text-sm font-medium {{ $disabled ? 'text-neutral-600' : '' }}">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <select {{ $attributes->merge(['class' => $defaultClass]) }} @disabled($disabled) @required($required)
        wire:loading.attr="disabled" name={{ $modelBinding }} id="{{ $htmlId }}"
        @if ($attributes->whereStartsWith('wire:model')->first()) {{ $attributes->whereStartsWith('wire:model')->first() }} wire:dirty.class="ring-2 ring-coollabs dark:ring-warning" @else wire:model={{ $modelBinding }} wire:dirty.class="ring-2 ring-coollabs dark:ring-warning" @endif>
        {{ $slot }}
    </select>
    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
