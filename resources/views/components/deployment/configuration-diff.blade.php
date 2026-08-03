@props([
    'diff' => null,
    'compact' => false,
])

@php
    $changes = collect(data_get($diff, 'changes', []))->values();
@endphp

@if ($changes->isNotEmpty())
    <div class="space-y-5">
        @foreach ($changes->groupBy('section_label') as $sectionLabel => $sectionChanges)
            <div>
                <h4
                    class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-neutral-500 dark:text-fg-faint">
                    {{ $sectionLabel }}
                </h4>

                <div class="overflow-x-auto rounded-lg ring-1 ring-neutral-200 dark:ring-white/[0.08]">
                    <div class="min-w-[640px]">
                        <div
                            class="grid grid-cols-[minmax(12rem,1.5fr)_minmax(8rem,1fr)_1.5rem_minmax(8rem,1fr)_1.75rem] items-center gap-3 bg-neutral-100 px-3 py-2 text-[11px] font-medium text-neutral-500 dark:bg-white/[0.04] dark:text-fg-faint">
                            <div>Field</div>
                            <div>Current</div>
                            <div></div>
                            <div>New</div>
                            <div></div>
                        </div>

                        <div class="divide-y divide-neutral-200 bg-white dark:divide-white/[0.07] dark:bg-transparent">
                            @foreach ($sectionChanges as $change)
                                @php
                                    $changeKey = (string) data_get($change, 'key');
                                    $expandable = data_get($change, 'expandable', false);
                                    $oldDisplay = (string) data_get($change, 'old_display_value');
                                    $newDisplay = (string) data_get($change, 'new_display_value');
                                    $oldFull = data_get($change, 'old_full_value') ?? $oldDisplay;
                                    $newFull = data_get($change, 'new_full_value') ?? $newDisplay;
                                    $label = (string) data_get($change, 'label');
                                    $labelTruncated = mb_strlen($label) > 24;
                                    $rowExpandable = $expandable || $labelTruncated;
                                @endphp

                                <div
                                    class="grid grid-cols-[minmax(12rem,1.5fr)_minmax(8rem,1fr)_1.5rem_minmax(8rem,1fr)_1.75rem] items-start gap-3 px-3 py-2.5 text-sm transition-colors hover:bg-neutral-50 dark:hover:bg-white/[0.025]">
                                    <div class="min-w-0 font-medium text-neutral-900 dark:text-fg">
                                        @if ($rowExpandable)
                                            <div class="break-words"
                                                :class="expandedRows['{{ $changeKey }}'] ? '' : 'truncate'"
                                                x-text="expandedRows['{{ $changeKey }}'] ? @js($label) : @js((string) str($label)->limit(24))">
                                            </div>
                                        @else
                                            {{ $label }}
                                        @endif
                                    </div>

                                    <div class="min-w-0 text-red-600 dark:text-red-400">
                                        @if ($expandable)
                                            <div class="break-words"
                                                :class="expandedRows['{{ $changeKey }}'] ? 'whitespace-pre-wrap' : 'truncate'"
                                                x-text="expandedRows['{{ $changeKey }}'] ? @js($oldFull) : @js($oldDisplay)">
                                            </div>
                                        @else
                                            <div class="truncate">{{ $oldDisplay }}</div>
                                        @endif
                                    </div>

                                    <div class="flex justify-center pt-0.5 text-neutral-400 dark:text-fg-faint">
                                        <x-reicon name="arrow-right" class="size-3.5" />
                                    </div>

                                    <div class="min-w-0 text-emerald-600 dark:text-emerald-400">
                                        @if ($expandable)
                                            <div class="break-words"
                                                :class="expandedRows['{{ $changeKey }}'] ? 'whitespace-pre-wrap' : 'truncate'"
                                                x-text="expandedRows['{{ $changeKey }}'] ? @js($newFull) : @js($newDisplay)">
                                            </div>
                                        @else
                                            <div class="truncate">{{ $newDisplay }}</div>
                                        @endif
                                    </div>

                                    <div class="flex justify-end">
                                        @if ($rowExpandable)
                                            <button type="button"
                                                x-on:click="expandedRows['{{ $changeKey }}'] = ! expandedRows['{{ $changeKey }}']"
                                                :aria-expanded="!! expandedRows['{{ $changeKey }}']"
                                                title="Toggle full value"
                                                class="flex size-6 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-700 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                                <x-reicon name="eye"
                                                    x-show="! expandedRows['{{ $changeKey }}']"
                                                    class="size-3.5" />
                                                <x-reicon name="eye-off"
                                                    x-show="expandedRows['{{ $changeKey }}']"
                                                    x-cloak class="size-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
