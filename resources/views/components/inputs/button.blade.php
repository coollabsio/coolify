@props([
    'class' => null,
    'defaultClass' => 'text-white bg-violet-500 hover:bg-violet-600',
    'confirm' => null,
    'confirmAction' => null,
])

<button {{ $attributes }} {{ $attributes->merge(['class' => "$defaultClass $class"]) }}
    @if ($attributes->whereStartsWith('wire:click')) wire:target="{{ $attributes->whereStartsWith('wire:click')->first() }}"
    wire:loading.class="text-black bg-green-500" wire:loading.attr="disabled" wire:loading.class.remove="{{ $defaultClass }} {{ $attributes->whereStartsWith('class')->first() }}" @endif
    @isset($confirm) x-on:click="toggleConfirmModal('{{ $confirm }}')" @endisset
    @isset($confirmAction) @confirm.window="$wire.{{ $confirmAction }}()" @endisset>
    {{ $slot }}
</button>
