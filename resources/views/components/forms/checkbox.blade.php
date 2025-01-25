@props([
    'id',
    'label' => null,
    'helper' => null,
    'disabled' => false,
    'instantSave' => false,
    'value' => null,
    'domValue' => null,
    'checked' => false,
    'fullWidth' => false,
])

<div @class([
    'flex flex-row items-center gap-4 pr-2 py-1 form-control min-w-fit',
    'w-full' => $fullWidth,
    'dark:hover:bg-coolgray-100 cursor-pointer' => !$disabled,
])>
    <label @class(['flex gap-4 items-center px-0 min-w-fit label w-full'])>
        <span class="flex flex-grow gap-2">
            @if ($label)
                @if ($disabled)
                    <span class="opacity-60">{!! $label !!}</span>
                @else
                    {!! $label !!}
                @endif
                @if ($helper)
                    <x-helper :helper="$helper" />
                @endif
            @endif
        </span>
        @if ($instantSave)
            <input type="checkbox" @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
                wire:loading.attr="disabled"
                wire:click='{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}'
                wire:model={{ $id }} @if ($checked) checked @endif />
        @else
            @if ($domValue)
                <input type="checkbox" @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
                    value={{ $domValue }} @if ($checked) checked @endif />
            @else
                <input type="checkbox" @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
                    wire:model={{ $value ?? $id }} @if ($checked) checked @endif />
            @endif
        @endif
    </label>
</div>
