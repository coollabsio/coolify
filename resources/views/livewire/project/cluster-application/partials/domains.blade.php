@php
    $domainList = $workload->domains ?? [];
    $hasRows = $domainList !== [];
    $gridClass = 'grid-cols-[minmax(0,1fr)_6.5rem_5rem]';
@endphp

<div class="flex flex-col gap-6" x-data="{
    domainSearch: '',
    matchesDomainSearch(value) {
        const query = this.domainSearch.trim().toLowerCase();
        return query === '' || value.toLowerCase().includes(query);
    },
}">
    <x-application.settings-section id="cluster-application-domains" title="Domains" :flush="$hasRows">
        <x-slot:actions>
            @if ($hasRows)
                <div class="relative w-full sm:w-64">
                    <x-reicon name="search"
                        class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                    <input type="search" x-model="domainSearch" aria-label="Search domains"
                        class="input h-8! w-full pl-8! text-[13px]!" placeholder="Search domains" />
                </div>
            @endif
            @can('update', $workload)
                <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Add domain
                        </button>
                    </x-slot:content>
                    <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
                            <x-forms.input id="newDomain" label="Domain" :placeholder="$suggestedDomain ?? 'app.example.com'" required
                                helper="The hostname only, without http://, a path, or a port." />
                            <x-forms.input id="newDomainPort" type="number" min="1" max="65535" label="Internal port"
                                required helper="The port inside the container that receives HTTP traffic. It applies to every domain of this application." />
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                            <x-forms.button type="button" wire:click="generateDomain">Generate domain</x-forms.button>
                            <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @if ($hasRows)
            <div class="data-table-header {{ $gridClass }}">
                <span>Domain</span>
                <span class="text-center">Internal port</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($domainList as $domain)
                    <div wire:key="cluster-application-domain-{{ $domain }}" class="env-table-item"
                        x-show="matchesDomainSearch(@js($domain))">
                        <div class="data-table-row {{ $gridClass }} py-2!">
                            <a href="http://{{ $domain }}" target="_blank" rel="noopener noreferrer"
                                class="min-w-0 truncate text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs dark:text-fg dark:decoration-white/20 dark:hover:decoration-brand-light"
                                title="http://{{ $domain }}">http://{{ $domain }}</a>
                            <span class="text-center text-[12px]">{{ $httpPort }}</span>
                            <div class="flex items-center justify-end gap-1">
                                @can('update', $workload)
                                    <button type="button" wire:click="editDomain(@js($domain))" class="icon-button shrink-0"
                                        title="Domain settings" aria-label="Settings for {{ $domain }}">
                                        <x-reicon name="settings" class="size-3.5" />
                                    </button>
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?" buttonTitle="Remove"
                                        isErrorButton canGate="update" :canResource="$workload"
                                        submitAction="removeDomain('{{ $domain }}')" :actions="[
                                            'This domain will be removed from the application.',
                                            'Ingress servers stop routing it without a redeploy.',
                                        ]" :confirmWithPassword="false" :confirmWithText="false"
                                        step2ButtonText="Remove domain">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Remove domain" aria-label="Remove {{ $domain }}">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div x-cloak x-show="domainSearch.trim() && !@js($domainList).some((domain) => matchesDomainSearch(domain))"
                class="px-4 py-8">
                <x-empty size="sm" title="No domains found" description="No domain matches your search." icon-name="search" />
            </div>
        @else
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the Add domain button above, or generate an sslip.io domain." icon-name="globe" />
        @endif
    </x-application.settings-section>

    <x-application.settings-section id="cluster-application-dns" title="DNS"
        helper="Ingress servers of the cluster route HTTP traffic for these domains to this application.">
        <div class="flex flex-col gap-4">
            @if ($ingressAddresses === [])
                <x-callout type="warning" title="No ingress server">
                    This cluster has no ingress server, so the domains are not reachable yet.
                    @if ($ingressCluster)
                        <a class="font-medium underline" {{ wireNavigate() }}
                            href="{{ route('node-cluster.nodes', ['cluster_uuid' => $ingressCluster->uuid]) }}">Turn on
                            ingress for a server</a>.
                    @endif
                </x-callout>
            @else
                <p class="text-sm text-neutral-500 dark:text-fg-dim">
                    Point an A record for each domain to one or more of these addresses.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($ingressAddresses as $ingressAddress)
                        <div wire:key="cluster-application-ingress-{{ $ingressAddress }}">
                            <x-forms.copy-input :text="$ingressAddress" />
                        </div>
                    @endforeach
                </div>
            @endif
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">HTTPS is not available yet. Ingress serves
                these domains over HTTP on port 80.</p>
        </div>
    </x-application.settings-section>

    @can('update', $workload)
        <x-modal-input title="Domain settings" :closeOutside="false" :wireIgnore="false" wireOpen="showEditDomainModal">
            <x-slot:content><span class="hidden"></span></x-slot:content>
            <form wire:submit="updateDomain" class="application-settings-form flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
                    <x-forms.input id="editingDomain" label="Domain" :placeholder="$suggestedDomain ?? 'app.example.com'" required
                        helper="The hostname only, without http://, a path, or a port." />
                    <x-forms.input id="editingPort" type="number" min="1" max="65535" label="Internal port" required
                        helper="The port inside the container that receives HTTP traffic. It applies to every domain of this application." />
                </div>
                <div class="flex justify-end pt-2">
                    <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                </div>
            </form>
        </x-modal-input>
    @endcan
</div>
