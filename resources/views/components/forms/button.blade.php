<button {{ $attributes->merge(['class' => $defaultClass]) }} {{ $attributes->merge(['type' => 'button']) }}
    @if ($attributes->whereStartsWith('x-on:click')->first()) isError @endif
    @isset($confirm)
        x-on:click="toggleConfirmModal('{{ $confirm }}', '{{ explode('(', $confirmAction)[0] }}')"
    @endisset
    @isset($confirmAction)
        x-on:{{ explode('(', $confirmAction)[0] }}.window="$wire.{{ explode('(', $confirmAction)[0] }}"
    @endisset>
    @if ($attributes->get('type') === 'submit')
        <span wire:target="submit" wire:loading.delay class="loading loading-xs text-warning loading-spinner"></span>
    @else
        <span wire:target="{{ explode('(', $attributes->whereStartsWith('wire:click')->first())[0] }}" wire:loading.delay
            class="loading loading-xs loading-spinner"></span>
    @endif
    {{ $slot }}
</button>
