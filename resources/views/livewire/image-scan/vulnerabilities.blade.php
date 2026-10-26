@php
    $fixStates = [
        'fixable_now' => 'Fixable now',
        'not_released' => 'Fix not released yet',
        'no_fix' => 'No fix',
    ];
    $segment = 'h-7 rounded-md px-2.5 text-[12px] font-medium whitespace-nowrap transition-colors';
    $segmentActive = 'bg-white text-black shadow-sm ring-1 ring-neutral-200 dark:bg-white/[0.09] dark:text-fg dark:ring-white/[0.08]';
    $segmentIdle = 'text-neutral-500 hover:text-black dark:text-fg-faint dark:hover:text-fg';
    $checkbox = fn (bool $selected) => $selected
        ? 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white'
        : 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]';
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
@endphp

<div class="flex flex-col gap-6">
    @if ($offState === 'instance')
        <x-empty title="Image scanning is off for this Coolify instance."
            description="An instance admin can allow it in Settings." icon-name="shield-alert" />
    @elseif ($offState === 'server')
        <x-empty title="Image scanning is off for {{ $serverName }}."
            :description="$serverUrl === null ? 'Ask a team admin to turn it on.' : null" icon-name="shield-alert">
            @if ($serverUrl !== null)
                <x-slot:actions>
                    <a class="button normal-case!" href="{{ $serverUrl }}" {{ wireNavigate() }}>Turn on</a>
                </x-slot:actions>
            @endif
        </x-empty>
    @elseif ($offState === 'application')
        <x-empty title="Image scanning is off for this app."
            description="Turn on Scan this app in the rules below." icon-name="shield-alert" />
    @else
        @php
            $isScanning = $progress !== null;
            $hasCallouts = $errorMessages !== [] || $runsOtherImage || $eolDistro !== null || $truncated;
        @endphp

        <x-application.settings-section id="vulnerabilities-section" title="Vulnerabilities"
            helper="Known vulnerabilities in the packages of the images this resource runs. Fixable now means you can fix it by updating the image or a package."
            flush>
            @if ($oldResultsDays !== null)
                <x-slot:badges>
                    <span data-tooltip="Coolify rescans running images every day. The last rescans did not finish.">
                        <x-status-badge type="warning" :label="'Results from '.$oldResultsDays.' days ago'" />
                    </span>
                </x-slot:badges>
            @endif
            <x-slot:actions>
                @if ($scannedAt !== null)
                    <span class="{{ $muted }}" data-tooltip="{{ $scannedAt->toDayDateTimeString() }}">
                        Scanned {{ $scannedAt->diffForHumans() }}
                    </span>
                @endif
                @if ($canUpdate)
                    <x-forms.button class="normal-case!" wire:click="scanNow" :disabled="$isScanning">
                        <x-reicon name="refresh" class="size-3.5" />
                        Scan now
                    </x-forms.button>
                @endif
            </x-slot:actions>

            @if (array_sum([$fixableBySeverity['critical'], $fixableBySeverity['high'], $fixableBySeverity['medium']]) > 0)
                <div class="flex flex-col gap-2 border-b border-neutral-200 p-3 sm:flex-row sm:items-center sm:gap-4 dark:border-white/[0.08]">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[12px] font-medium text-neutral-700 dark:text-fg">Fixable now</span>
                        @foreach (['critical', 'high', 'medium'] as $severity)
                            @if ($fixableBySeverity[$severity] > 0)
                                <x-image-scan.severity-badge :severity="$severity" :count="$fixableBySeverity[$severity]" />
                            @endif
                        @endforeach
                    </div>
                    <x-image-scan.severity-bar class="sm:max-w-60" :counts="$fixableBySeverity" />
                </div>
            @endif

            @if ($hasCallouts)
                <div class="flex flex-col gap-2 border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    @foreach ($errorMessages as $message)
                        <x-callout type="danger" title="The last scan could not run">{{ $message }}</x-callout>
                    @endforeach
                    @if ($runsOtherImage)
                        <x-callout type="warning" title="Results are out of date">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>The running image is not the scanned image.</span>
                                @if ($canUpdate)
                                    <x-forms.button class="normal-case!" wire:click="scanNow" :disabled="$isScanning">Scan now</x-forms.button>
                                @endif
                            </div>
                        </x-callout>
                    @endif
                    @if ($eolDistro !== null)
                        <x-callout type="warning" title="Old operating system">
                            This image uses {{ $eolDistro['name'] }} {{ $eolDistro['version'] }}, which no longer gets security fixes.
                        </x-callout>
                    @endif
                    @if ($truncated)
                        <x-callout type="info" title="Too many results">Only the first 5,000 are shown.</x-callout>
                    @endif
                </div>
            @endif

            @if ($isScanning)
                <div class="flex flex-col divide-y divide-neutral-200 border-b border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]"
                    role="status" aria-live="polite">
                    @foreach ($checkpoints as $checkpoint)
                        <x-checkpoint-item :title="$checkpoint['title']" :status="$checkpoint['status']" />
                    @endforeach
                </div>
            @endif

            @if ($scans->isEmpty())
                @unless ($isScanning)
                    <x-empty size="sm" title="Not scanned yet. The next deploy scans the image.">
                        @if ($canUpdate)
                            <x-slot:actions>
                                <x-forms.button class="normal-case!" wire:click="scanNow">Scan now</x-forms.button>
                            </x-slot:actions>
                        @endif
                    </x-empty>
                @endunless
            @else
                {{-- Version recommendations (spec 7.3). The Dockerfile base image recommendation (7.4) goes here too. --}}
                <x-image-scan.fix-panel :items="$fixPanel" :can-update="$canUpdate" />

                <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    <x-slot:search>
                        <x-table.search placeholder="Search by ID or package" loading-target="search"
                            wire:model.live.debounce.300ms="search" />
                    </x-slot:search>

                    @unless ($showIgnored)
                        <div class="inline-flex max-w-full flex-wrap items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]"
                            role="group" aria-label="Fix state">
                            @foreach ($fixStates as $value => $label)
                                <button type="button" wire:click="setFixState('{{ $value }}')"
                                    aria-pressed="{{ $fixState === $value ? 'true' : 'false' }}"
                                    class="{{ $segment }} {{ $fixState === $value ? $segmentActive : $segmentIdle }}">
                                    {{ $label }} <span class="tabular-nums">({{ $stateCounts[$value] }})</span>
                                </button>
                            @endforeach
                        </div>
                    @endunless

                    <x-table.filter :active-count="count($severities) + count($images)" reset-action="resetFilters">
                        <span class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Severity</span>
                        @foreach ($severityOptions as $option)
                            @php $selected = in_array($option, $severities, true); @endphp
                            <button type="button" class="listbox-option" role="option"
                                aria-selected="{{ $selected ? 'true' : 'false' }}"
                                wire:click="toggleSeverity('{{ $option }}')">
                                <span>{{ ucfirst($option) }}</span>
                                <span class="{{ $checkbox($selected) }}">
                                    @if ($selected)
                                        <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                            <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                        @if ($imageOptions !== [])
                            <span class="px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint">Image</span>
                            @foreach ($imageOptions as $option)
                                @php $selected = in_array($option, $images, true); @endphp
                                <button type="button" class="listbox-option" role="option"
                                    aria-selected="{{ $selected ? 'true' : 'false' }}"
                                    wire:click="toggleImage({{ \Illuminate\Support\Js::from($option) }})">
                                    <span class="truncate" title="{{ $option }}">{{ $option }}</span>
                                    <span class="{{ $checkbox($selected) }}">
                                        @if ($selected)
                                            <svg class="size-3" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                                <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        @endif
                    </x-table.filter>

                    @unless ($showIgnored)
                        <div class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]"
                            role="group" aria-label="Group results">
                            @foreach (['package' => 'By package', 'cve' => 'By CVE'] as $value => $label)
                                <button type="button" wire:click="$set('view', '{{ $value }}')"
                                    aria-pressed="{{ $view === $value ? 'true' : 'false' }}"
                                    class="{{ $segment }} {{ $view === $value ? $segmentActive : $segmentIdle }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    @endunless
                </x-table.toolbar>

                @if ($showIgnored)
                    <div class="flex items-center justify-between gap-2 border-b border-neutral-200 px-4 py-2 dark:border-white/[0.08]">
                        <span class="text-[12px] font-medium text-neutral-700 dark:text-fg">Ignored</span>
                        <button type="button" class="text-[12px] font-medium text-coollabs hover:underline dark:text-brand-light"
                            wire:click="$set('showIgnored', false)">Back to the results</button>
                    </div>
                @endif

                <div class="relative">
                    <x-table.loading target="search,setFixState,toggleSeverity,toggleImage,resetFilters,view,showIgnored,previousPage,nextPage,gotoPage,perPage" />

                    @if ($items->isEmpty())
                        @if (! $showIgnored && ! $hasFilters && $fixState === 'fixable_now')
                            <x-empty size="sm" title="Nothing to fix now.">
                                <x-slot:actions>
                                    <button type="button" class="button normal-case!" wire:click="setFixState('not_released')">
                                        Fix not released yet ({{ $stateCounts['not_released'] }})
                                    </button>
                                    <button type="button" class="button normal-case!" wire:click="setFixState('no_fix')">
                                        No fix ({{ $stateCounts['no_fix'] }})
                                    </button>
                                </x-slot:actions>
                            </x-empty>
                        @elseif ($hasFilters)
                            <x-empty size="sm" title="No matching vulnerabilities"
                                description="Try another search, or reset the filters." />
                        @else
                            <x-empty size="sm" :title="$showIgnored ? 'No ignored vulnerabilities.' : 'No vulnerabilities in this group.'" />
                        @endif
                    @elseif (! $showIgnored && $view === 'package')
                        <div class="data-table">
                            <div @class(['data-table-header image-scan-packages-grid', 'has-image' => $showsImage])>
                                <span>Package</span>
                                @if ($showsImage)
                                    <span class="image-scan-secondary">Image</span>
                                @endif
                                <span class="image-scan-secondary">Version</span>
                                <span class="image-scan-severity-cell">Severity</span>
                                <span class="image-scan-secondary">Vulnerabilities</span>
                                <span class="text-right">Actions</span>
                            </div>
                            @foreach ($items as $group)
                                <div wire:key="package-{{ $group['key'] }}" x-data="{ open: false }"
                                    class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                    <div @class(['data-table-row image-scan-packages-grid', 'has-image' => $showsImage])>
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <button type="button" class="icon-button" x-on:click="open = !open"
                                                :aria-expanded="open ? 'true' : 'false'"
                                                aria-label="Show the vulnerabilities of {{ $group['package'] }}">
                                                <x-reicon name="chevron-down" class="size-3.5 transition-transform duration-150"
                                                    ::class="open ? 'rotate-0' : '-rotate-90'" />
                                            </button>
                                            <span class="truncate text-[12px] font-medium text-neutral-950 dark:text-fg"
                                                title="{{ $group['package'] }}">{{ $group['package'] }}</span>
                                        </div>
                                        @if ($showsImage)
                                            <span class="image-scan-secondary truncate {{ $muted }}" title="{{ $group['image'] }}">{{ $group['image'] }}</span>
                                        @endif
                                        <span class="image-scan-secondary min-w-0 truncate font-mono text-[11px] text-neutral-600 dark:text-fg-dim"
                                            title="{{ $group['version'] }}{{ $group['fixed'] ? ' → '.$group['fixed'] : '' }}">
                                            {{ $group['version'] }}@if ($group['fixed']) → {{ $group['fixed'] }}@endif
                                        </span>
                                        <span class="image-scan-severity-cell flex min-w-0 flex-wrap items-center gap-1">
                                            <x-image-scan.severity-badge :severity="$group['severity']" />
                                            @if ($group['kev'])
                                                <span data-tooltip="Attackers are using this vulnerability, according to CISA.">
                                                    <x-status-badge type="error" label="Known exploited" />
                                                </span>
                                            @endif
                                        </span>
                                        <span class="image-scan-secondary text-[12px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $group['count'] }}</span>
                                        <span class="flex justify-end">
                                            @if ($canUpdate)
                                                <button type="button" class="button normal-case!"
                                                    x-on:click="$dispatch('open-ignore-modal', {
                                                        target: {{ \Illuminate\Support\Js::from($group['package']) }},
                                                        kind: 'package',
                                                        owner: {{ \Illuminate\Support\Js::from($group['owner']) }},
                                                    })">Ignore package</button>
                                            @endif
                                        </span>
                                    </div>
                                    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                                        class="border-t border-neutral-200 bg-neutral-50/60 dark:border-white/[0.06] dark:bg-white/[0.015]">
                                        @foreach ($group['rows'] as $row)
                                            @include('livewire.image-scan.partials.finding-row', ['row' => $row, 'nested' => true])
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="data-table">
                            <div @class(['data-table-header image-scan-findings-grid', 'has-image' => $showsImage])>
                                <span>Vulnerability</span>
                                <span class="image-scan-secondary">Package</span>
                                @if ($showsImage)
                                    <span class="image-scan-secondary">Image</span>
                                @endif
                                <span class="image-scan-secondary">Version</span>
                                <span class="image-scan-severity-cell">Severity</span>
                                <span class="text-right">Actions</span>
                            </div>
                            @foreach ($items as $row)
                                @include('livewire.image-scan.partials.finding-row', ['row' => $row, 'nested' => false])
                            @endforeach
                        </div>
                    @endif
                </div>

                <x-table-pagination :from="$items->firstItem() ?? 0" :to="$items->lastItem() ?? 0" :total="$items->total()"
                    :current-page="$items->currentPage()" :last-page="$items->lastPage()"
                    wire-target="previousPage,nextPage,perPage" previous-action="previousPage" next-action="nextPage">
                    <x-slot:pageSize>
                        <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.image-scan-findings" />
                    </x-slot:pageSize>
                </x-table-pagination>

                @if ($ignoredCount > 0 && ! $showIgnored)
                    <div class="border-t border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
                        <button type="button" class="text-[12px] font-medium text-coollabs hover:underline dark:text-brand-light"
                            wire:click="$set('showIgnored', true)">{{ $ignoredCount }} ignored</button>
                    </div>
                @endif
            @endif
        </x-application.settings-section>

        @if ($canUpdate)
            @include('livewire.image-scan.partials.ignore-modal')
        @endif
    @endif
</div>
