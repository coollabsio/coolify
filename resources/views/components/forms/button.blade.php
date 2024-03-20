<button @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
    {{ $attributes->merge(['type' => 'button']) }}
    @isset($confirm)
            x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
        @endisset
    @isset($confirmAction)
            x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
        @endisset
    @if ($isModal) onclick="{{ $modalId }}.showModal()" @endif>

    {{ $slot }}
    @if ($attributes->get('type') === 'submit')
        <x-loading wire:target="submit" wire:loading.delay />
    @else
        @if ($attributes->whereStartsWith('wire:click')->first())
            <x-loading wire:target="{{ $attributes->whereStartsWith('wire:click')->first() }}" wire:loading.delay />
        @endif
    @endif
</button>
