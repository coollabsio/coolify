@props([
    'isWarning' => null,
    'defaultClass' => 'text-white bg-neutral-800 hover:bg-violet-600 w-28',
    'defaultWarningClass' => 'text-white bg-red-500 hover:bg-red-600 w-28',
    'loadingClass' => 'text-black bg-green-500',
    'confirm' => null,
    'confirmAction' => null,
])
<button {{ $attributes }} @class([
    $defaultClass => !$confirm && !$isWarning,
    $defaultWarningClass => $confirm || $isWarning,
]) @if ($attributes->whereStartsWith('wire:click'))
    wire:target="{{ explode('(', $attributes->whereStartsWith('wire:click')->first())[0] }}"
    wire:loading.delay.class="{{ $loadingClass }}" wire:loading.delay.attr="disabled"
    wire:loading.delay.class.remove="{{ $defaultClass }} {{ $attributes->whereStartsWith('class')->first() }}"
    @endif
    @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}')"
    @endisset
    @isset($confirmAction)
        @confirm.window="$wire.{{ $confirmAction }}()"
    @endisset>
    {{ $slot }}
</button>
