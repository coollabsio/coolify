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
    'form-control flex max-w-full flex-row items-center gap-4 py-1 pr-2',
    'w-full' => $fullWidth,
    'dark:hover:bg-coolgray-100 cursor-pointer' => !$disabled,
])>
    <label @class(['label flex w-full max-w-full min-w-0 items-center gap-4 px-0'])>
        <span class="flex min-w-0 grow gap-2 break-words">
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
            <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass, 'shrink-0']) }}
                wire:loading.attr="disabled"
                wire:click='{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}'
                wire:model={{ $modelBinding }} id="{{ $htmlId }}" @if ($checked) checked @endif />
        @else
            @if ($domValue)
                <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass, 'shrink-0']) }}
                    value={{ $domValue }} id="{{ $htmlId }}" @if ($checked) checked @endif />
            @else
                <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass, 'shrink-0']) }}
                    wire:model={{ $value ?? $modelBinding }} id="{{ $htmlId }}" @if ($checked) checked @endif />
            @endif
        @endif
    </label>
</div>
