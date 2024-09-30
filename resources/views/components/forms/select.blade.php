<div class="w-full">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <select {{ $attributes->merge(['class' => $defaultClass]) }} @required($required)
        wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
        wire:dirty.class="dark:focus:ring-warning dark:ring-warning" wire:loading.attr="disabled" name={{ $id }}
        @if ($attributes->whereStartsWith('wire:model')->first()) {{ $attributes->whereStartsWith('wire:model')->first() }} @else wire:model={{ $id }} @endif>
        {{ $slot }}
    </select>
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
