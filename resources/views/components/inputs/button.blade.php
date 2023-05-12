@props([
    'isWarning' => null,
    'disabled' => null,
    'defaultClass' => 'text-white hover:bg-coollabs h-8 rounded transition-colors',
    'defaultWarningClass' => 'text-white bg-red-500 hover:bg-red-600 h-8 rounded',
    'disabledClass' => 'text-coolgray-200 h-8 rounded',
    'loadingClass' => 'text-black bg-green-500 h-8 rounded',
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
