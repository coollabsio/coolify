@props([
    'id',
    'label',
    'events' => [],
    'settings' => null,
])

@php
    $disabled = $settings && ! auth()->user()->can('update', $settings);
    $selectedEvents = collect($events)->where('enabled', true);
    $selectedCount = $selectedEvents->count();
    $selectedLabels = $selectedEvents->pluck('label')->implode(', ');
@endphp

<div class="w-full" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <label for="{{ $id }}-trigger" class="mb-1.5 block text-[12px] font-medium text-black dark:text-fg">
        {{ $label }}
    </label>
    <div class="relative">
        <button id="{{ $id }}-trigger" type="button" class="listbox-trigger" @click="open = !open"
            @disabled($disabled) aria-haspopup="listbox" :aria-expanded="open">
            <span class="min-w-0 flex-1 truncate text-left">
                {{ $selectedCount === 0 ? 'No events selected' : $selectedLabels }}
            </span>
            <span
                class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-500 dark:bg-white/[0.07] dark:text-fg-dim">
                {{ $selectedCount }}/{{ count($events) }}
            </span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </button>

        <div class="listbox-panel" x-show="open" x-cloak role="listbox" aria-multiselectable="true">
            @foreach ($events as $event)
                <button wire:key="{{ $id }}-{{ $event['property'] }}" type="button" class="listbox-option"
                    role="option" aria-selected="{{ $event['enabled'] ? 'true' : 'false' }}"
                    wire:click="toggleEvent('{{ $event['property'] }}')" @disabled($disabled)>
                    <span class="truncate">{{ $event['label'] }}</span>
                    <span @class([
                        'flex size-4 shrink-0 items-center justify-center rounded-[5px] border',
                        'border-coollabs bg-coollabs text-white dark:border-warning dark:bg-warning dark:text-black' => $event['enabled'],
                        'border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]' => ! $event['enabled'],
                    ])>
                        @if ($event['enabled'])
                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    </div>
</div>
