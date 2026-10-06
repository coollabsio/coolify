@props([
    'id',
    'isRunning' => false,
    'containerPresent' => false,
    // Livewire call of the main Deploy button, for example "deployRevision('…')" on the server page.
    'deployAction' => 'deploy',
    // Id prefix of the hidden confirmation triggers: {prefix}-restart-trigger and {prefix}-stop-trigger.
    'triggerPrefix' => 'cluster-application',
])

{{-- Mirrors the v4 application heading actions. Confirmations open through hidden triggers rendered by the caller.
     Extra menu items, like "Move to another server" on the server page, go in the default slot. --}}
<x-split-action :id="$id" {{ $attributes }}>
    @if ($isRunning)
        <x-slot:main wire:click="{{ $deployAction }}" wire:target="{{ $deployAction }}" wire:loading.attr="disabled">
            <x-reicon name="refresh" class="size-3.5" />
            Deploy
        </x-slot:main>
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            @click="open = false; document.getElementById('{{ $triggerPrefix }}-restart-trigger')?.click()"
            role="menuitem">
            <x-reicon name="restart" class="size-3.5 opacity-70" />
            Restart
        </button>
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            @click="open = false; document.getElementById('{{ $triggerPrefix }}-stop-trigger')?.click()"
            role="menuitem">
            <x-reicon name="stop-circle" class="size-3.5 text-error" />
            Stop
        </button>
    @else
        <x-slot:main wire:click="{{ $deployAction }}" wire:target="{{ $deployAction }}" wire:loading.attr="disabled">
            <x-reicon name="play-circle" class="size-3.5" />
            Deploy
        </x-slot:main>
        @if ($containerPresent)
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @click="open = false; document.getElementById('{{ $triggerPrefix }}-stop-trigger')?.click()"
                role="menuitem">
                <x-reicon name="stop-circle" class="size-3.5 text-error" />
                Remove container
            </button>
        @endif
    @endif
    {{ $slot }}
</x-split-action>
