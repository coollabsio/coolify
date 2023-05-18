@props([
    'type' => $attributes->get('type') ?? 'button',
    'disabled' => null,
    'confirm' => null,
    'confirmAction' => null,
])
@if ($type === 'submit')
    <button {{ $attributes }} type="submit" @if ($disabled !== null) disabled @endif wire:target="submit"
        wire:loading.delay.shorter.class="loading"
        @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
    @endisset
        @isset($confirmAction)
        x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
    @endisset>
        {{ $slot }}
    </button>
@elseif($type === 'button')
    <button {{ $attributes }} @if ($disabled !== null) disabled @endif type="button"
        wire:target="{{ explode('(', $attributes->whereStartsWith('wire:click')->first())[0] }}"
        wire:loading.delay.shorter.class="loading"
        @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
    @endisset
        @isset($confirmAction)
        x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
    @endisset>
        {{ $slot }}
    </button>
@endif
