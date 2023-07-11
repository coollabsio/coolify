<div class="w-full">
    @if ($label)
        <label for="small-input"
            class="flex items-center gap-1 mb-2 text-sm font-medium text-neutral-400">{{ $label }}
            @if ($required)
                <span class="text-warning">*</span>
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    @if ($type !== 'password')
        <input {{ $attributes->merge(['class' => $defaultClass]) }} @required($required)
            wire:model.defer={{ $id }} wire:dirty.class.remove='text-white'
            wire:dirty.class="text-black bg-warning" wire:loading.attr="disabled" type="{{ $type }}"
            @disabled($disabled) id="{{ $id }}" name="{{ $name }}">
    @elseif ($type === 'password')
        <div class="relative" x-data>
            @if ($allowToPeak)
                <div x-on:click="changePasswordFieldType"
                    class="absolute inset-y-0 left-0 flex items-center pl-2 cursor-pointer hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                        stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                        <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                    </svg>
                </div>
            @endif
            <input {{ $attributes->merge(['class' => $defaultClass . ' pl-10']) }} @required($required)
                wire:model.defer={{ $id }} wire:dirty.class.remove='text-white'
                wire:dirty.class="text-black bg-warning" wire:loading.attr="disabled" type="{{ $type }}"
                @disabled($disabled) id="{{ $id }}" name="{{ $name }}">
        </div>
    @endif
    @if (!$label && $helper)
        <x-helper :helper="$helper" />
    @endif
</div>
{{-- <div class="w-full">
    @if ($label)
        <label class="label">
            <span class="flex gap-1 label-text">
                {{ $label }}
                @if ($required)
                    <span class="text-warning">*</span>
                @endif
                @if ($helper)
                    <x-helper :helper="$helper" />
                @endif
            </span>
        </label>
    @endif

    <div class="w-full">
        @if ($type === 'password')
            <div class="w-full rounded join" x-data>
                <input {{ $attributes }} class="join-item" wire:model.defer={{ $id }}
                    wire:dirty.class="input-warning" wire:loading.attr='disabled' @readonly($readonly)
                    @disabled($disabled) type={{ $type }} id={{ $id }}
                    name={{ $name }} @isset($value) value={{ $value }} @endisset
                    @isset($placeholder) placeholder={{ $placeholder }} @endisset>
                @if (!$cannotPeakPassword)
                    <span x-on:click="changePasswordFieldType" x-cloak @class([
                        'border-l-0 border-none rounded-r no-animation h-7 btn join-item btn-xs',
                        'bg-coolgray-200/50 hover:bg-coolgray-200/50 text-opacity-25' =>
                            $disabled || $readonly,
                        'bg-coolgray-200 hover:bg-coolgray-200' => !$disabled || !$readonly,
                    ])><svg
                            xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path
                                d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                        </svg>
                    </span>
                @endif
            </div>
        @else
            <input {{ $attributes }} id={{ $id }} name={{ $name }}
                wire:model.defer={{ $id }} wire:dirty.class="input-warning" wire:loading.attr='disabled'
                @readonly($readonly) @disabled($disabled)
                @isset($value) value="{{ $value }}" @endisset
                @isset($placeholder) placeholder="{{ $placeholder }}" @endisset>
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
</div> --}}
