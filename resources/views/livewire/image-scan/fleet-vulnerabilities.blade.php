@php
    $checkbox = fn (bool $selected) => $selected
        ? 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-coollabs bg-coollabs text-white dark:border-brand dark:bg-brand dark:text-white'
        : 'flex size-4 shrink-0 items-center justify-center rounded-[5px] border border-neutral-300 bg-white dark:border-white/[0.14] dark:bg-white/[0.045]';
    $groupLabel = 'px-2 pb-1 pt-2 text-[10px] font-medium uppercase tracking-wider text-neutral-400 dark:text-fg-faint';
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
    $secondary = 'truncate text-[11px] text-neutral-600 dark:text-fg-dim';
@endphp

<div class="flex w-full min-w-0 flex-col gap-6">
    <x-slot:title>
        Security | Coolify
    </x-slot>

    <div class="min-w-0">
        <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Security</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            What each resource of your team needs, from its last image scan.
        </p>
    </div>

    @if (! $allowed)
        <x-empty title="Image scanning is off for this Coolify instance."
            description="An instance admin can allow it in Settings." icon-name="shield-alert" />
    @else
        @if ($serversNotScanning > 0)
            @php
                $serversKey = 'coolify.image-scan.servers-not-scanning-dismissed.'.currentTeam()->id.'.'.$serversNotScanning;
            @endphp
            <div x-data="{ hidden: localStorage.getItem(@js($serversKey)) === '1' }" x-show="!hidden" x-cloak
                wire:key="servers-not-scanning-{{ $serversNotScanning }}">
                <x-callout type="info" dismissible
                    :title="$serversNotScanning === 1 ? '1 server does not scan images.' : $serversNotScanning.' servers do not scan images.'"
                    on-dismiss="localStorage.setItem({{ \Illuminate\Support\Js::from($serversKey) }}, '1'); hidden = true">
                    <a href="{{ route('server.index') }}" {{ wireNavigate() }}
                        class="font-medium text-coollabs hover:underline dark:text-brand-light">Go to servers</a>
                </x-callout>
            </div>
        @endif

        <div class="min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]">
            <x-table.toolbar class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                <x-slot:search>
                    <x-table.search placeholder="Search by CVE, GHSA, or package" loading-target="search"
                        wire:model.live.debounce.300ms="search" />
                </x-slot:search>

                <x-table.filter :active-count="count($statuses) + count($servers) + count($projects)" reset-action="resetFilters">
                    <span class="{{ $groupLabel }}">Status</span>
                    @foreach ($statusOptions as $option => $optionLabel)
                        @php
                            $selected = in_array($option, $statuses, true);
                        @endphp
                        <button type="button" class="listbox-option" role="option" wire:key="status-option-{{ $option }}"
                            aria-selected="{{ $selected ? 'true' : 'false' }}"
                            wire:click="toggleStatus('{{ $option }}')">
                            <span>{{ $optionLabel }}</span>
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
                    @if ($serverOptions !== [])
                        <span class="{{ $groupLabel }}">Server</span>
                        @foreach ($serverOptions as $uuid => $name)
                            @php
                                $selected = in_array($uuid, $servers, true);
                            @endphp
                            <button type="button" class="listbox-option" role="option" wire:key="server-option-{{ $uuid }}"
                                aria-selected="{{ $selected ? 'true' : 'false' }}"
                                wire:click="toggleServer({{ \Illuminate\Support\Js::from($uuid) }})">
                                <span class="truncate" title="{{ $name }}">{{ $name }}</span>
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
                    @if ($projectOptions !== [])
                        <span class="{{ $groupLabel }}">Project</span>
                        @foreach ($projectOptions as $uuid => $name)
                            @php
                                $selected = in_array($uuid, $projects, true);
                            @endphp
                            <button type="button" class="listbox-option" role="option" wire:key="project-option-{{ $uuid }}"
                                aria-selected="{{ $selected ? 'true' : 'false' }}"
                                wire:click="toggleProject({{ \Illuminate\Support\Js::from($uuid) }})">
                                <span class="truncate" title="{{ $name }}">{{ $name }}</span>
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
            </x-table.toolbar>

            <div class="relative">
                <x-table.loading target="search,toggleStatus,toggleServer,toggleProject,resetFilters,previousPage,nextPage,gotoPage,perPage" />

                @if ($items->isEmpty())
                    @if ($searching)
                        <x-empty size="sm" title="No scanned image has this vulnerability or package."
                            description="Check the ID, or search for the package name." />
                    @elseif ($hasFilters)
                        <x-empty size="sm" title="No matching resources" description="Try other filters, or reset them." />
                    @elseif (! $hasResources)
                        <x-empty size="sm" title="No resources yet" description="The resources of your team show here." />
                    @endif
                @else
                    <div class="data-table">
                        <div class="data-table-header image-scan-fleet-grid">
                            <span>Resource</span>
                            <span>{{ $searching ? 'Match' : 'Status' }}</span>
                            <span class="image-scan-fleet-action">{{ $searching ? 'State' : 'Action' }}</span>
                            <span class="image-scan-secondary">Server</span>
                            <span class="image-scan-secondary image-scan-fleet-tertiary">Checked</span>
                        </div>
                        @foreach ($items as $entry)
                            @php
                                $resource = $entry['resource'];
                                $security = $entry['security'];
                                $status = $security->status;
                                $checkedAt = $security->checkedAt ?? $entry['scans']->max('scanned_at');
                                $action = $searching ? null : \App\Services\ImageScan\TeamVulnerabilities::action($security);
                                $statusLabel = \App\Services\ImageScan\TeamVulnerabilities::statusLabel($status);
                                $badgeType = match ($status) {
                                    \App\Enums\SecurityStatusType::UpdateNow => 'error',
                                    \App\Enums\SecurityStatusType::UpdateAvailable => 'warning',
                                    \App\Enums\SecurityStatusType::NoFix, \App\Enums\SecurityStatusType::CheckNeeded => 'neutral',
                                    default => null,
                                };
                            @endphp
                            <div wire:key="fleet-{{ $entry['key'] }}"
                                class="data-table-row image-scan-fleet-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="flex size-5 shrink-0 items-center justify-center overflow-hidden rounded text-neutral-500 dark:text-fg-dim">
                                        <x-resource-logo :resource="$resource" icon-class="size-3.5" />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-1.5">
                                            @if ($entry['link'])
                                                <a href="{{ $entry['link'] }}" {{ wireNavigate() }}
                                                    class="block min-w-0 truncate text-[12px] font-medium text-neutral-950 hover:underline dark:text-fg"
                                                    title="{{ $resource->name }}">{{ $resource->name }}</a>
                                            @else
                                                <span class="block min-w-0 truncate text-[12px] font-medium text-neutral-950 dark:text-fg">{{ $resource->name }}</span>
                                            @endif
                                            @if ($searching && $badgeType !== null)
                                                <x-status-badge :type="$badgeType" :label="$statusLabel" class="h-5! px-1.5! text-[10px]!" />
                                            @endif
                                        </div>
                                        <span class="block truncate {{ $muted }}"
                                            title="{{ $entry['project']?->name }} / {{ $entry['environment']?->name }}">{{ $entry['project']?->name }} / {{ $entry['environment']?->name }}</span>
                                    </div>
                                </div>

                                @if ($searching)
                                    @php
                                        $match = $entry['match'];
                                    @endphp
                                    <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <x-image-scan.severity-badge :severity="$match['severity']" />
                                        <span class="min-w-0 truncate font-mono text-[11px] text-neutral-700 dark:text-fg"
                                            title="{{ $match['id'] }} {{ $match['package'] }} {{ $match['version'] }}">{{ $match['id'] }} · {{ $match['package'] }} {{ $match['version'] }}</span>
                                        @if ($match['more'] > 0)
                                            <span class="{{ $muted }}">+{{ $match['more'] }} more</span>
                                        @endif
                                    </div>
                                    <span class="image-scan-fleet-action text-[12px] text-neutral-700 dark:text-fg">
                                        {{ $stateLabels[$match['state']] }}@if ($match['state'] === 'fixed' && filled($match['fixedIn']))<span class="{{ $muted }}"> → {{ $match['fixedIn'] }}</span>@endif
                                    </span>
                                @else
                                    <div class="flex min-w-0 items-center">
                                        @if ($badgeType !== null)
                                            <x-status-badge :type="$badgeType" :label="$statusLabel" />
                                        @else
                                            <span class="{{ $muted }}">{{ $statusLabel }}</span>
                                        @endif
                                    </div>
                                    <div class="image-scan-fleet-action min-w-0">
                                        @if ($action !== null)
                                            @php
                                                $actionClass = $action['muted'] ? 'text-[12px] text-neutral-500 dark:text-fg-faint' : 'text-[12px] text-neutral-700 dark:text-fg';
                                            @endphp
                                            @if ($action['link'] && $entry['link'])
                                                <a href="{{ $entry['link'] }}#security-section" {{ wireNavigate() }}
                                                    class="line-clamp-2 hover:underline {{ $actionClass }}"
                                                    title="{{ $action['text'] }}">{{ $action['text'] }}</a>
                                            @else
                                                <span class="line-clamp-2 {{ $actionClass }}">{{ $action['text'] }}</span>
                                            @endif
                                        @endif
                                    </div>
                                @endif

                                <span class="image-scan-secondary {{ $secondary }}" title="{{ $entry['server']?->name }}">{{ $entry['server']?->name }}</span>
                                <span class="image-scan-secondary image-scan-fleet-tertiary {{ $muted }}"
                                    @if ($checkedAt) title="{{ $checkedAt->toDayDateTimeString() }}" @endif>{{ $checkedAt?->diffForHumans() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <x-table-pagination :from="$items->firstItem() ?? 0" :to="$items->lastItem() ?? 0" :total="$items->total()"
                :current-page="$items->currentPage()" :last-page="$items->lastPage()"
                wire-target="previousPage,nextPage,perPage" previous-action="previousPage" next-action="nextPage">
                <x-slot:pageSize>
                    <x-page-size-select model="perPage" livewire storage-key="coolify.page-size.image-scan-fleet" :selected="$perPage" />
                </x-slot:pageSize>
            </x-table-pagination>

            @if ($searchCapped)
                <div class="border-t border-neutral-200 px-4 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    Only the first {{ \App\Services\ImageScan\TeamVulnerabilities::MAX_DECODED_SCANS }} images with a match were searched. Filter by server or project to see the rest.
                </div>
            @endif
            @if ($notScannedLine)
                <div class="border-t border-neutral-200 px-4 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                    {{ $notScannedLine }}
                </div>
            @endif
        </div>
    @endif
</div>
