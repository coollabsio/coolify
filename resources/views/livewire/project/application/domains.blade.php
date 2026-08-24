@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $hasDnsChecksInProgress = collect($domainRows)->contains(fn ($row) => $row['dns_status'] === 'checking');
    $composeDomainGroups = collect($domainRows)
        ->groupBy(fn ($row) => $row['service'] ?? '__unknown')
        ->filter(fn ($rows) => $rows->contains(fn ($row) => ! ($row['is_suggested'] ?? false)));
    $helperText = $isCompose
        ? 'Manage domains for every service in this Docker Compose application.'
        : 'Manage domains for this application.';
    $hasHttpsDomains = collect($domainRows)->contains(
        fn ($row) => ! ($row['is_suggested'] ?? false) && str_starts_with(strtolower($row['url']), 'https://')
    );
@endphp

<div class="flex flex-col gap-4"
    x-data="{
        domainSearch: '',
        modalOpen: @js($showEditDomainModal || $editDomainDnsFailed),
        editingServiceLabel: @js($editingService ?? ''),
        openEditDomain() {
            this.editingServiceLabel = $wire.editingService || '';
            this.modalOpen = true;
            this.$nextTick(() => document.getElementById('editingDomainLocal')?.focus?.());
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
    <x-application.settings-section id="domains-section" title="Domains">
        @can('update', $application)
            <x-slot:actions>
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled" wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Recheck DNS
                </x-forms.button>
            </x-slot:actions>
        @endcan

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

        <p class="text-sm text-neutral-500 dark:text-fg-dim">
            {{ $helperText }}
        </p>

        @if ($hasHttpsDomains && ! $labelsAreWritable)
            <div class="mt-4 max-w-md">
                <x-forms.listbox canGate="update" :canResource="$application" id="isForceHttpsEnabled" label="Redirect HTTP to HTTPS"
                    onChange="updateForceHttps"
                    helper="Disable only when Cloudflare Tunnel or another proxy connects to Coolify over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL."
                    :options="[
                        ['value' => true, 'label' => 'Enabled'],
                        ['value' => false, 'label' => 'Disabled'],
                    ]" :disabled="! auth()->user()->can('update', $application)" />
            </div>
        @endif

    </x-application.settings-section>

    {{-- Toolbar --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1">
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
                @if ($suggestedCount > 0)
                    · {{ $suggestedCount }} not added
                @endif
            </p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($isCompose && $composeDomainGroups->isNotEmpty())
                <div class="relative w-full sm:w-64">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="domainSearch" aria-label="Search services or domains"
                        class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search services or domains" />
                </div>
            @endif
            @can('update', $application)
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
                                    Add
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

    {{-- Table / empty --}}
    <div id="domains-table-section"
        class="application-settings-section-body mt-1 scroll-mt-28 {{ $hasRows ? 'is-flush' : '' }} w-full">
        @if ($isCompose && count($composeServices) === 0 && ! $hasRows)
            <x-empty size="sm" title="No services available"
                description="No non-database services found in the Docker Compose file."
                icon-name="globe" />
        @elseif ($isCompose && $composeDomainGroups->isEmpty())
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the + Add button above. Choose which service receives it."
                icon-name="globe" />
        @elseif (! $hasRows)
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the + Add button above, or generate one with the server wildcard domain."
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
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="hidden text-xs text-neutral-500 sm:inline dark:text-fg-dim">Direction</span>
                                @if (auth()->user()?->can('update', $application) && ! $labelsAreWritable)
                                    <x-forms.listbox id="domain-direction-service-{{ $redirectWireKey }}" :wire="false"
                                        :value="$serviceRedirects[$redirectWireKey] ?? 'both'" preserveValue
                                        onChange="updateServiceRedirect" :onChangeArgs="[$serviceName]" portal :options="[
                                            ['value' => 'both', 'label' => 'Allow www & non-www'],
                                            ['value' => 'www', 'label' => 'Redirect to www'],
                                            ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                                        ]" />
                                @else
                                    <span class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                        {{ match ($serviceRedirects[$redirectWireKey] ?? 'both') {
                                            'www' => 'Redirect to www',
                                            'non-www' => 'Redirect to non-www',
                                            default => 'Allow both',
                                        } }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div wire:key="application-compose-domain-rows-{{ $redirectWireKey }}-{{ md5(serialize($rows->all())) }}"
                            class="data-table w-full">
                            <div class="data-table-header domains-table-grid-service">
                                <span>Domain</span>
                                <span>DNS Check</span>
                                <span class="whitespace-nowrap">Search engine indexing</span>
                                <span></span>
                            </div>
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
                                    'isCompose' => false,
                                    'showDirectionControl' => false,
                                    'domainGridClass' => 'domains-table-grid-service',
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
                <div class="data-table-header domains-table-grid">
                    <span>Domain</span>
                    <span>DNS Check</span>
                    <span class="whitespace-nowrap">Search engine indexing</span>
                    <span>Direction</span>
                    <span></span>
                </div>
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
        @endif
    </div>

    {{-- Edit domain modal: open/close is Alpine-only; server runs only on Save / Continue. --}}
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
                            <h3 class="min-w-0 flex-1 truncate">Edit domain</h3>
                            <button type="button" @click="closeEditDomain()"
                                class="icon-button shrink-0" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body relative min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <form wire:submit="updateDomain" class="flex flex-col gap-4">
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

                                <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                    @if ($editDomainDnsFailed)
                                        <x-forms.button type="button" isError
                                            wire:click="confirmUpdateDomainDespiteDns">
                                            Continue
                                        </x-forms.button>
                                    @else
                                        <x-forms.button type="submit" wire:target="updateDomain" isHighlighted>
                                            Save
                                        </x-forms.button>
                                    @endif
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
</div>
