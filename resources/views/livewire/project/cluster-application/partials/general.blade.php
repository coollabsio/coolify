@php
    $latestDeployment = $workload->operations->firstWhere('command_type', 'workload.deploy.v1');
@endphp

<form wire:submit="saveDetails" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveDetails" targets="name,description" />
    @endcan

    <x-application.settings-section id="cluster-application-details" title="Application details"
        helper="Name the application. Renaming it does not change its internal hostname.">
        <div class="grid gap-4">
            <x-forms.input id="name" label="Name" required canGate="update" :canResource="$workload" />
            <x-forms.input id="description" label="Description" canGate="update" :canResource="$workload" />
        </div>
    </x-application.settings-section>
</form>

<x-application.settings-section id="cluster-application-overview" title="Overview"
    helper="Where this application runs and how other workloads in the cluster reach it.">
    <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2">
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Status</dt>
            <dd class="mt-1">
                <x-status-badge :status="$status" :type="$statusType" />
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Image</dt>
            <dd class="mt-1 break-all font-mono text-xs">{{ $image ?? 'Unknown' }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Cluster</dt>
            <dd class="mt-1">
                @if ($node->cluster)
                    <a class="underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                        {{ wireNavigate() }}
                        href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}">
                        {{ $node->cluster->name }}
                    </a>
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">None</span>
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">{{ $servers->count() > 1 ? 'Servers' : 'Server' }}</dt>
            <dd class="mt-1 flex flex-wrap gap-x-3 gap-y-1">
                @foreach ($servers as $server)
                    <a wire:key="cluster-application-server-{{ $server->uuid }}"
                        class="underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                        {{ wireNavigate() }}
                        href="{{ route('node.show', ['node_uuid' => $server->uuid]) }}">
                        {{ $server->name }}
                    </a>
                @endforeach
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Internal hostname</dt>
            <dd class="mt-1 flex min-w-0 items-center gap-1.5">
                @if ($internalHostname)
                    <span class="truncate font-mono text-xs">{{ $internalHostname }}</span>
                    <x-copy-button :value="$internalHostname" label="Copy internal hostname" />
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">Pending</span>
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="text-neutral-500 dark:text-fg-dim">Last deployment</dt>
            <dd class="mt-1">
                @if ($latestDeployment)
                    <a class="underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                        title="{{ $latestDeployment->created_at }}" {{ wireNavigate() }}
                        href="{{ route('project.cluster-application.deployment.show', [...$routeParameters, 'deployment_uuid' => $latestDeployment->uuid]) }}">
                        {{ $latestDeployment->created_at->diffForHumans() }}
                    </a>
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">Never</span>
                @endif
            </dd>
        </div>
    </dl>
</x-application.settings-section>

<form wire:submit="saveConfiguration" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveConfiguration" targets="startCommand" />
    @endcan

    <x-application.settings-section id="cluster-application-runtime" title="Runtime"
        helper="Changes create a new revision. Redeploy the application to apply them.">
        <x-forms.input id="startCommand" label="Start command" placeholder="nginx -g &quot;daemon off;&quot;"
            canGate="update" :canResource="$workload"
            helper="Overrides the image command. Use double quotes for arguments with spaces. Leave empty to use the image default." />
    </x-application.settings-section>
</form>

<form wire:submit="saveDomains" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveDomains" targets="domains,httpPort" />
    @endcan

    <x-application.settings-section id="cluster-application-domains" title="Domains"
        helper="Ingress servers of the cluster route HTTP traffic for these domains to this application. Changes apply without a redeploy.">
        <div class="flex flex-col gap-4">
            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
                <x-forms.textarea id="domains" label="Domains" rows="3" placeholder="app.example.com"
                    canGate="update" :canResource="$workload"
                    helper="One domain per line, or separated by commas. Use the hostname only, without http://, a path, or a port. At most 20 domains." />
                <x-forms.input id="httpPort" type="number" min="1" max="65535" label="Port" placeholder="80"
                    canGate="update" :canResource="$workload"
                    helper="The port inside the container that receives HTTP traffic. Required when you set domains." />
            </div>

            @if ($publicUrls !== [])
                <div class="text-sm">
                    <div class="text-neutral-500 dark:text-fg-dim">URLs</div>
                    <ul class="mt-1 flex flex-col gap-1">
                        @foreach ($publicUrls as $publicUrl)
                            <li wire:key="cluster-application-url-{{ $publicUrl }}">
                                <a class="font-mono text-xs underline decoration-neutral-400 underline-offset-2 dark:decoration-neutral-600"
                                    href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer">{{ $publicUrl }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

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
                <div class="text-sm">
                    <div class="text-neutral-500 dark:text-fg-dim">
                        Point an A record for each domain to one or more of these addresses.
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                        @foreach ($ingressAddresses as $ingressAddress)
                            <span wire:key="cluster-application-ingress-{{ $ingressAddress }}"
                                class="flex items-center gap-1.5">
                                <span class="font-mono text-xs">{{ $ingressAddress }}</span>
                                <x-copy-button :value="$ingressAddress" label="Copy address" />
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">HTTPS is not available yet. Ingress serves
                these domains over HTTP on port 80.</p>
        </div>
    </x-application.settings-section>
</form>
