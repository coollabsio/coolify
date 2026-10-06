@props(['id', 'isRunning' => false, 'containerPresent' => false])

{{-- Mirrors the v4 application heading actions. Confirmations open through the hidden triggers in the page heading. --}}
<x-split-action :id="$id" {{ $attributes }}>
    @if ($isRunning)
        <x-slot:main wire:click="deploy" wire:loading.attr="disabled">
            <x-reicon name="refresh" class="size-3.5" />
            Deploy
        </x-slot:main>
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            @click="open = false; document.getElementById('cluster-application-restart-trigger')?.click()"
            role="menuitem">
            <x-reicon name="restart" class="size-3.5 opacity-70" />
            Restart
        </button>
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            @click="open = false; document.getElementById('cluster-application-stop-trigger')?.click()"
            role="menuitem">
            <x-reicon name="stop-circle" class="size-3.5 text-error" />
            Stop
        </button>
    @else
        <x-slot:main wire:click="deploy" wire:loading.attr="disabled">
            <x-reicon name="play-circle" class="size-3.5" />
            Deploy
        </x-slot:main>
        @if ($containerPresent)
            <button type="button" class="listbox-option justify-start! gap-2.5!"
                @click="open = false; document.getElementById('cluster-application-stop-trigger')?.click()"
                role="menuitem">
                <x-reicon name="stop-circle" class="size-3.5 text-error" />
                Remove container
            </button>
        @endif
    @endif
</x-split-action>
