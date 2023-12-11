<div class="form-control">
    @if ($label)
        <label for="small-input" class="flex items-center gap-1 mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <textarea placeholder="{{ $placeholder }}" {{ $attributes->merge(['class' => $defaultClass]) }}
        @if ($realtimeValidation) wire:model.debounce.200ms="{{ $id }}"
        @else
        wire:model={{ $value ?? $id }}
        wire:dirty.class="input-warning" @endif
        @disabled($disabled) @readonly($readonly) @required($required) id="{{ $id }}" name="{{ $name }}"
        name={{ $id }}></textarea>
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
