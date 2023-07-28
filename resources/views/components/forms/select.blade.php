<div class="w-full">
    @if ($label)
        <label for="small-input" class="flex items-center gap-1 mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <span class="text-warning">*</span>
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <select {{ $attributes->merge(['class' => $defaultClass]) }} @required($required) wire:dirty.class.remove='text-white'
        wire:dirty.class="text-black bg-warning" wire:loading.attr="disabled" name={{ $id }}
        @if ($attributes->whereStartsWith('wire:model')->first()) {{ $attributes->whereStartsWith('wire:model')->first() }} @else wire:model.defer={{ $id }} @endif>
        {{ $slot }}
    </select>
</div>
