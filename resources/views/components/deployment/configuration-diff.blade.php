@props([
    'diff' => null,
    'compact' => false,
])

@php
    $changes = collect(data_get($diff, 'changes', []))->filter(fn ($change) => data_get($change, 'key') !== 'domains.custom_labels')->values()->all();
    $count = count($changes);
    $requiresBuild = collect($changes)->contains(fn ($change) => data_get($change, 'impact') === 'build');
@endphp

@if ($count > 0)
    <div @class([
        'text-xs' => $compact,
        'text-sm' => ! $compact,
    ])>
        <div class="mb-2 flex flex-wrap items-center gap-2 font-semibold text-black dark:text-white">
            <span>{{ $count }} configuration {{ $count === 1 ? 'change' : 'changes' }}</span>
            <span @class([
                'rounded-sm px-1.5 py-0.5 text-[0.65rem] font-semibold uppercase leading-none',
                'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300' => $requiresBuild,
                'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => ! $requiresBuild,
            ])>
                {{ $requiresBuild ? 'Rebuild required' : 'Redeploy required' }}
            </span>
        </div>

        @unless ($compact)
            <div class="space-y-4">
                @foreach (collect($changes)->groupBy('section_label') as $sectionLabel => $sectionChanges)
                    <div>
                        <div class="mb-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-400">
                            {{ $sectionLabel }}
                        </div>
                        <div class="rounded-sm border border-neutral-300 dark:border-coolgray-200">
                            <div class="grid grid-cols-[12rem_1fr_1.5rem_1fr] items-center gap-2 bg-neutral-100 px-3 py-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-neutral-500 dark:bg-coolgray-200 dark:text-neutral-400">
                                <div>Field</div>
                                <div>From</div>
                                <div></div>
                                <div>To</div>
                            </div>
                            <div class="divide-y divide-neutral-300 dark:divide-coolgray-200">
                                @foreach ($sectionChanges as $change)
                                    <div class="grid grid-cols-[12rem_1fr_1.5rem_1fr] items-start gap-2 px-3 py-1.5 text-neutral-700 dark:text-neutral-300">
                                        <div class="shrink-0 font-medium text-black dark:text-white">
                                            {{ data_get($change, 'label') }}
                                        </div>
                                        <div class="truncate text-red-700 dark:text-red-400/80" title="{{ data_get($change, 'old_display_value') }}">
                                            {{ data_get($change, 'old_display_value') }}
                                        </div>
                                        <div class="text-center text-neutral-500 dark:text-neutral-400">→</div>
                                        <div class="truncate text-green-700 dark:text-green-500" title="{{ data_get($change, 'new_display_value') }}">
                                            {{ data_get($change, 'new_display_value') }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endunless
    </div>
@endif
