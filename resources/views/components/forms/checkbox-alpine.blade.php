@props([
    'label' => null,
    'disabled' => false,
    'defaultClass' => 'dark:border-neutral-700 text-coolgray-400 dark:bg-coolgray-100 rounded-sm cursor-pointer dark:disabled:bg-base dark:disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base',
])

<div @class([
    'flex flex-row items-center gap-4 pr-2 py-1 form-control min-w-fit',
    'dark:hover:bg-coolgray-100 cursor-pointer' => !$disabled,
])>
    <label @class(['flex gap-4 items-center px-0 min-w-fit label w-full'])>
        <span class="flex grow gap-2">
            @if ($label)
                @if ($disabled)
                    <span class="opacity-60">{!! $label !!}</span>
                @else
                    {!! $label !!}
                @endif
            @endif
        </span>
        <input type="checkbox" @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }} />
    </label>
</div>
