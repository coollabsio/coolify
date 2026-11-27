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
            <x-forms.button type="button" wire:click="validateNode" :isHighlighted="! $node->is_usable"
                title="Check Podman and the host requirements, then update the server status">
                <x-reicon :name="$node->is_usable ? 'refresh' : 'alert-circle'" class="size-3.5" />
                {{ $node->is_usable ? 'Revalidate connection' : 'Validate connection' }}
            </x-forms.button>
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
<x-unsaved-bar action="saveConnection" targets="connection" />
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

<form wire:submit="saveImageCleanup" class="application-settings-form">
<x-unsaved-bar action="saveImageCleanup" targets="imageCleanup" />
<x-application.settings-section id="node-image-cleanup-section" title="Image cleanup"
    helper="Removes the images that no container uses. The deployed revision and the 2 revisions before it of each application, the last 3 builds of each application on a builder server, and the builder, BuildKit, and Sentinel images stay. Volumes, networks, and the build cache stay.">
    <x-slot:actions>
        @can('update', $node)
            <x-forms.button type="button" wire:click="cleanupImages" title="Clean up images now">
                Clean up now
            </x-forms.button>
        @endcan
    </x-slot:actions>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.input id="imageCleanup.frequency" label="Frequency" placeholder="0 0 * * *" required
            helper="A cron expression in the timezone of the server. The default is every day at 00:00." canGate="update" :canResource="$node" />
        <x-forms.input id="imageCleanup.threshold" label="Disk threshold (%)" type="number" min="1" max="100" required
            helper="A cleanup also starts when the disk use of the server is at or above this value." canGate="update" :canResource="$node" />
    </div>
    @isset($lastImageCleanup)
        @php
            $cleanupResult = $lastImageCleanup->result ?? [];
        @endphp
        <p class="mt-4 text-sm text-neutral-500 dark:text-fg-dim" wire:key="node-image-cleanup-{{ $lastImageCleanup->uuid }}">
            Last cleanup: {{ $lastImageCleanup->status->value }}, {{ $lastImageCleanup->created_at?->diffForHumans() }}.
            @if ($lastImageCleanup->status->value === 'succeeded')
                Freed {{ formatBytes((int) data_get($cleanupResult, 'freed_bytes', 0)) }},
                removed {{ (int) data_get($cleanupResult, 'removed_images_count', 0) }} images and {{ count(data_get($cleanupResult, 'removed_containers', [])) }} build containers,
                kept {{ (int) data_get($cleanupResult, 'kept_images', 0) }} images.
            @elseif (filled($lastImageCleanup->error))
                {{ $lastImageCleanup->error }}
            @endif
        </p>
    @endisset
</x-application.settings-section>
</form>

<form wire:submit="saveGpu" class="application-settings-form">
<x-application.settings-section id="node-gpu-section" title="GPU"
    helper="NVIDIA GPUs for cluster applications, through the CDI spec of the NVIDIA Container Toolkit. Coolify does not install the driver or the toolkit.">
    <x-slot:actions>
        @can('update', $node)
            <x-forms.button type="submit">Save</x-forms.button>
        @endcan
    </x-slot:actions>
    <x-forms.checkbox id="gpuEnabled" label="Allow GPU workloads" canGate="update" :canResource="$node"
        helper="Only a team admin can change this. A GPU gives a container device files and driver files of the server. Applications that ask for a GPU deploy only on servers that allow it." />
    @php
        $gpuCheck = data_get($node->metadata, 'gpu_cdi_check');
        $gpuDevices = (array) data_get($node->metadata, 'gpu_cdi_devices', []);
    @endphp
    <div class="mt-4 text-sm text-neutral-500 dark:text-fg-dim">
        @if ($gpuCheck === null)
            Sentinel on this server does not report GPUs yet. Upgrade Sentinel to see the CDI check.
        @else
            <p>CDI check: {{ $gpuCheck }}</p>
            @if ($gpuDevices !== [])
                <p class="mt-1 font-mono">{{ implode(', ', $gpuDevices) }}</p>
            @endif
        @endif
        <p class="mt-1">To use a GPU, install the NVIDIA driver and the NVIDIA Container Toolkit, then run
            <code>nvidia-ctk cdi generate --output=/etc/cdi/nvidia.yaml</code>. See the
            <a class="underline" href="https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/cdi-support.html" target="_blank" rel="noopener">NVIDIA CDI documentation</a>.</p>
    </div>
</x-application.settings-section>
</form>
