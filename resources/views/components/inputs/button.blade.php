@props([
    'isWarning' => null,
    'disabled' => null,
    'defaultClass' => 'text-white bg-neutral-800 hover:bg-violet-600 h-8',
    'defaultWarningClass' => 'text-white bg-red-500 hover:bg-red-600 h-8',
    'disabledClass' => 'text-neutral-400 bg-neutral-900 h-8',
    'loadingClass' => 'text-black bg-green-500 h-8',
    'confirm' => null,
    'confirmAction' => null,
])
<button {{ $attributes }} @class([
    $defaultClass => !$confirm && !$isWarning && !$disabled,
    $defaultWarningClass => ($confirm || $isWarning) && !$disabled,
    $disabledClass => $disabled,
]) @if ($attributes->whereStartsWith('wire:click') && !$disabled)
    wire:target="{{ explode('(', $attributes->whereStartsWith('wire:click')->first())[0] }}"
    wire:loading.delay.class="{{ $loadingClass }}" wire:loading.delay.attr="disabled"
    wire:loading.delay.class.remove="{{ $defaultClass }} {{ $attributes->whereStartsWith('class')->first() }}"
    @endif
    @if ($disabled !== null)
        disabled title="{{ $disabled }}"
    @endif
    @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
    @endisset
    @isset($confirmAction)
        x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
    @endisset>
    {{ $slot }}
</button>
