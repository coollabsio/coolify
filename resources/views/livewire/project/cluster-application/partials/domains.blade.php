@php
    $domainList = $workload->domains ?? [];
    // The www redirect hosts need DNS records too.
    $dnsHosts = [...$domainList, ...array_keys(\App\Actions\Node\BuildNodeClusterIngressRoutes::wwwRedirects($domainList, $workload->www_redirect))];
    $domainRoutes = $workload->domain_routes ?? [];
    $hasRows = $domainRoutes !== [];
    $hasHttpsDomains = collect($domainRoutes)->contains('scheme', 'https');
    $domainRedirect = match ($workload->www_redirect) {
        'www' => 'non-www → www',
        'non-www' => 'www → non-www',
        default => null,
    };
@endphp

<div class="domains-overview-container flex flex-col gap-6" x-data="{
    domainSearch: '',
    matchesDomainSearch(value) {
        const query = this.domainSearch.trim().toLowerCase();
        return query === '' || value.toLowerCase().includes(query);
    },
}">
    @unless ($ingressRoutesV2)
        <x-callout type="warning" title="Some domain settings are not applied yet">
            Paths, the http protocol, www redirects, search indexing, and redirect settings need the latest
            Sentinel on every server of the cluster. Until then, ingress serves each domain on all paths and
            uses HTTPS when it has a certificate.
        </x-callout>
    @endunless
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
            <div x-data="{ dnsEntriesOpen: false }" class="relative shrink-0" @click.outside="dnsEntriesOpen = false">
                <button type="button" class="button" @click="dnsEntriesOpen = !dnsEntriesOpen" aria-haspopup="menu"
                    x-bind:aria-expanded="dnsEntriesOpen" title="DNS records for this application">
                    <x-reicon name="globe" class="size-3.5" />
                    DNS
                    <span class="inline-flex transition-transform" :class="dnsEntriesOpen && 'rotate-180'">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                            stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
                        </svg>
                    </span>
                </button>
                <div x-show="dnsEntriesOpen" x-cloak role="menu" x-transition.origin.top.right
                    class="listbox-panel left-auto! right-0! z-[90]! w-56! min-w-56!">
                    <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                        @click="dnsEntriesOpen = false; $dispatch('open-cluster-dns-records-modal')">
                        <x-reicon name="documentation" class="size-3.5 shrink-0 opacity-70" />
                        Manual records
                    </button>
                </div>
            </div>
            @can('update', $workload)
                <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Add domain
                        </button>
                    </x-slot:content>
                    <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                        <x-forms.domain-input id="newDomainParts" :hostPlaceholder="$suggestedDomain ?? 'app.example.com'"
                    pathHelper="Optional path prefix, like /api. Ingress removes it before the request reaches the application." />
                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                            <x-forms.button type="button" wire:click="generateDomain">Generate domain</x-forms.button>
                            <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @if ($hasRows)
            <div class="data-table-header service-domains-overview-grid">
                <span>Domain</span>
                <span>Protocol redirect</span>
                <span>Domain redirect</span>
                <span>Internal port</span>
                <span>Search indexing</span>
                <span>HTTPS</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($domainRoutes as $route)
                    @php
                        $displayUrl = \App\Actions\Node\UpdateNodeWorkloadDomains::url($route);
                        $certificate = $domainCertificates[$route['host']] ?? null;
                        $servedScheme = $route['scheme'] === 'https' && \App\Models\NodeWorkload::servesHttps($certificate) ? 'https' : 'http';
                        $domainUrl = $servedScheme.'://'.$route['host'].$route['path'];
                        $redirectsToHttps = $route['scheme'] === 'https' && $forceHttps;
                        $isNoindexed = (bool) ($route['noindex'] ?? false);
                    @endphp
                    <div wire:key="cluster-application-domain-{{ md5($route['host'].'|'.$route['path']) }}" class="env-table-item"
                        x-show="matchesDomainSearch(@js($displayUrl))">
                        <div class="data-table-row service-domains-overview-grid">
                            <div class="flex min-w-0 items-center gap-2">
                                <a href="{{ $domainUrl }}" target="_blank" rel="noopener noreferrer"
                                    class="min-w-0 flex-1 truncate text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs dark:text-fg dark:decoration-white/20 dark:hover:decoration-brand-light"
                                    title="{{ $displayUrl }}">{{ $displayUrl }}</a>
                            </div>
                            <div class="service-domain-detail" title="Protocol redirect">
                                <span class="service-domain-detail-label">Protocol redirect</span>
                                <span>{{ $redirectsToHttps ? 'HTTP → HTTPS' : 'Disabled' }}</span>
                            </div>
                            <div class="service-domain-detail" title="Domain redirect">
                                <span class="service-domain-detail-label">Domain redirect</span>
                                <span>{{ $domainRedirect ?? 'Disabled' }}</span>
                            </div>
                            <div class="service-domain-detail" title="Container port that receives the traffic of this domain">
                                <span class="service-domain-detail-label">Internal port</span>
                                <span aria-label="Internal port {{ $route['port'] }}">{{ $route['port'] }}</span>
                            </div>
                            <div class="service-domain-detail">
                                <span class="service-domain-detail-label">Search indexing</span>
                                <span role="img" aria-label="{{ $isNoindexed ? 'Search indexing blocked' : 'Search indexing allowed' }}"
                                    title="{{ $isNoindexed ? 'Search indexing blocked' : 'Search indexing allowed' }}">
                                    <x-reicon :name="$isNoindexed ? 'x' : 'check'" class="size-4" />
                                </span>
                            </div>
                            <div class="service-domain-mobile-summary" aria-label="Domain routing summary">
                                @if ($redirectsToHttps)
                                    <span>HTTP → HTTPS</span>
                                @endif
                                @if ($domainRedirect)
                                    <span>{{ $domainRedirect }}</span>
                                @elseif (! $redirectsToHttps)
                                    <span>No redirects</span>
                                @endif
                                <span>Port {{ $route['port'] }}</span>
                                <span>{{ $isNoindexed ? 'Noindex' : 'Indexable' }}</span>
                            </div>
                            <div class="service-domain-dns flex min-w-0 items-center justify-center">
                                @if ($route['scheme'] === 'http')
                                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim" title="Served over HTTP only, without a certificate">HTTP only</span>
                                @elseif ($ingressTls)
                                    <x-cluster-applications.https-badge :certificate="$certificate" />
                                @else
                                    <span class="text-[12px] text-neutral-500 dark:text-fg-dim"
                                        title="HTTPS needs the latest Sentinel on every server of the cluster and an ACME directory on this instance.">Unavailable</span>
                                @endif
                            </div>
                            <div class="service-domain-actions flex items-center justify-end gap-1">
                                @can('update', $workload)
                                    <x-table.row-actions label="Domain actions">
                                        <x-table.row-action wire:click="editDomain({{ \Illuminate\Support\Js::from($route['host']) }}, {{ \Illuminate\Support\Js::from($route['path']) }})"
                                            aria-label="Edit {{ $displayUrl }}">
                                            Edit
                                        </x-table.row-action>
                                        <x-slot:danger>
                                            <x-modal-confirmation buttonFullWidth title="Remove domain?" buttonTitle="Remove"
                                                isErrorButton canGate="update" :canResource="$workload"
                                                submitAction="removeDomain('{{ $route['host'] }}', '{{ $route['path'] }}')" :actions="[
                                                    'This domain will be removed from the application.',
                                                    'Ingress servers stop routing it without a redeploy.',
                                                ]" :confirmWithPassword="false" :confirmWithText="false"
                                                step2ButtonText="Remove domain">
                                                <x-slot:trigger>
                                                    <x-table.row-action>Delete</x-table.row-action>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        </x-slot:danger>
                                    </x-table.row-actions>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div x-cloak x-show="domainSearch.trim() && !@js(collect($domainRoutes)->map(fn ($route) => \App\Actions\Node\UpdateNodeWorkloadDomains::url($route))->values()).some((domain) => matchesDomainSearch(domain))"
                class="px-4 py-8">
                <x-empty size="sm" title="No domains found" description="No domain matches your search." icon-name="search" />
            </div>
        @else
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the Add domain button above, or generate an sslip.io domain." icon-name="globe" />
        @endif
    </x-application.settings-section>

    @if ($hasRows)
        <x-application.settings-section id="cluster-application-domain-redirects" title="Redirects"
            helper="Applies to all domains of this application.">
            <div class="grid gap-4 lg:grid-cols-2">
                @if ($hasHttpsDomains)
                    <x-forms.listbox canGate="update" :canResource="$workload" id="forceHttps"
                        label="Redirect HTTP to HTTPS" onChange="updateForceHttps"
                        helper="Disable only when Cloudflare Tunnel or another proxy connects to the ingress servers over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL."
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                @endif
                <x-forms.listbox canGate="update" :canResource="$workload" id="permanentRedirect"
                    label="Redirect type" onChange="updatePermanentRedirect"
                    helper="Applies to the HTTP to HTTPS and www redirects. Permanent (308) is better for SEO, but browsers cache it. Use Temporary (307) while you test a redirect."
                    :options="[
                        ['value' => false, 'label' => 'Temporary'],
                        ['value' => true, 'label' => 'Permanent'],
                    ]" />
            </div>
        </x-application.settings-section>
    @endif

    @can('update', $workload)
        <x-modal-input title="Domain settings" :closeOutside="false" :wireIgnore="false" wireOpen="showEditDomainModal">
            <x-slot:content><span class="hidden"></span></x-slot:content>
            <form wire:submit="updateDomain" class="application-settings-form flex flex-col gap-4">
                <x-forms.domain-input id="editingDomainParts" :hostPlaceholder="$suggestedDomain ?? 'app.example.com'"
                    pathHelper="Optional path prefix, like /api. Ingress removes it before the request reaches the application." />
                <div class="grid grid-cols-1 gap-4 border-t border-neutral-200 pt-4 sm:grid-cols-2 dark:border-white/10">
                    <x-forms.listbox id="editingIndexing" htmlId="cluster-application-domain-indexing"
                        label="Search engine indexing" portal :options="[
                            ['value' => 'index', 'label' => 'Indexable'],
                            ['value' => 'noindex', 'label' => 'Noindex'],
                        ]" />
                    <x-forms.listbox id="editingRedirect" htmlId="cluster-application-domain-direction"
                        label="www redirect" helper="Applies to all domains of this application." portal :options="[
                            ['value' => 'both', 'label' => 'No redirect'],
                            ['value' => 'www', 'label' => 'Redirect to www'],
                            ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                        ]" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/10">
                    <x-forms.button type="button" wire:click="generateDomain(true)" wire:target="generateDomain">
                        Generate domain
                    </x-forms.button>
                    <x-forms.button type="submit" wire:target="updateDomain" isHighlighted>Save</x-forms.button>
                </div>
            </form>
        </x-modal-input>
    @endcan
    <div x-data="{ modalOpen: false }" @open-cluster-dns-records-modal.window="modalOpen = true"
        class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }"
        @keydown.window.escape="modalOpen = false">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]" @click="modalOpen = false">
                </div>
                <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="application-settings-form application-settings-section relative flex w-full max-w-2xl flex-col overflow-hidden"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">DNS entries</h3>
                            <button type="button" @click="modalOpen = false" class="icon-button shrink-0"
                                aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body flex flex-col gap-4">
                            <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                                Ingress servers of the cluster route HTTP traffic for these domains to this
                                application. Create matching Type / Name / Value records at your DNS provider.
                            </p>
                            @if ($ingressAddresses === [])
                                <x-callout type="warning" title="No ingress server">
                                    This cluster has no ingress server, so the domains are not reachable yet.
                                    @if ($ingressCluster)
                                        <a class="font-medium underline" {{ wireNavigate() }}
                                            href="{{ route('node-cluster.nodes', ['cluster_uuid' => $ingressCluster->uuid]) }}">Turn
                                            on ingress for a server</a>.
                                    @endif
                                </x-callout>
                            @elseif (! $hasRows)
                                <x-callout type="info" title="Nothing to configure">
                                    No domains are configured yet. Point an A record for each domain to one or more
                                    of these addresses:
                                    {{ implode(', ', $ingressAddresses) }}
                                </x-callout>
                            @else
                                <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                                    <table class="w-full min-w-[32rem] text-left text-sm">
                                        <thead
                                            class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                                            <tr>
                                                <th class="px-3 py-2 font-medium">Type</th>
                                                <th class="px-3 py-2 font-medium">Name</th>
                                                <th class="px-3 py-2 font-medium">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                                            @foreach ($dnsHosts as $domain)
                                                @foreach ($ingressAddresses as $ingressAddress)
                                                    <tr wire:key="cluster-application-dns-{{ $domain }}-{{ $ingressAddress }}"
                                                        class="text-[13px] text-black dark:text-fg">
                                                        <td class="px-3 py-2.5">A</td>
                                                        <td class="px-3 py-2.5">
                                                            @include('livewire.project.shared.partials.dns-copy-cell', [
                                                                'text' => $domain,
                                                                'label' => 'Copy name',
                                                                'break' => true,
                                                            ])
                                                        </td>
                                                        <td class="px-3 py-2.5">
                                                            @include('livewire.project.shared.partials.dns-copy-cell', [
                                                                'text' => $ingressAddress,
                                                                'label' => 'Copy value',
                                                                'break' => true,
                                                            ])
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                @if ($ingressTls)
                                    When a domain points to the ingress servers, they get a Let's Encrypt certificate
                                    for it and redirect HTTP to HTTPS. Until then, they serve it over HTTP on port 80.
                                    Ports 80 and 443 must be open on the ingress servers.
                                @else
                                    HTTPS is not available for this cluster: it needs the latest Sentinel on every server
                                    of the cluster and an ACME directory on this instance. Until then, ingress serves
                                    these domains over HTTP on port 80.
                                @endif
                            </p>
                            <div class="flex justify-end pt-2">
                                <x-forms.button type="button" @click="modalOpen = false" isHighlighted>
                                    Done
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
