{{--
    Changes of one plan, one row per resource. A row with field changes expands. A removed resource can
    offer "Also delete its data" when $deleteDataModel is set (a Livewire array property).
--}}
@props(['changes' => [], 'deleteDataModel' => null, 'canDelete' => false, 'compact' => false])
@php
    $groups = \App\Services\ProjectConfig\ConfigPlanView::groups($changes ?? []);
    $tagClass = fn (array $group) => match (true) {
        $group['removes'] => 'text-red-600 dark:text-red-400',
        $group['kind'] === 'create' => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-neutral-500 dark:text-fg-dim',
    };
@endphp
@if ($groups === [])
    <p class="px-4 py-3 text-[13px] text-neutral-500 dark:text-fg-dim">No changes.</p>
@else
    <ul class="divide-y divide-neutral-200 dark:divide-white/[0.06]">
        @foreach ($groups as $group)
            <li wire:key="plan-group-{{ $group['key'] }}" x-data="{ open: false }">
                <div @class(['flex items-center gap-3', 'px-4 py-2.5' => ! $compact, 'px-3 py-2' => $compact])>
                    <button type="button" @click="open = !open" @disabled($group['fields'] === [])
                        class="flex min-w-0 flex-1 items-center gap-2 text-left disabled:cursor-default"
                        :aria-expanded="open">
                        <x-reicon name="chevron-down" @class([
                            'size-3.5 shrink-0 text-neutral-400 transition-transform duration-150 ease-out dark:text-fg-faint',
                            'invisible' => $group['fields'] === [],
                        ]) ::class="open ? '' : '-rotate-90'" />
                        <code class="truncate text-[13px] font-medium text-black dark:text-fg">{{ $group['key'] }}</code>
                        <span class="shrink-0 text-[11px] font-medium {{ $tagClass($group) }}">{{ $group['tag'] }}</span>
                        @if ($group['detail'])
                            <span class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $group['detail'] }}</span>
                        @endif
                        @if ($group['fields'] !== [])
                            <span class="shrink-0 text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ count($group['fields']) }} {{ count($group['fields']) === 1 ? 'setting' : 'settings' }}
                            </span>
                        @endif
                        @if ($group['destructive'] && ! $group['removes'])
                            <span class="shrink-0 text-[11px] font-medium text-red-600 dark:text-red-400">destructive</span>
                        @endif
                    </button>
                    @if ($deleteDataModel && $group['removes'])
                        <label class="flex shrink-0 items-center gap-2 text-[12px] text-red-700 dark:text-red-300">
                            <input type="checkbox" wire:model="{{ $deleteDataModel }}" value="{{ $group['key'] }}"
                                class="rounded border-red-300 text-red-600 focus:ring-red-500 dark:border-red-500/40 dark:bg-transparent"
                                @disabled(! $canDelete)>
                            Also delete its data
                        </label>
                    @endif
                </div>
                @if ($group['fields'] !== [])
                    <div x-show="open" x-cloak x-transition:enter="transition-opacity duration-150 ease-out"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        @class(['pb-2.5', 'pl-[42px] pr-4' => ! $compact, 'pl-[34px] pr-3' => $compact])>
                        <dl class="grid grid-cols-[minmax(8rem,auto)_1fr] gap-x-4 gap-y-1 text-[12px]">
                            @foreach ($group['fields'] as $field)
                                <dt @class(['font-mono text-neutral-500 dark:text-fg-dim', 'text-red-600 dark:text-red-400' => $field['destructive']])>{{ $field['path'] }}</dt>
                                <dd class="min-w-0 break-all font-mono text-neutral-800 dark:text-fg">{{ $field['value'] }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
@endif
