<div @class([
    'flex-1' => $isMultiline,
    'w-full' => !$isMultiline,
])>
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
    @if ($type === 'password')
        <div class="relative" x-data="{ type: 'password' }">
            @if ($allowToPeak)
                <div x-on:click="changePasswordFieldType"
                    class="flex absolute inset-y-0 right-0 items-center pr-2 cursor-pointer hover:dark:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                        <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                    </svg>
                </div>
            @endif
            <input autocomplete="{{ $autocomplete }}" value="{{ $value }}"
                {{ $attributes->merge(['class' => $defaultClass]) }} @required($required)
                @if ($id !== 'null') wire:model={{ $id }} @endif
                wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
                wire:dirty.class="dark:focus:ring-warning dark:ring-warning" wire:loading.attr="disabled"
                type="{{ $type }}" @readonly($readonly) @disabled($disabled) id="{{ $id }}"
                name="{{ $name }}" placeholder="{{ $attributes->get('placeholder') }}"
                aria-placeholder="{{ $attributes->get('placeholder') }}">

        </div>
    @else
        <input autocomplete="{{ $autocomplete }}" @if ($value) value="{{ $value }}" @endif
            {{ $attributes->merge(['class' => $defaultClass]) }} @required($required) @readonly($readonly)
            @if ($id !== 'null') wire:model={{ $id }} @endif
            wire:dirty.class.remove='dark:focus:ring-coolgray-300 dark:ring-coolgray-300'
            wire:dirty.class="dark:focus:ring-warning dark:ring-warning" wire:loading.attr="disabled"
            type="{{ $type }}" @disabled($disabled) min="{{ $attributes->get('min') }}"
            max="{{ $attributes->get('max') }}" minlength="{{ $attributes->get('minlength') }}"
            maxlength="{{ $attributes->get('maxlength') }}"
            @if ($id !== 'null') id={{ $id }} @endif name="{{ $name }}"
            placeholder="{{ $attributes->get('placeholder') }}">
    @endif
    @if (!$label && $helper)
        <x-helper :helper="$helper" />
    @endif
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
