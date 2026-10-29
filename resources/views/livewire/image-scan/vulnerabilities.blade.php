@php
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
            description="Turn on Scan this app in the settings below." icon-name="shield-alert" />
    @else
        @php
            $isScanning = $progress !== null;
            $checkNeeded = ($card['status'] ?? null) === 'check_needed';
        @endphp

        {{-- Main card (spec 15.7, 15.11): the status sentence and the one action first, then the why-list, the
             provenance, and the running image. One image renders open without a list frame; several images are a list,
             worst first, with the worst one open. --}}
        <x-application.settings-section id="security-section" title="Security">
            @if (filled($card['badge'] ?? null))
                <x-slot:badges>
                    <x-status-badge :type="$card['badgeType']" :label="$card['badge']" />
                </x-slot:badges>
            @endif
            <x-slot:actions>
                @if ($checkedAt !== null)
                    <span class="{{ $muted }}" data-tooltip="{{ $checkedAt->toDayDateTimeString() }}">
                        Checked {{ $checkedAt->diffForHumans() }}
                    </span>
                @endif
                @if ($canUpdate && ! $checkNeeded && $card !== null)
                    <x-forms.button class="normal-case!" wire:click="scanNow" :disabled="$isScanning">
                        <x-reicon name="refresh" class="size-3.5" />
                        Scan now
                    </x-forms.button>
                @endif
            </x-slot:actions>

            <div class="flex flex-col gap-4">
                @foreach ($errorMessages as $message)
                    <x-callout type="danger" title="The last scan could not run">{{ $message }}</x-callout>
                @endforeach

                @if ($isScanning)
                    <div class="flex flex-col divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]"
                        role="status" aria-live="polite">
                        @foreach ($checkpoints as $checkpoint)
                            <x-checkpoint-item :title="$checkpoint['title']" :status="$checkpoint['status']" />
                        @endforeach
                    </div>
                @endif

                @if ($card === null)
                    @unless ($isScanning)
                        <x-empty size="sm" :title="$isApplication ? 'Not scanned yet. The next deploy scans the image.' : 'Not scanned yet.'">
                            @if ($canUpdate)
                                <x-slot:actions>
                                    <x-forms.button :isHighlighted="! $isApplication" class="normal-case!" wire:click="scanNow">
                                        <x-reicon name="refresh" class="size-3.5" />
                                        Scan now
                                    </x-forms.button>
                                </x-slot:actions>
                            @endif
                        </x-empty>
                    @endunless
                @elseif ($card['single'] && count($card['rows']) === 1)
                    {{-- One image: the open row without the list frame and without a second status (spec 15.11). --}}
                    <div wire:key="security-image-{{ $card['rows'][0]['key'] }}">
                        @include('livewire.image-scan.partials.security-image', ['row' => $card['rows'][0], 'single' => true])
                    </div>
                @else
                    @if ($card['header'] !== null || ($checkNeeded && $canUpdate))
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            @if ($card['header'] !== null)
                                <p class="text-[15px] font-medium leading-6 text-neutral-950 dark:text-fg">{{ $card['header'] }}</p>
                            @endif
                            @if ($checkNeeded && $canUpdate)
                                <x-forms.button isHighlighted class="normal-case!" wire:click="scanNow" :disabled="$isScanning">
                                    <x-reicon name="refresh" class="size-3.5" />
                                    Scan now
                                </x-forms.button>
                            @endif
                        </div>
                    @endif

                    <ul class="flex flex-col divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]"
                        aria-label="Images">
                        @foreach ($card['rows'] as $row)
                            @php
                                $bodyId = 'security-image-'.$row['key'].'-body';
                            @endphp
                            <li wire:key="security-image-{{ $row['key'] }}" x-data="{ open: @js($row['open']) }">
                                <div class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-3">
                                    <button type="button" class="group flex min-w-0 flex-1 items-center gap-2 rounded-md text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coollabs dark:focus-visible:outline-brand"
                                        x-on:click="open = ! open" aria-expanded="{{ $row['open'] ? 'true' : 'false' }}"
                                        x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $bodyId }}">
                                        <x-reicon name="chevron-down"
                                            @class([
                                                'size-3.5 shrink-0 text-neutral-500 transition-transform duration-[180ms] ease-[cubic-bezier(0.23,1,0.32,1)] motion-reduce:transition-none dark:text-fg-faint',
                                                '-rotate-90' => ! $row['open'],
                                            ])
                                            ::class="{ '-rotate-90': ! open }" />
                                        <span class="flex min-w-0 flex-col">
                                            @if ($row['name'] !== null)
                                                <span class="truncate text-[13px] font-medium text-neutral-950 dark:text-fg">{{ $row['name'] }}</span>
                                                <code class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint" title="{{ $row['ref'] }}">{{ $row['ref'] }}</code>
                                            @else
                                                <code class="truncate font-mono text-[12px] text-neutral-900 dark:text-fg" title="{{ $row['ref'] }}">{{ $row['ref'] }}</code>
                                            @endif
                                        </span>
                                    </button>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2 pl-5.5 sm:pl-0">
                                        @if ($row['badge'] !== null)
                                            <x-status-badge :type="$row['badgeType']" :label="$row['badge']" />
                                        @else
                                            <span class="{{ $muted }}">{{ $row['status'] === 'ok' ? 'No action needed' : 'Not checked' }}</span>
                                        @endif
                                        @if ($row['mergedWith'] !== null)
                                            <span class="{{ $muted }}">Updated with {{ $row['mergedWith'] }}</span>
                                        @elseif ($row['primary'] !== null)
                                            @include('livewire.image-scan.partials.security-action', ['action' => $row['primary'], 'primary' => $loop->first, 'withSentence' => false, 'keyPrefix' => $row['key']])
                                        @endif
                                    </div>
                                </div>
                                {{-- A check only the user can unblock (registry login, daily limit) shows also on a closed row. --}}
                                @foreach ($row['versionNotes'] as $versionNote)
                                    <p class="-mt-1 truncate px-3 pb-2 text-[11px] text-neutral-500 sm:pl-8.5 dark:text-fg-faint" title="{{ $versionNote['text'] }}">
                                        {{ $versionNote['text'] }}
                                        @if ($versionNote['link'] !== null)
                                            <a href="{{ $versionNote['link'] }}" class="underline underline-offset-4 hover:text-neutral-950 dark:hover:text-fg" {{ wireNavigate() }}>Registry logins</a>
                                        @endif
                                    </p>
                                @endforeach
                                {{-- Height and opacity animate together (grid rows 0fr → 1fr); nothing animates on load. --}}
                                <div id="{{ $bodyId }}"
                                    @class([
                                        'grid transition-[grid-template-rows,opacity] duration-[180ms] ease-[cubic-bezier(0.23,1,0.32,1)] motion-reduce:transition-none',
                                        'grid-rows-[1fr] opacity-100' => $row['open'],
                                        'grid-rows-[0fr] opacity-0' => ! $row['open'],
                                    ])
                                    x-bind:class="{ 'grid-rows-[1fr] opacity-100': open, 'grid-rows-[0fr] opacity-0': ! open }"
                                    @unless ($row['open']) inert @endunless x-bind:inert="! open">
                                    <div class="min-h-0 overflow-hidden">
                                        <div class="px-3 pb-3 pt-1 sm:pl-8.5">
                                            @include('livewire.image-scan.partials.security-image', ['row' => $row, 'single' => false])
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-application.settings-section>

        @if ($scans->isNotEmpty())
            <x-application.settings-section id="all-findings-section" :title="'All findings ('.number_format($findingCount).')'"
                collapsible flush>
                @if ($truncated)
                    <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                        <x-callout type="info" title="Too many results">Only part of the results is shown, with the problems that attackers are using first.</x-callout>
                    </div>
                @endif

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
                                    {{ $label }} <span class="tabular-nums">({{ number_format($stateCounts[$value]) }})</span>
                                </button>
                            @endforeach
                        </div>
                    @endunless

                    <x-image-scan.image-select :options="$imageOptions" :selected="$images" action="selectImage" />

                    <x-table.filter :active-count="count($severities)" reset-action="resetFilters">
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
                    <x-table.loading target="search,setFixState,toggleSeverity,toggleImage,selectImage,showImageFindings,resetFilters,view,showIgnored,previousPage,nextPage,gotoPage,perPage" />

                    @if ($items->isEmpty())
                        @if (! $showIgnored && ! $hasFilters && $fixState === 'fixed')
                            <x-empty size="sm" title="No finding has a fixed version.">
                                @if ($stateCounts['no_fix'] > 0)
                                    <x-slot:actions>
                                        <button type="button" class="button normal-case!" wire:click="setFixState('no_fix')">
                                            No fix yet ({{ number_format($stateCounts['no_fix']) }})
                                        </button>
                                    </x-slot:actions>
                                @endif
                            </x-empty>
                        @elseif ($hasFilters)
                            <x-empty size="sm" title="No matching findings"
                                description="Try another search, or reset the filters." />
                        @else
                            <x-empty size="sm" :title="$showIgnored ? 'No ignored findings.' : 'No findings in this group.'" />
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
                                <span class="image-scan-secondary">Findings</span>
                                <span class="sr-only">Actions</span>
                            </div>
                            @foreach ($items as $group)
                                <div wire:key="package-{{ $group['key'] }}" x-data="{ open: false }"
                                    class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                    <div @class(['data-table-row image-scan-packages-grid', 'has-image' => $showsImage])>
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            <button type="button" class="icon-button" x-on:click="open = !open"
                                                :aria-expanded="open ? 'true' : 'false'"
                                                aria-label="Show the findings of {{ $group['package'] }}">
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
                                                <span data-tooltip="Attackers are using this, according to CISA.">
                                                    <x-status-badge type="error" label="Known exploited" />
                                                </span>
                                            @endif
                                        </span>
                                        <span class="image-scan-secondary text-[12px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ number_format($group['count']) }}</span>
                                        <span class="flex justify-end">
                                            @if ($canUpdate)
                                                <button type="button" class="icon-button" data-tooltip="Ignore package"
                                                    aria-label="Ignore {{ $group['package'] }}"
                                                    x-on:click="$dispatch('open-ignore-modal', {
                                                        target: {{ \Illuminate\Support\Js::from($group['package']) }},
                                                        kind: 'package',
                                                        owner: {{ \Illuminate\Support\Js::from($group['owner']) }},
                                                        neverAllowed: {{ \Illuminate\Support\Js::from($group['never_allowed']) }},
                                                    })">
                                                    <x-reicon name="eye-off2" class="size-3.5" />
                                                </button>
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
                                <span>Finding</span>
                                <span class="image-scan-secondary">Package</span>
                                @if ($showsImage)
                                    <span class="image-scan-secondary">Image</span>
                                @endif
                                <span class="image-scan-secondary">Version</span>
                                <span class="image-scan-severity-cell">Severity</span>
                                <span class="sr-only">Actions</span>
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
                            wire:click="$set('showIgnored', true)">{{ number_format($ignoredCount) }} ignored</button>
                    </div>
                @endif
            </x-application.settings-section>
        @endif

        @if ($canUpdate)
            @include('livewire.image-scan.partials.ignore-modal')
        @endif
    @endif
</div>
