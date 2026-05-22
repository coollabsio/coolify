@props([
    'diff' => null,
    'compact' => false,
])

@php
    $changes = data_get($diff, 'changes', []);
    $count = data_get($diff, 'count', count($changes));
    $requiresBuild = data_get($diff, 'requires_build', false);
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
                {{ $requiresBuild ? 'Rebuild' : 'Redeploy' }}
            </span>
        </div>

        @unless ($compact)
            <div class="space-y-2">
                @foreach (collect($changes)->groupBy('section_label') as $sectionLabel => $sectionChanges)
                    <div>
                        <div class="mb-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-neutral-600 dark:text-neutral-400">
                            {{ $sectionLabel }}
                        </div>
                        <div class="overflow-x-auto rounded-sm border border-neutral-300 dark:border-coolgray-200">
                            <div class="min-w-[44rem]">
                                <div class="grid grid-cols-[minmax(12rem,1.4fr)_7rem_minmax(8rem,1fr)_1.5rem_minmax(8rem,1fr)] items-center gap-2 bg-neutral-100 px-3 py-1.5 text-[0.65rem] font-semibold uppercase tracking-wide text-neutral-500 dark:bg-coolgray-200 dark:text-neutral-400">
                                    <div>Field</div>
                                    <div>Type</div>
                                    <div>From</div>
                                    <div></div>
                                    <div>To</div>
                                </div>
                                <div class="divide-y divide-neutral-300 dark:divide-coolgray-200">
                                    @foreach ($sectionChanges as $change)
                                        <div class="grid grid-cols-[minmax(12rem,1.4fr)_7rem_minmax(8rem,1fr)_1.5rem_minmax(8rem,1fr)] items-center gap-2 px-3 py-1.5 text-neutral-700 dark:text-neutral-300">
                                            <div class="truncate font-medium text-black dark:text-white" title="{{ data_get($change, 'label') }}">
                                                {{ data_get($change, 'label') }}
                                            </div>
                                            <div class="text-neutral-500 dark:text-neutral-400">
                                                {{ data_get($change, 'type') }}
                                            </div>
                                            <div class="truncate" title="{{ data_get($change, 'old_display_value') }}">
                                                {{ data_get($change, 'old_display_value') }}
                                            </div>
                                            <div class="text-center text-neutral-500 dark:text-neutral-400">→</div>
                                            <div class="truncate" title="{{ data_get($change, 'new_display_value') }}">
                                                {{ data_get($change, 'new_display_value') }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endunless
    </div>
@endif
