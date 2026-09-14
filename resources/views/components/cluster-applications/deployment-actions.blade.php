@props(['status', 'id'])

@php
    $isRunning = $status === 'Running';
@endphp

<x-split-action :id="$id" {{ $attributes }}>
    <x-slot:main wire:click="deploy" wire:loading.attr="disabled">
        <x-reicon :name="$isRunning ? 'refresh' : 'play-circle'" class="size-3.5" />
        {{ $isRunning ? 'Redeploy' : 'Deploy' }}
    </x-slot:main>
    @if ($isRunning)
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            wire:click="manage('restart')" wire:loading.attr="disabled" @click="open = false" role="menuitem">
            <x-reicon name="restart" class="size-3.5 opacity-70" />
            Restart
        </button>
        <button type="button" class="listbox-option justify-start! gap-2.5!"
            wire:click="manage('stop')" wire:loading.attr="disabled" @click="open = false" role="menuitem">
            <x-reicon name="stop-circle" class="size-3.5 text-error" />
            Stop
        </button>
    @endif
</x-split-action>
