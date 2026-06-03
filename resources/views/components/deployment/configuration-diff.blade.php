@props([
    'diff' => null,
    'compact' => false,
])

@php
    $changes = collect(data_get($diff, 'changes', []))->values()->all();
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
                                    @php
                                        $changeKey = (string) data_get($change, 'key');
                                        $expandable = data_get($change, 'expandable', false);
                                        $oldDisplay = (string) data_get($change, 'old_display_value');
                                        $newDisplay = (string) data_get($change, 'new_display_value');
                                        $oldFull = data_get($change, 'old_full_value') ?? $oldDisplay;
                                        $newFull = data_get($change, 'new_full_value') ?? $newDisplay;
                                        $label = (string) data_get($change, 'label');
                                        $labelTruncated = mb_strlen($label) > 20;
                                        $rowExpandable = $expandable || $labelTruncated;
                                    @endphp
                                    <div class="grid grid-cols-[12rem_1fr_1.5rem_1fr] items-start gap-2 px-3 py-1.5 text-neutral-700 dark:text-neutral-300">
                                        <div class="min-w-0 shrink-0 font-medium text-black dark:text-white">
                                            @if ($rowExpandable)
                                                <div class="break-words"
                                                    :class="expandedRows['{{ $changeKey }}'] ? '' : 'truncate'"
                                                    x-text="expandedRows['{{ $changeKey }}'] ? @js($label) : @js((string) str($label)->limit(20))"></div>
                                            @else
                                                {{ $label }}
                                            @endif
                                        </div>
                                        <div class="min-w-0 text-red-700 dark:text-red-400/80">
                                            @if ($expandable)
                                                <div class="break-words"
                                                    :class="expandedRows['{{ $changeKey }}'] ? 'whitespace-pre-wrap' : 'truncate'"
                                                    x-text="expandedRows['{{ $changeKey }}'] ? @js($oldFull) : @js($oldDisplay)"></div>
                                            @else
                                                <div class="truncate">{{ $oldDisplay }}</div>
                                            @endif
                                        </div>
                                        <div class="text-center text-neutral-500 dark:text-neutral-400">→</div>
                                        <div class="flex min-w-0 items-start gap-1 text-green-700 dark:text-green-500">
                                            <div class="min-w-0 flex-1">
                                                @if ($expandable)
                                                    <div class="break-words"
                                                        :class="expandedRows['{{ $changeKey }}'] ? 'whitespace-pre-wrap' : 'truncate'"
                                                        x-text="expandedRows['{{ $changeKey }}'] ? @js($newFull) : @js($newDisplay)"></div>
                                                @else
                                                    <div class="truncate">{{ $newDisplay }}</div>
                                                @endif
                                            </div>
                                            @if ($rowExpandable)
                                                <button type="button"
                                                    x-on:click="expandedRows['{{ $changeKey }}'] = ! expandedRows['{{ $changeKey }}']"
                                                    :aria-expanded="!! expandedRows['{{ $changeKey }}']"
                                                    title="Toggle full value"
                                                    class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center text-neutral-400 transition hover:text-black dark:hover:text-white">
                                                    <svg x-show="! expandedRows['{{ $changeKey }}']" class="h-3.5 w-3.5"
                                                        viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M13.28 7.78l3.22-3.22v2.69a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.69l-3.22 3.22a.75.75 0 0 0 1.06 1.06ZM2 17.25v-4.5a.75.75 0 0 1 1.5 0v2.69l3.22-3.22a.75.75 0 0 1 1.06 1.06L4.56 16.5h2.69a.75.75 0 0 1 0 1.5h-4.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                                                    </svg>
                                                    <svg x-show="expandedRows['{{ $changeKey }}']" x-cloak class="h-3.5 w-3.5"
                                                        viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M3.28 2.22a.75.75 0 0 0-1.06 1.06L5.44 6.5H2.75a.75.75 0 0 0 0 1.5h4.5A.75.75 0 0 0 8 7.25v-4.5a.75.75 0 0 0-1.5 0v2.69L3.28 2.22ZM13.5 2.75a.75.75 0 0 0-1.5 0v4.5c0 .414.336.75.75.75h4.5a.75.75 0 0 0 0-1.5h-2.69l3.22-3.22a.75.75 0 0 0-1.06-1.06L13.5 5.44V2.75ZM3.28 17.78l3.22-3.22v2.69a.75.75 0 0 0 1.5 0v-4.5a.75.75 0 0 0-.75-.75h-4.5a.75.75 0 0 0 0 1.5h2.69l-3.22 3.22a.75.75 0 1 0 1.06 1.06ZM12 12.75c0-.414.336-.75.75-.75h4.5a.75.75 0 0 1 0 1.5h-2.69l3.22 3.22a.75.75 0 1 1-1.06 1.06l-3.22-3.22v2.69a.75.75 0 0 1-1.5 0v-4.5Z" clip-rule="evenodd" />
                                                    </svg>
                                                </button>
                                            @endif
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
