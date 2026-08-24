@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $hasDnsChecksInProgress = collect($domainRows)->contains(fn ($row) => $row['dns_status'] === 'checking');
    $serviceAppCount = count($serviceApps);
    $domainGroups = collect($domainRows)
        ->groupBy('service_application_id')
        ->filter(fn ($rows) => $rows->contains(fn ($row) => ! ($row['is_suggested'] ?? false)));
    $domainSearchValues = $domainGroups->map(function ($rows, $appId) use ($serviceApps) {
        $app = collect($serviceApps)->firstWhere('id', (int) $appId);
        $heading = \Illuminate\Support\Str::headline($app['name'] ?? $rows->first()['service_name'] ?? 'Service');

        return $heading.' '.$rows->pluck('url')->implode(' ');
    })->values();
@endphp

<div class="flex flex-col gap-4"
    x-data="{
        domainSearch: '',
        modalOpen: @js($showEditDomainModal || $editDomainDnsFailed),
        editingServiceLabel: '',
        openEditDomain() {
            this.editingServiceLabel = $wire.serviceApps.find(app => app.id === $wire.editingServiceApplicationId)?.name || '';
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
    <x-application.settings-section id="service-domains-section" title="Domains">
        @can('update', $service)
            <x-slot:actions>
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled"
                    wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Recheck DNS
                </x-forms.button>
            </x-slot:actions>
        @endcan

        @cannot('update', $service)
            <x-callout type="danger" title="Insufficient permissions">
                You don't have permission to manage domains. Contact your team administrator for access.
            </x-callout>
        @endcannot

        <p class="text-sm text-neutral-500 dark:text-fg-dim">
            Manage domains and www/non-www redirects for applications in this stack.
        </p>

    </x-application.settings-section>

    {{-- Toolbar --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <p class="min-w-0 flex-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
            @if ($suggestedCount > 0)
                · {{ $suggestedCount }} not added
            @endif
        </p>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($domainGroups->isNotEmpty())
                <div class="relative w-full sm:w-64">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="domainSearch" aria-label="Search services or domains"
                        class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search services or domains" />
                </div>
            @endif
            @can('update', $service)
                @if ($serviceAppCount > 0)
                    <div class="relative shrink-0">
                        @include('livewire.project.shared.cloudflare-autoconfigure')
                    </div>
                    <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                        canGate="update" :canResource="$service">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
                                <x-reicon name="plus" class="size-3.5" />
                                Add
                            </button>
                        </x-slot:content>
                        <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                            {{-- Always show which service receives the domain --}}
                            <x-forms.listbox canGate="update" :canResource="$service" label="Service application" id="newServiceApplicationId" required
                                helper="Domain will be assigned to this compose service application."
                                :options="collect($serviceApps)->map(fn ($app) => [
                                    'value' => $app['id'],
                                    'label' => $app['name'].(filled($app['image'] ?? null) ? ' ('.$app['image'].')' : ''),
                                ])->values()->all()"
                                :disabled="! auth()->user()->can('update', $service)" />

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
                                <x-forms.button canGate="update" :canResource="$service" type="button"
                                    wire:click="generateDomain">
                                    Generate domain
                                </x-forms.button>
                                <div class="flex flex-wrap gap-2">
                                    @if ($addDomainDnsFailed)
                                        <x-forms.button canGate="update" :canResource="$service" type="button"
                                            wire:click="confirmAddDomainDespiteDns" isError>
                                            Continue
                                        </x-forms.button>
                                    @else
                                        <x-forms.button canGate="update" :canResource="$service" type="submit"
                                            isHighlighted>
                                            Save
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </x-modal-input>
                @endif
            @endcan
        </div>
    </div>

    @if ($serviceAppCount === 0)
        <div class="application-settings-section-body mt-1 w-full scroll-mt-28">
            <x-empty size="sm" title="No application services"
                description="Only database services are available. Domains can only be assigned to application services."
                icon-name="globe" />
        </div>
    @elseif (! $hasRows)
        <div class="application-settings-section-body mt-1 w-full scroll-mt-28">
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the + Add button above. Choose which service application receives it."
                icon-name="globe" />
        </div>
    @else
        <div wire:key="service-domains-list"
            class="application-settings-section-body is-flush mt-1 w-full scroll-mt-28 overflow-visible">
            @foreach ($domainGroups as $appId => $rows)
                @php
                    $app = collect($serviceApps)->firstWhere('id', (int) $appId);
                    $heading = \Illuminate\Support\Str::headline($app['name'] ?? $rows->first()['service_name'] ?? 'Service');
                    $hasHttpsDomains = $rows->contains(
                        fn ($row) => ! ($row['is_suggested'] ?? false) && str_starts_with(strtolower($row['url']), 'https://')
                    );
                @endphp
                <section id="service-domain-group-{{ $appId }}" wire:key="service-domain-group-{{ $appId }}"
                    x-show="matchesDomainSearch(@js($heading.' '.$rows->pluck('url')->implode(' ')))"
                    class="border-b border-neutral-200 last:border-b-0 dark:border-white/10">
                    <div class="flex w-full flex-wrap items-center gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.04]">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-white">{{ $heading }}</span>
                        @if ($hasHttpsDomains)
                            <div class="w-full sm:w-72">
                                <x-forms.listbox canGate="update" :canResource="$service" id="forceHttpsRedirects.{{ $appId }}"
                                    htmlId="service-force-https-{{ $appId }}"
                                    label="Redirect HTTP to HTTPS" onChange="updateForceHttps"
                                    :onChangeArgs="[(int) $appId]"
                                    helper="Disable only when Cloudflare Tunnel or another proxy connects to Coolify over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL."
                                    :options="[
                                        ['value' => true, 'label' => 'Enabled'],
                                        ['value' => false, 'label' => 'Disabled'],
                                    ]" :disabled="! auth()->user()->can('update', $service)" />
                            </div>
                        @endif
                    </div>

                    <div wire:key="service-domain-rows-{{ $appId }}-{{ md5(serialize($rows->all())) }}">
                        @include('livewire.project.service.partials.domain-table', [
                            'rows' => $rows,
                            'domainRows' => $domainRows,
                            'service' => $service,
                            'showServiceColumn' => false,
                            'showHeader' => true,
                        ])
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
    @endif

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
                            <button type="button" @click="closeEditDomain()" class="icon-button shrink-0"
                                aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body relative min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <form wire:submit="updateDomain" class="flex flex-col gap-4">
                                <div x-show="editingServiceLabel" x-cloak class="w-full">
                                    <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
                                        <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">Service application</label>
                                    </div>
                                    <input type="text" class="input" readonly x-bind:value="editingServiceLabel" />
                                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                        Domains stay on the service they were added to. Remove and re-add to move.
                                    </p>
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
