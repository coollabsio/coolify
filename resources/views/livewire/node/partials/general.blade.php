@php
    $metadata = $node->metadata ?? [];
    $runtime = data_get($metadata, 'container_runtime');
    $overviewDetails = [
        'Public IP' => ['value' => $node->ip, 'mono' => true, 'copy' => true, 'hidden' => true],
    ];
    if (filled($node->wireguard_ip)) {
        $overviewDetails['Private IP'] = ['value' => $node->wireguard_ip, 'mono' => true, 'copy' => true, 'hidden' => true];
    }
    $overviewDetails += [
        'Hostname' => ['value' => data_get($metadata, 'hostname', 'Unknown')],
        'Operating system' => ['value' => data_get($metadata, 'os', 'Unknown')],
        'Kernel' => ['value' => data_get($metadata, 'kernel', 'Unknown')],
        'Architecture' => ['value' => data_get($metadata, 'arch', 'Unknown')],
        'CPU cores' => ['value' => data_get($metadata, 'cpus', 'Unknown')],
        'Container runtime' => [
            'value' => $runtime
                ? ucfirst($runtime).(data_get($metadata, 'container_runtime_version') ? ' '.data_get($metadata, 'container_runtime_version') : '')
                : 'Unknown',
        ],
    ];

    $memoryTotal = (int) data_get($metadata, 'memory_bytes', 0);
    $memoryUsed = (int) data_get($metadata, 'memory_used_bytes', 0);
    $diskTotal = (int) data_get($metadata, 'disk_total_bytes', 0);
    $diskUsed = $diskTotal - (int) data_get($metadata, 'disk_available_bytes', 0);
    $cpuPercent = is_numeric(data_get($metadata, 'cpu_usage_percent')) ? (float) data_get($metadata, 'cpu_usage_percent') : null;
    $memoryPercent = $memoryTotal > 0 ? round(($memoryUsed / $memoryTotal) * 100, 1) : null;
    $diskPercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : null;
    $usageRows = [
        [
            'label' => 'CPU usage',
            'percent' => $cpuPercent,
            'display' => $cpuPercent !== null ? number_format($cpuPercent, 1).'%' : null,
            'detail' => is_numeric(data_get($metadata, 'cpus')) ? data_get($metadata, 'cpus').' cores' : null,
            'limit' => $node->cluster?->cpu_pressure_threshold ?? 95,
        ],
        [
            'label' => 'Memory usage',
            'percent' => $memoryPercent,
            'display' => $memoryPercent !== null ? $memoryPercent.'%' : null,
            'detail' => $memoryPercent !== null ? formatBytes($memoryUsed).' of '.formatBytes($memoryTotal) : null,
            'limit' => $node->cluster?->memory_pressure_threshold ?? 90,
        ],
        [
            'label' => 'Disk usage',
            'percent' => $diskPercent,
            'display' => $diskPercent !== null ? $diskPercent.'%' : null,
            'detail' => $diskPercent !== null ? formatBytes($diskUsed).' of '.formatBytes($diskTotal) : null,
            'limit' => $node->cluster?->disk_pressure_threshold ?? 90,
        ],
    ];
    $underPressure = collect($usageRows)->contains(fn (array $row): bool => $row['percent'] !== null && $row['percent'] >= $row['limit']);
    $loadAverage = is_numeric(data_get($metadata, 'load_average.one'))
        ? collect(data_get($metadata, 'load_average'))->map(fn ($value) => number_format((float) $value, 2))->implode(' / ')
        : null;
@endphp

<x-application.settings-section id="node-overview-section" title="Overview"
    helper="Connection details and host information that Sentinel reports.">
    <x-slot:actions>
        @can('manageSentinel', $node)
            <x-forms.button type="button" wire:click="refreshInformation"
                title="Refresh server information">
                <x-reicon name="refresh" class="size-3.5" />
                Refresh
            </x-forms.button>
        @endcan
    </x-slot:actions>

    @if (! $node->is_usable && filled($node->validation_logs))
        <x-callout type="warning" title="Server is not ready" class="mb-4">
            {{ $node->validation_logs }}
        </x-callout>
    @endif

    <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <dt class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Status</dt>
            <dd class="mt-1">
                <x-status-badge :status="$node->is_usable ? 'Ready' : 'Not ready'"
                    :type="$node->is_usable ? 'success' : 'warning'" />
            </dd>
        </div>
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
    </dl>
</x-application.settings-section>

<x-application.settings-section id="node-usage-section" title="Resource usage"
    helper="Latest CPU, memory, and disk usage that Sentinel reports, compared with the cluster deployment limits.">
    @if ($underPressure)
        <x-callout type="warning" title="Server pressure" class="mb-4">
            One or more server resources reached the cluster deployment limit.
        </x-callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($usageRows as $usage)
            @php($overLimit = $usage['percent'] !== null && $usage['percent'] >= $usage['limit'])
            <div class="min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs font-medium text-neutral-500 dark:text-fg-dim">{{ $usage['label'] }}</span>
                    <span class="text-sm font-semibold text-neutral-950 dark:text-fg">{{ $usage['display'] ?? 'Unknown' }}</span>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
                    <div @class([
                        'h-full rounded-full',
                        'bg-warning' => $overLimit,
                        'bg-coollabs dark:bg-warning/70' => ! $overLimit,
                    ]) style="width: {{ min(100, max(0, $usage['percent'] ?? 0)) }}%"></div>
                </div>
                <p class="mt-1.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                    {{ $usage['detail'] ?? 'Not reported yet' }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 flex items-center justify-between gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <span class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Load average (1 / 5 / 15 min)</span>
        <span class="font-mono text-[13px] text-neutral-950 dark:text-fg">{{ $loadAverage ?? 'Unknown' }}</span>
    </div>
</x-application.settings-section>
