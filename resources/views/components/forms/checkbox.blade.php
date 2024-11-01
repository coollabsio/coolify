@props([
    'id',
    'label' => null,
    'helper' => null,
    'disabled' => false,
    'instantSave' => false,
    'value' => null,
    'domValue' => null,
    'hideLabel' => false,
    'fullWidth' => false,
])

<div @class([
    'flex flex-row items-center gap-4 px-2 py-1 form-control min-w-fit dark:hover:bg-coolgray-100',
    'w-full' => $fullWidth,
])>
    @if (!$hideLabel)
        <label @class(['flex gap-4 px-0 min-w-fit label', 'opacity-40' => $disabled])>
            <span class="flex gap-2">
                @if ($label)
                    {!! $label !!}
                @else
                    {{ $id }}
                @endif
                @if ($helper)
                    <x-helper :helper="$helper" />
                @endif
            </span>
        </label>
    @endif
    <span class="flex-grow"></span>
    <input @disabled($disabled) type="checkbox" {{ $attributes->merge(['class' => $defaultClass]) }}
        @if ($instantSave) wire:loading.attr="disabled" wire:click='{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}'
       wire:model="{{ $id }}" @else wire:model="{{ $value ?? $id }}" @endif
       @if ($domValue) value="{{ $domValue }}" @endif
    />
</div>
