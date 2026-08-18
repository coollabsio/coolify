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
    'form-control group flex min-h-9 max-w-full items-center rounded-lg px-2.5 py-1.5 transition-colors',
    'w-full' => $fullWidth,
    'cursor-pointer hover:bg-neutral-100/80 dark:hover:bg-white/[0.035]' => !$disabled,
    'opacity-55' => $disabled,
])>
    <label @class([
        'label flex w-full max-w-full min-w-0 items-center gap-3 px-0',
        'cursor-pointer' => !$disabled,
        'cursor-not-allowed' => $disabled,
    ])>
        <span
            class="flex min-w-0 grow items-center gap-1.5 break-words text-[12px] text-neutral-600 dark:text-fg-dim">
            @if ($label)
                {!! $label !!}
                @if ($helper)
                    <x-helper :helper="$helper" />
                @endif
            @endif
        </span>

        <span class="relative flex size-[18px] shrink-0">
            @if ($instantSave)
                <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass]) }}
                    wire:loading.attr="disabled"
                    wire:click='{{ $instantSave === 'instantSave' || $instantSave == '1' ? 'instantSave' : $instantSave }}'
                    wire:model={{ $modelBinding }} id="{{ $htmlId }}" @if ($checked) checked @endif />
            @else
                @if ($domValue)
                    <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass]) }}
                        value={{ $domValue }} id="{{ $htmlId }}" @if ($checked) checked @endif />
                @else
                    <input type="checkbox" @disabled($disabled) {{ $attributes->class([$defaultClass]) }}
                        @if ($live) wire:model.live={{ $value ?? $modelBinding }} @else wire:model={{ $value ?? $modelBinding }} @endif
                        id="{{ $htmlId }}" @if ($checked) checked @endif />
                @endif
            @endif

            <span
                class="pointer-events-none absolute inset-0 rounded-[5px] border border-neutral-300 bg-white shadow-[inset_0_1px_1px_rgb(0_0_0/0.04)] transition-colors group-hover:border-neutral-400 peer-checked:border-coollabs peer-checked:bg-coollabs peer-focus-visible:ring-2 peer-focus-visible:ring-coollabs/25 peer-focus-visible:ring-offset-2 peer-disabled:opacity-50 dark:border-white/[0.14] dark:bg-white/[0.045] dark:shadow-none dark:group-hover:border-white/[0.22] dark:peer-checked:border-warning dark:peer-checked:bg-warning dark:peer-focus-visible:ring-warning/30 dark:peer-focus-visible:ring-offset-base"></span>
            <svg class="pointer-events-none absolute inset-0 m-auto size-3 scale-75 text-white opacity-0 transition-[opacity,transform] peer-checked:scale-100 peer-checked:opacity-100 dark:text-black"
                viewBox="0 0 12 12" fill="none" aria-hidden="true">
                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </label>
</div>
