<button @disabled($disabled) {{ $attributes->merge(['class' => $defaultClass]) }}
    {{ $attributes->merge(['type' => 'button']) }}
    @isset($confirm)
            x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
        @endisset
    @isset($confirmAction)
            x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
        @endisset>

    {{ $slot }}
    @if ($showLoadingIndicator)
        @if ($attributes->whereStartsWith('wire:click')->first())
            <x-loading-on-button wire:target="{{ $attributes->whereStartsWith('wire:click')->first() }}"
                wire:loading.delay />
        @elseif($attributes->whereStartsWith('wire:target')->first())
            <x-loading-on-button wire:target="{{ $attributes->whereStartsWith('wire:target')->first() }}"
                wire:loading.delay />
        @endif
    @endif
</button>
