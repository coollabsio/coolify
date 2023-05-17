@props([
    'disabled' => null,
    'confirm' => null,
    'confirmAction' => null,
])
<button {{ $attributes }}
    @if ($attributes->whereStartsWith('wire:click') && !$disabled) wire:target="{{ explode('(', $attributes->whereStartsWith('wire:click')->first())[0] }}"
    wire:loading.delay.class='loading' wire:loading.delay.attr="disabled" @endif
    @if ($disabled !== null) disabled title="{{ $disabled }}" @endif
    @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
    @endisset
    @isset($confirmAction)
        x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
    @endisset>
    {{ $slot }}
</button>
