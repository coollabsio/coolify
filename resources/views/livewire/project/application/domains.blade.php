@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $hasDnsChecksInProgress = collect($domainRows)->contains(fn ($row) => $row['dns_status'] === 'checking');
    $composeDomainGroups = collect($domainRows)
        ->groupBy(fn ($row) => $row['service'] ?? '__unknown')
        ->filter(fn ($rows) => $rows->contains(fn ($row) => ! ($row['is_suggested'] ?? false)));
    $hasHttpsDomains = collect($domainRows)->contains(
        fn ($row) => ! ($row['is_suggested'] ?? false) && str_starts_with(strtolower($row['url']), 'https://')
    );
@endphp

<div id="application-domains-section" class="domains-overview-container flex flex-col gap-4"
    x-data="{
        domainSearch: '',
        modalOpen: @js($showEditDomainModal || $editDomainDnsFailed),
        editingServiceLabel: @js($editingService ?? ''),
        openEditDomain() {
            this.editingServiceLabel = $wire.editingService || '';
            this.modalOpen = true;
            this.$nextTick(() => document.getElementById('editingDomainParts-host')?.focus?.());
        },
        closeEditDomain() {
            this.modalOpen = false;
            this.editingServiceLabel = '';
        },
        matchesDomainSearch(value) {
            return !this.domainSearch.trim() || value.toLowerCase().includes(this.domainSearch.trim().toLowerCase());
        },
        hasDomainSearchResults(values) {
            return values.some((value) => this.matchesDomainSearch(value));
        },
    }"
    @open-edit-domain.window="openEditDomain()"
    @edit-domain-saved.window="closeEditDomain()">
    @if ($hasDnsChecksInProgress)
        <div class="hidden" wire:poll.2000ms="pollDnsChecks" aria-hidden="true"></div>
    @endif
    @if ($labelsAreWritable)
        <x-callout type="warning" title="Domains managed via labels" class="mb-4">
            Container label readonly mode is disabled. Domains must be set in the Labels section on the General page.
        </x-callout>
    @endif

    @if ($isCompose && count($composeServices) === 0)
        <x-callout type="info" title="No services">
            No non-database services found in the Docker Compose file. Domains can only be assigned to application
            services.
        </x-callout>
    @endif

    @cannot('update', $application)
        <x-callout type="danger" title="Insufficient permissions">
            You don't have permission to manage domains. Contact your team administrator for access.
        </x-callout>
    @endcannot

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1">
            <h2 id="domains-section">Domains</h2>
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
                @if ($suggestedCount > 0)
                    · {{ $suggestedCount }} not added
                @endif
            </p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($hasRows)
                <div class="relative w-full sm:w-64">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="domainSearch" aria-label="Search services or domains"
                        class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search services or domains" />
                </div>
            @endif
            @can('update', $application)
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled" wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Check all DNS
                </x-forms.button>
                <div class="relative shrink-0">
                    @include('livewire.project.shared.cloudflare-autoconfigure')
                </div>
                @unless ($labelsAreWritable)
                    @if (! $isCompose || count($composeServices) > 0)
                        <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                            canGate="update" :canResource="$application">
                            <x-slot:content>
                                <button type="button"
                                    class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add domain
                                </button>
                            </x-slot:content>
                            <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                                @if ($isCompose && count($composeServices) > 0)
                                    <x-forms.listbox canGate="update" :canResource="$application" label="Service" id="newDomainService" required
                                        :options="collect($composeServices)->map(fn ($serviceName) => [
                                            'value' => $serviceName,
                                            'label' => $serviceName,
                                        ])->values()->all()"
                                        :disabled="! auth()->user()->can('update', $application)" />
                                @endif

                                <x-forms.domain-input id="newDomainParts" errorId="newDomain" />

                                @if ($addDomainDnsFailed)
                                    <x-callout type="danger" title="DNS is not pointing to the right IP">
                                        This domain does not currently resolve to this server.
                                        Traffic may not reach Coolify until you update DNS.
                                        Are you sure you want to add it anyway?
                                        @if (filled($addDomainDnsMessage))
                                            <div class="pt-2">{{ $addDomainDnsMessage }}</div>
                                        @endif
                                    </x-callout>
                                @endif

                                <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                                    <x-forms.button type="button" wire:click="generateDomain">
                                        Generate domain
                                    </x-forms.button>
                                    <div class="flex flex-wrap gap-2">
                                        @if ($addDomainDnsFailed)
                                            <x-forms.button type="button" wire:click="confirmAddDomainDespiteDns" isError>
                                                Continue
                                            </x-forms.button>
                                        @else
                                            <x-forms.button type="submit" isHighlighted>
                                                Save
                                            </x-forms.button>
                                        @endif
                                    </div>
                                </div>
                            </form>
                        </x-modal-input>
                    @endif
                @endunless
            @endcan
        </div>
    </div>

    @if ($hasHttpsDomains && ! $labelsAreWritable)
        <div class="flex flex-wrap items-center justify-end gap-2 service-domains-https">
            <label for="isForceHttpsEnabled-trigger" class="mb-0! text-[12px]!">Redirect HTTP to HTTPS</label>
            <x-helper helper="Disable only when Cloudflare Tunnel or another proxy connects to Coolify over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL." />
            <div class="w-28 shrink-0">
                <x-forms.listbox canGate="update" :canResource="$application" id="isForceHttpsEnabled"
                    onChange="updateForceHttps" portal
                    :options="[
                        ['value' => true, 'label' => 'Enabled'],
                        ['value' => false, 'label' => 'Disabled'],
                    ]" :disabled="! auth()->user()->can('update', $application)" />
            </div>
        </div>
    @endif

    {{-- Table / empty --}}
    <div id="domains-table-section"
        class="application-settings-section-body mt-1 scroll-mt-28 {{ $hasRows ? 'is-flush' : '' }} w-full">
        @if ($hasRows)
            <div class="data-table-header service-domains-overview-grid">
                <span>Domain</span>
                <span>Protocol redirect</span>
                <span>Domain redirect</span>
                <span>Internal port</span>
                <span>Search indexing</span>
                <span>DNS status</span>
                <span class="text-right">Actions</span>
            </div>
        @endif
        @if ($isCompose && count($composeServices) === 0 && ! $hasRows)
            <x-empty size="sm" title="No services available"
                description="No non-database services found in the Docker Compose file."
                icon-name="globe" />
        @elseif ($isCompose && $composeDomainGroups->isEmpty())
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the Add domain button above. Choose which service receives it."
                icon-name="globe" />
        @elseif (! $hasRows)
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the Add domain button above, or generate one with the server wildcard domain."
                icon-name="globe" />
        @elseif ($isCompose)
            @php
                $grouped = $composeDomainGroups;
                $serviceOrder = collect($composeServices)
                    ->filter(fn ($serviceName) => $grouped->has($serviceName))
                    ->values()
                    ->all();
                foreach ($grouped->keys() as $name) {
                    if ($name !== '__unknown' && ! in_array($name, $serviceOrder, true)) {
                        $serviceOrder[] = $name;
                    }
                }
                $domainSearchValues = collect($serviceOrder)
                    ->map(fn ($serviceName) => $serviceName.' '.$grouped->get($serviceName, collect())->pluck('url')->implode(' '))
                    ->values();
            @endphp
            <div>
                @foreach ($serviceOrder as $serviceName)
                    @php
                        $rows = $grouped->get($serviceName, collect());
                        $redirectWireKey = $this->serviceRedirectWireKey($serviceName);
                    @endphp
                    <section id="application-compose-domain-group-{{ $redirectWireKey }}"
                        wire:key="application-compose-domain-group-{{ $redirectWireKey }}"
                        x-show="matchesDomainSearch(@js($serviceName.' '.$rows->pluck('url')->implode(' ')))"
                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/10">
                        <div class="flex w-full items-center justify-between gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.04]">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-white">
                                {{ $serviceName }}
                            </span>
                        </div>

                        <div wire:key="application-compose-domain-rows-{{ $redirectWireKey }}"
                            class="data-table w-full">
                            @foreach ($rows as $row)
                                @php
                                    $index = collect($domainRows)->search(
                                        fn ($item) => $item['url'] === $row['url']
                                            && ($item['service'] ?? null) === ($row['service'] ?? null)
                                            && (bool) ($item['is_suggested'] ?? false) === (bool) ($row['is_suggested'] ?? false),
                                    );
                                @endphp
                                @include('livewire.project.application.partials.domain-row', [
                                    'index' => $index,
                                    'row' => $row,
                                    'application' => $application,
                                    'labelsAreWritable' => $labelsAreWritable,
                                    'isCompose' => true,
                                ])
                            @endforeach
                        </div>
                    </section>
                @endforeach
                <div x-cloak
                    x-show="domainSearch.trim() && !hasDomainSearchResults(@js($domainSearchValues))"
                    class="px-4 py-8">
                    <x-empty size="sm" title="No domains found"
                        description="No service or domain matches your search." icon-name="search" />
                </div>
            </div>
        @else
            <div class="data-table w-full">
                @foreach ($domainRows as $index => $row)
                    @include('livewire.project.application.partials.domain-row', [
                        'index' => $index,
                        'row' => $row,
                        'application' => $application,
                        'labelsAreWritable' => $labelsAreWritable,
                        'isCompose' => false,
                    ])
                @endforeach
            </div>
            <div x-cloak x-show="domainSearch.trim() && !hasDomainSearchResults(@js(collect($domainRows)->pluck('url')->values()))"
                class="px-4 py-8">
                <x-empty size="sm" title="No domains found"
                    description="No domain matches your search." icon-name="search" />
            </div>
        @endif
    </div>

    {{-- One dialog for address edits and automatically saved domain settings. --}}
    <div class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }"
        @keydown.window.escape="if (modalOpen) { closeEditDomain() }">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                    @click="closeEditDomain()"></div>
                <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden lg:w-auto lg:min-w-2xl lg:max-w-4xl"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">Domain settings</h3>
                            <button type="button" @click="closeEditDomain()"
                                class="icon-button shrink-0" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body relative flex min-h-0 flex-1 flex-col overflow-hidden">
                            <form wire:submit="updateDomain" class="flex min-h-0 flex-1 flex-col">
                                <div data-testid="domain-settings-scroll"
                                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain pb-4"
                                    style="-webkit-overflow-scrolling: touch;">
                                <div x-show="editingServiceLabel" x-cloak class="w-full">
                                    <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
                                        <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">Service</label>
                                    </div>
                                    <input type="text" class="input" readonly x-bind:value="editingServiceLabel" />
                                </div>

                                <x-forms.domain-input id="editingDomainParts" errorId="editingDomain" />

                                @if ($editDomainDnsFailed)
                                    <x-callout type="danger" title="DNS is not pointing to the right IP">
                                        This domain does not currently resolve to this server.
                                        Traffic may not reach Coolify until you update DNS.
                                        Are you sure you want to save it anyway?
                                        @if (filled($editDomainDnsMessage))
                                            <div class="pt-2">{{ $editDomainDnsMessage }}</div>
                                        @endif
                                    </x-callout>
                                @endif

                                @php
                                    $editingRow = $editingIndex !== null ? ($domainRows[$editingIndex] ?? null) : null;
                                @endphp
                                @if ($editingRow && ! $labelsAreWritable)
                                    @can('update', $application)
                                        @php
                                            $editingKey = hash('sha256', $editingRow['url'].'|'.($editingRow['service'] ?? ''));
                                            $editingRedirectKey = $isCompose ? $this->serviceRedirectWireKey($editingRow['service']) : null;
                                            $editingRedirectProperty = $isCompose ? 'serviceRedirects.'.$editingRedirectKey : 'redirect';
                                        @endphp
                                        <div wire:key="editing-application-domain-settings-{{ $editingKey }}"
                                            class="grid grid-cols-1 gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2 dark:border-white/10">
                                        <x-forms.listbox id="application-domain-indexing-{{ $editingKey }}"
                                            label="Search engine indexing" :wire="false" preserveValue
                                            :value="$application->isDomainNoindexed($editingRow['url']) ? 'noindex' : 'index'"
                                            onChange="toggleNoindexDomain" :onChangeArgs="[$editingRow['url']]" portal
                                            :options="[
                                                ['value' => 'index', 'label' => 'Indexable'],
                                                ['value' => 'noindex', 'label' => 'Noindex'],
                                            ]" />
                                        <x-forms.listbox id="application-domain-direction-{{ $editingKey }}"
                                            label="www redirect" :wire="false" preserveValue
                                            :value="$isCompose ? ($serviceRedirects[$editingRedirectKey] ?? 'both') : $redirect"
                                            :x-effect="'value = $wire.get('.json_encode($editingRedirectProperty).')'"
                                            :helper="$isCompose ? 'Applies to all domains for this Compose service.' : 'Applies to all domains for this application.'"
                                            :onChange="$isCompose ? 'updateServiceRedirect' : 'updateRedirect'"
                                            :onChangeArgs="$isCompose ? [$editingRow['service']] : []" portal
                                            :options="[
                                                ['value' => 'both', 'label' => 'No redirect'],
                                                ['value' => 'www', 'label' => 'Redirect to www'],
                                                ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                                            ]" />
                                        </div>
                                    @endcan
                                @endif
                                </div>

                                <div data-testid="domain-settings-footer"
                                    class="shrink-0 border-t border-neutral-200 pt-4 dark:border-white/10">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($editDomainDnsFailed)
                                        <x-forms.button type="button" isError wire:click="confirmUpdateDomainDespiteDns">
                                            Continue
                                        </x-forms.button>
                                    @else
                                        <x-forms.button type="submit" wire:target="updateDomain" isHighlighted>
                                            Save
                                        </x-forms.button>
                                    @endif
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage" />

    @if ($showPortWarningModal)
        <div x-data="{ modalOpen: true }"
            @keydown.escape.window="modalOpen = false; $wire.call('cancelUseUnknownPort')"
            class="relative z-40">
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header>
                            <h3>Use a different port?</h3>
                            <button type="button"
                                @click="modalOpen = false; $wire.call('cancelUseUnknownPort')"
                                class="icon-button" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body">
                            <x-callout type="warning" title="Unrecognized internal port" class="mb-4">
                                Port <strong>{{ $unrecognizedPort }}</strong> is not listed in Ports Exposes
                                and is not used by any application domain. The proxy will still route to it,
                                but the container may not be listening there.
                            </x-callout>

                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button type="button" canGate="update" :canResource="$application"
                                    @click="modalOpen = false; $wire.call('cancelUseUnknownPort')">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="confirmUseUnknownPort" canGate="update"
                                    :canResource="$application"
                                    @click="modalOpen = false" isError>
                                    Use this port anyway
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
    @include('livewire.project.shared.dns-provider-management')
</div>
