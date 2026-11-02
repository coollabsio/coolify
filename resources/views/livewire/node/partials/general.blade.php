@php
    $metadata = $node->metadata ?? [];
    $runtime = data_get($metadata, 'container_runtime');
    $overviewDetails = [
        'Hostname' => ['value' => data_get($metadata, 'hostname', 'Unknown')],
        'Operating system' => ['value' => data_get($metadata, 'os', 'Unknown')],
        'Architecture' => ['value' => data_get($metadata, 'arch', 'Unknown')],
        'Kernel' => ['value' => data_get($metadata, 'kernel', 'Unknown')],
        'CPU cores' => ['value' => data_get($metadata, 'cpus', 'Unknown')],
        'Memory' => ['value' => isset($metadata['memory_bytes']) ? round($metadata['memory_bytes'] / 1073741824, 1).' GB' : 'Unknown'],
        'Container runtime' => [
            'value' => $runtime
                ? ucfirst($runtime).(data_get($metadata, 'container_runtime_version') ? ' '.data_get($metadata, 'container_runtime_version') : '')
                : 'Unknown',
        ],
        'Sentinel version' => ['value' => data_get($metadata, 'sentinel_version', 'Unknown')],
        'Up since' => ['value' => data_get($metadata, 'uptime_since', 'Unknown')],
    ];
@endphp

<x-application.settings-section id="node-overview-section" title="Server overview"
    helper="Connection details and host information that Sentinel reports.">
    <x-slot:actions>
        @can('manageSentinel', $node)
            <x-forms.button type="button" wire:click="refreshInformation"
                title="Refresh server information">
                <x-reicon name="refresh" class="size-3.5" />
                Refresh details
            </x-forms.button>
        @endcan
    </x-slot:actions>

    @if (! $node->is_usable && filled($node->validation_logs))
        <x-callout type="warning" title="Server is not ready" class="mb-4">
            {{ $node->validation_logs }}
        </x-callout>
    @endif

    <div class="flex items-start gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
            <x-reicon name="servers" class="size-4.5" />
        </div>
        <div>
            <p class="text-sm font-medium text-neutral-950 dark:text-fg">{{ $node->name }}</p>
            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                {{ $node->is_usable ? 'The server is reachable, validated, and ready to host resources.' : 'Validate the connection before using this server.' }}
            </p>
        </div>
    </div>

    <dl class="mt-4 grid gap-x-6 gap-y-5 border-t border-neutral-200 pt-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-white/[0.08]">
        @foreach ($overviewDetails as $detailLabel => $detail)
            <div class="min-w-0">
                <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $detailLabel }}</dt>
                <dd @class([
                    'mt-1 flex min-w-0 items-center gap-1.5 text-sm font-medium text-neutral-950 dark:text-fg',
                    'font-mono text-[13px]' => $detail['mono'] ?? false,
                ])>
                    @if ($detail['hidden'] ?? false)
                        {{-- Addresses stay hidden until the user shows them; copy works without showing. --}}
                        <span x-data="{ shown: false }" class="flex min-w-0 items-center gap-1.5">
                            <span x-show="shown" x-cloak class="truncate">{{ $detail['value'] }}</span>
                            <span x-show="!shown" class="truncate tracking-widest text-neutral-400 dark:text-fg-faint" aria-hidden="true">••••••••</span>
                            <button type="button" class="icon-button size-6!" x-on:click="shown = !shown"
                                x-bind:aria-label="shown ? 'Hide {{ strtolower($detailLabel) }}' : 'Show {{ strtolower($detailLabel) }}'"
                                x-bind:title="shown ? 'Hide' : 'Show'">
                                <x-reicon name="eye" x-show="!shown" class="size-3.5" />
                                <x-reicon name="eye-off2" x-show="shown" x-cloak class="size-3.5" />
                            </button>
                        </span>
                    @else
                        <span class="truncate">{{ $detail['value'] }}</span>
                    @endif
                    @if ($detail['copy'] ?? false)
                        <x-copy-button :value="$detail['value']" :label="'Copy '.strtolower($detailLabel)" />
                    @endif
                </dd>
            </div>
        @endforeach
        <div>
            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Cluster</dt>
            <dd class="mt-1 text-sm font-medium text-neutral-950 dark:text-fg">
                @if ($node->cluster)
                    <a href="{{ route('node-cluster.show', ['cluster_uuid' => $node->cluster->uuid]) }}" {{ wireNavigate() }}>
                        {{ $node->cluster->name }}
                    </a>
                @else
                    <span class="text-neutral-500 dark:text-fg-dim">Not assigned</span>
                @endif
            </dd>
        </div>
    </dl>
</x-application.settings-section>

<form wire:submit="saveConnection" class="application-settings-form">
<x-unsaved-bar action="saveConnection" />
<x-application.settings-section id="node-connection-section" title="Connection"
    helper="How Coolify identifies and connects to this server.">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.input id="connection.name" label="Name" canGate="update" :canResource="$node" required />
        <x-forms.input id="connection.description" label="Description" canGate="update" :canResource="$node" />
    </div>
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <x-forms.input id="connection.ip" label="IP address or domain" type="password" canGate="update" :canResource="$node" required />
        <x-forms.input id="connection.user" label="SSH user" canGate="update" :canResource="$node" required />
        <x-forms.input id="connection.port" label="SSH port" type="number" min="1" max="65535" canGate="update" :canResource="$node" required />
    </div>
    @if (filled($node->wireguard_ip))
        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <x-forms.input id="node-connection-private-ip" label="Private IP" type="password" :value="$node->wireguard_ip" readonly />
        </div>
    @endif
    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <x-forms.input id="connection.settings.connection_timeout" label="Connection timeout" type="number" min="1" max="300" required
            helper="Seconds to wait before an SSH connection fails." canGate="update" :canResource="$node" />
        <x-forms.listbox id="connection.settings.server_timezone" label="Server timezone" searchable
            :options="collect(timezone_identifiers_list())->map(fn ($timezone) => ['value' => $timezone, 'label' => $timezone])->all()"
            canGate="update" :canResource="$node" />
        <x-forms.input id="connection.settings.wildcard_domain" label="Wildcard domain" placeholder="https://example.com"
            helper="New resources can receive generated subdomains from this domain." canGate="update" :canResource="$node" />
    </div>
</x-application.settings-section>
</form>
