<div class="domains-overview-container flex flex-col gap-3" x-data="{
        editOpen: false,
        domainSearch: '',
        editingDomainBaseline: null,
        get hasAddressChanges() {
            return this.editOpen && this.editingDomainBaseline !== null
                && JSON.stringify($wire.editingDomainParts) !== this.editingDomainBaseline
                && !$wire.showPortWarningModal;
        },
        editingServiceLabel: '',
        openEditDomain(index, domain, parts, service) {
            if (index !== undefined) {
                $wire.set('editingIndex', index, false);
                $wire.set('editingDomainParts', parts, false);
            }
            this.editingServiceLabel = service || '';
            this.editingDomainBaseline = JSON.stringify($wire.editingDomainParts);
            this.editOpen = true;
            this.$nextTick(() => this.$refs.editForm.querySelector('input[required]')?.focus());
        },
        closeEditDomain(discardDraft = true) {
            this.editOpen = false;
            this.editingDomainBaseline = null;
            if (discardDraft) this.$wire.cancelEdit();
        },
        matchesDomainSearch(value) { return !this.domainSearch.trim() || value.toLowerCase().includes(this.domainSearch.trim().toLowerCase()); },
    }"
    @open-preview-domain-edit.window="if ($event.detail.previewId === {{ $preview->id }}) openEditDomain()"
    @close-preview-domain-edit.window="if ($event.detail.previewId === {{ $preview->id }}) closeEditDomain(false)"
    @keydown.escape.window="if (editOpen && !$wire.showPortWarningModal) closeEditDomain()">
    @if (collect($domainRows)->contains(fn ($row) => $row['dns_status'] === 'checking'))
        <div class="hidden" wire:poll.2000ms="pollDnsChecks" aria-hidden="true"></div>
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <p class="min-w-0 flex-1 truncate text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ count($domainRows) }} domain{{ count($domainRows) === 1 ? '' : 's' }}
        </p>
        @if (count($domainRows) > 0)
            <input type="search" x-model="domainSearch" aria-label="Search preview domains"
                class="input h-8! w-full sm:w-64!" placeholder="Search services or domains" />
        @endif
        @can('update', $preview->application)
            @if (count($domainRows) > 0)
                <x-forms.button wire:click="checkAllDns" :showLoadingIndicator="false" wire:loading.attr="disabled" wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Check all DNS
                </x-forms.button>
            @endif
            <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                canGate="update" :canResource="$preview->application"
                @close-preview-domain-add.window="if ($event.detail.previewId === {{ $preview->id }}) modalOpen = false">
                <x-slot:content>
                    <button type="button" class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Add domain
                    </button>
                </x-slot:content>
                <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                    @if ($isCompose && count($composeServices) > 0)
                        <x-forms.listbox id="newDomainService" label="Service" required
                            :options="collect($composeServices)->map(fn ($service) => ['value' => $service, 'label' => $service])->all()" />
                    @endif
                    <x-forms.domain-input id="newDomainParts" />

                    <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                        <x-forms.button type="button" wire:click="generateDomain">Generate domain</x-forms.button>
                        <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                    </div>
                </form>
            </x-modal-input>
        @endcan
    </div>

    @if (count($domainRows) === 0)
        <div class="application-settings-section-body">
            <x-empty size="sm" title="No domains configured"
                description="Add a domain or generate one with the server wildcard domain." icon-name="globe" />
        </div>
    @else
        <div class="application-settings-section-body is-flush overflow-visible">
            <div class="data-table-header service-domains-overview-grid">
                <span>Domain</span>
                <span>Protocol redirect</span>
                <span>Domain redirect</span>
                <span>Internal port</span>
                <span>Search indexing</span>
                <span>DNS status</span>
                <span class="text-right">Actions</span>
            </div>
            @foreach (collect($domainRows)->groupBy(fn ($row) => $row['service'] ?? '', preserveKeys: true) as $serviceName => $rows)
                @if ($isCompose)
                    <div wire:key="preview-domain-service-{{ md5($serviceName) }}"
                        x-show="matchesDomainSearch(@js($serviceName.' '.$rows->pluck('url')->implode(' ')))"
                        class="border-b border-neutral-200 bg-neutral-50 px-4 py-3 text-sm font-medium dark:border-white/10 dark:bg-white/[0.04]">{{ $serviceName }}</div>
                @endif
                @foreach ($rows as $index => $row)
                    @php
                        $dnsType = match ($row['dns_status']) {
                            'ok' => 'success',
                            'failed' => 'error',
                            'skipped' => 'warning',
                            default => 'neutral',
                        };
                        $dnsLabel = match ($row['dns_status']) {
                            'ok' => 'DNS matches',
                            'failed' => 'DNS mismatch',
                            'skipped' => 'DNS skipped',
                            'checking' => 'Checking DNS...',
                            'pending' => 'Not checked',
                            default => 'DNS unknown',
                        };
                        $domainKey = hash('sha256', $row['url'].'|'.($row['service'] ?? ''));
                        $editingParts = \App\Support\DomainUrlParts::split($row['url']);
                        if ($row['has_port_override'] ?? false) {
                            $editingParts['port'] = (string) $row['internal_port'];
                        }
                    @endphp
                    <div wire:key="preview-domain-{{ md5(($row['service'] ?? '') . $row['url']) }}" x-show="matchesDomainSearch(@js(($row['service'] ?? '').' '.$row['url']))" class="env-table-item">
                        <div class="data-table-row service-domains-overview-grid">
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    <x-reicon name="globe" class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                    <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank" rel="noopener noreferrer"
                                        class="min-w-0 flex-1 truncate text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                                        title="{{ getFqdnWithoutPort($row['url']) }}">{{ getFqdnWithoutPort($row['url']) }}</a>
                                </div>
                            </div>
                            <div class="service-domain-detail" title="Protocol redirect">
                                <span class="service-domain-detail-label">Protocol redirect</span>
                                <span>{{ str_starts_with($row['url'], 'https://') && $preview->application->isForceHttpsEnabled() ? 'HTTP → HTTPS' : 'Disabled' }}</span>
                            </div>
                            <div class="service-domain-detail" title="Domain redirect">
                                <span class="service-domain-detail-label">Domain redirect</span>
                                <span>{{ match (($row['redirect'] ?? 'both')) { 'www' => 'non-www → www', 'non-www' => 'www → non-www', default => 'Disabled' } }}</span>
                            </div>
                            <div class="service-domain-detail"
                                title="{{ ($row['has_port_override'] ?? false) ? 'Custom internal port for this domain' : 'Inherited from the application or Compose service port' }}">
                                <span class="service-domain-detail-label">Internal port</span>
                                @if (filled($row['internal_port'] ?? null))
                                    <span aria-label="Internal port {{ $row['internal_port'] }}">{{ $row['internal_port'] }}</span>
                                @else
                                    <span role="img" aria-label="No internal port" title="No internal port. Set Ports Exposes or a per-domain internal port so the proxy can route this domain." class="text-red-500 dark:text-red-400">
                                        <x-reicon name="alert-triangle" class="size-4" />
                                    </span>
                                @endif
                            </div>
                            <div class="service-domain-detail">
                                <span class="service-domain-detail-label">Search indexing</span>
                                <span role="img" aria-label="Search indexing blocked"
                                    title="Search indexing blocked">
                                    <x-reicon name="x" class="size-4" />
                                </span>
                            </div>

                            <div class="service-domain-mobile-summary" aria-label="Domain routing summary">
                                @if (str_starts_with($row['url'], 'https://') && $preview->application->isForceHttpsEnabled())
                                    <span>HTTP → HTTPS</span>
                                @endif
                                @if (in_array($row['redirect'] ?? 'both', ['www', 'non-www'], true))
                                    <span>{{ ($row['redirect'] ?? 'both') === 'www' ? 'non-www → www' : 'www → non-www' }}</span>
                                @elseif (! str_starts_with($row['url'], 'https://') || ! $preview->application->isForceHttpsEnabled())
                                    <span>No redirects</span>
                                @endif
                                <span>Port {{ $row['internal_port'] ?? 'missing' }}</span>
                                <span>Noindex</span>
                            </div>

                            <div class="service-domain-dns flex min-w-0 items-center">
                                @if ($row['dns_status'] === 'checking')
                                    <x-status-badge dynamic :title="$row['dns_message']">
                                        <x-loading compact aria-label="Checking DNS" />
                                        <span class="truncate">Checking DNS...</span>
                                    </x-status-badge>
                                @else
                                    <x-status-badge :status="$dnsLabel" :type="$dnsType" :title="$row['dns_message']" />
                                @endif
                            </div>
                            <div class="service-domain-actions flex items-center justify-end gap-1">
                                @can('update', $preview->application)
                                    <button type="button" wire:click="checkDomainDns({{ $index }})"
                                        wire:loading.attr="disabled"
                                        wire:target="checkDomainDns({{ $index }}),checkAllDns"
                                        class="icon-button shrink-0" title="Check DNS" aria-label="Check DNS">
                                        <x-reicon name="refresh" class="size-3.5" />
                                    </button>
                                    <button type="button"
                                        @click="openEditDomain(@js($index), @js($row['url']), @js($editingParts), @js($row['service']))"
                                        class="icon-button shrink-0" title="Domain settings" aria-label="Settings for {{ getFqdnWithoutPort($row['url']) }}">
                                        <x-reicon name="settings" class="size-3.5" />
                                    </button>
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?"
                                        buttonTitle="Remove" isErrorButton
                                        submitAction="removeDomainByKey({{ $domainKey }})"
                                        :actions="[
                                            'This domain will be removed from the preview deployment.',
                                            'Redeploy the preview to apply proxy changes.',
                                        ]"
                                        :confirmWithPassword="false" :confirmWithText="false"
                                        step2ButtonText="Remove domain">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Remove domain" aria-label="Remove domain">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
            <div x-cloak x-show="domainSearch.trim() && !@js(collect($domainRows)->map(fn ($row) => ($row['service'] ?? '').' '.$row['url'])->values()).some(value => matchesDomainSearch(value))" class="px-4 py-8">
                <x-empty size="sm" title="No domains found" description="No service or domain matches your search." icon-name="search" />
            </div>
        </div>
    @endif

    <template x-teleport="body">
        <div x-show="editOpen" x-cloak class="fixed inset-0 z-99 overflow-y-auto">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" @click="closeEditDomain()"></div>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div x-show="editOpen" x-trap.inert.noscroll="editOpen"
                    data-preview-domain-dialog class="application-settings-form application-settings-section relative w-full max-w-3xl">
                    <header>
                        <h3>Domain settings</h3>
                        <button type="button" @click="closeEditDomain()" class="icon-button" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body">
                        <form x-ref="editForm" wire:submit="updateDomain" class="flex flex-col gap-4">
                            <div x-show="editingServiceLabel" x-cloak>
                                <div class="mb-1.5 flex h-4 items-center">
                                    <label class="mb-0! leading-4">Service</label>
                                </div>
                                <input type="text" class="input" readonly x-bind:value="editingServiceLabel" />
                            </div>
                            <x-forms.domain-input id="editingDomainParts" />
                            <div class="grid grid-cols-1 gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2 dark:border-white/10">
                                <x-forms.listbox id="preview-domain-indexing-{{ $preview->id }}" label="Search engine indexing"
                                    :wire="false" value="noindex" disabled
                                    helper="Preview deployments are always excluded from search indexing."
                                    :options="[['value' => 'noindex', 'label' => 'Noindex']]" />
                                <x-forms.listbox id="preview-domain-direction-{{ $preview->id }}" label="www redirect"
                                    :wire="false" :value="$editingIndex !== null ? ($domainRows[$editingIndex]['redirect'] ?? 'both') : 'both'" disabled
                                    helper="Read-only preview routing configuration."
                                    :options="[
                                        ['value' => 'both', 'label' => 'No redirect'],
                                        ['value' => 'www', 'label' => 'Redirect to www'],
                                        ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                                    ]" />
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/10">
                                <x-forms.button type="button" wire:click="regenerateEditingDomain">Regenerate hostname</x-forms.button>
                                <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </template>

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
                                <x-forms.button type="button" canGate="update" :canResource="$preview->application"
                                    @click="modalOpen = false; $wire.call('cancelUseUnknownPort')">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="confirmUseUnknownPort" canGate="update"
                                    :canResource="$preview->application"
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
</div>
