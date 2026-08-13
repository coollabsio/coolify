<div>
    <x-slot:title>
        Migrations | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-application.settings-section title="Migrations">
                <div class="flex min-w-0 flex-col gap-6">
                    <p class="text-sm text-neutral-500">
                        Move resources between servers, or migrate this entire Coolify instance to a new VM.
                    </p>

                    <div class="flex flex-wrap gap-2">
                <x-forms.button wire:click="setMode('resources')"
                    class="{{ $mode === 'resources' ? 'dark:bg-coolgray-200' : '' }}">
                    Resource copy
                </x-forms.button>
                <x-forms.button wire:click="setMode('instance')"
                    class="{{ $mode === 'instance' ? 'dark:bg-coolgray-200' : '' }}">
                    Instance migration
                </x-forms.button>
            </div>

            @if ($mode === 'resources')
                <div class="flex flex-wrap items-center gap-2">
                    <x-forms.button isHighlighted wire:click="startMigration"
                        wire:loading.attr="disabled" wire:target="startMigration"
                        :disabled="$targetBlockReason !== '' || $phase === 'running'">
                        <span wire:loading.remove wire:target="startMigration">Start Migration</span>
                        <span wire:loading wire:target="startMigration">Migrating…</span>
                    </x-forms.button>
                </div>

                @if ($phase === 'running')
                    <x-callout type="info" title="Migration in progress">
                        Copying selected resources to the target server. Keep this page open until it finishes.
                    </x-callout>
                @endif

                <x-callout type="info" title="How it works">
                    Choose a source server and a target server. Coolify copies the selected resources onto the target.
                    Source resources are left running. Enable volume copy to also transfer database and persistent
                    storage data.
                </x-callout>

                <x-callout type="warning" title="Do not install Coolify on the target">
                    Add the VM under Servers and validate it from this Coolify. That installs Docker and
                    <code>coolify-proxy</code>, which binds ports 80/443 — that is expected. Migrated apps use that
                    proxy
                    for domains; they do not need host port 80 free. Do not run the Coolify installer on the target
                    (no second dashboard / <code>coolify-db</code> / port 8000).
                </x-callout>

                @if ($servers === [])
                    <x-callout type="warning" title="No servers">
                        Add a server first under Servers, then come back to migrate resources between servers.
                    </x-callout>
                @endif

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-forms.select required id="sourceServerUuid" wire:model.live="sourceServerUuid"
                        label="Source server" helper="The server you are copying resources from.">
                        <option value="">Select source server</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server['uuid'] }}">{{ $server['name'] }} ({{ $server['ip'] }})</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.select required id="targetServerUuid" wire:model.live="targetServerUuid"
                        label="Target server"
                        helper="A server already added to this Coolify instance. Do not install Coolify on it.">
                        <option value="">Select target server</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server['uuid'] }}" @disabled($server['uuid'] === $sourceServerUuid)>
                                {{ $server['name'] }} ({{ $server['ip'] }})
                            </option>
                        @endforeach
                    </x-forms.select>
                </div>

                @if ($targetBlockReason === 'independent_coolify')
                    <x-callout type="danger" title="Coolify is already installed on the target">
                        This host is running its own Coolify dashboard. Pick a different server, or add a new VPS under
                        Servers without running the Coolify installer on it.
                    </x-callout>
                @elseif ($targetBlockReason === 'not_ready')
                    <x-callout type="warning" title="Target server is not ready">
                        The target is not reachable or not validated. Open Servers, validate it, then try again.
                    </x-callout>
                @elseif ($targetBlockReason === 'cannot_host')
                    <x-callout type="warning" title="Target cannot host resources">
                        Build servers cannot be used as a migration target.
                    </x-callout>
                @elseif ($targetBlockReason === 'no_destination')
                    <x-callout type="warning" title="No Docker destination">
                        The target server has no Docker destination. Validate the server first.
                    </x-callout>
                @endif

                <div class="md:w-96">
                    <x-forms.checkbox id="cloneVolumeData" label="Copy persistent volume data"
                        helper="Stops source resources briefly, copies Docker volumes to the target, then restarts the source." />
                </div>

                @if ($discoveredResources !== [])
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <h4>Resources on source</h4>
                            <x-forms.button wire:click="toggleSelectAll">
                                {{ count($selectedResourceUuids) === count($discoveredResources) ? 'Clear all' : 'Select all' }}
                            </x-forms.button>
                        </div>
                        <div class="overflow-x-auto border rounded-lg dark:border-coolgray-200">
                            <table class="w-full text-sm">
                                <thead class="text-left dark:bg-coolgray-100">
                                    <tr>
                                        <th class="p-2"></th>
                                        <th class="p-2">Name</th>
                                        <th class="p-2">Type</th>
                                        <th class="p-2">Volumes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($discoveredResources as $resource)
                                        <tr wire:key="resource-{{ $resource['uuid'] }}"
                                            class="border-t dark:border-coolgray-200">
                                            <td class="p-2">
                                                <input type="checkbox" class="rounded"
                                                    wire:model="selectedResourceUuids"
                                                    value="{{ $resource['uuid'] }}" />
                                            </td>
                                            <td class="p-2">{{ $resource['name'] ?? $resource['uuid'] }}</td>
                                            <td class="p-2">{{ $resource['type'] ?? 'unknown' }}</td>
                                            <td class="p-2">{{ $resource['volume_count'] ?? 0 }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @elseif ($sourceServerUuid !== '')
                    <div class="text-sm text-neutral-500">No resources found on the source server.</div>
                @endif

                @if ($phase !== 'idle' && $items !== [])
                    <div class="flex flex-col gap-2">
                        <h4>Result</h4>
                        <div class="text-sm">
                            Migrated: {{ $migratedCount }}. Failed: {{ $failedCount }}. Skipped:
                            {{ $skippedCount }}.
                        </div>
                        <div class="overflow-x-auto border rounded-lg dark:border-coolgray-200">
                            <table class="w-full text-sm">
                                <thead class="text-left dark:bg-coolgray-100">
                                    <tr>
                                        <th class="p-2">Resource</th>
                                        <th class="p-2">Status</th>
                                        <th class="p-2">Error</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $item)
                                        <tr wire:key="item-{{ $item['name'] }}-{{ $loop->index }}"
                                            class="border-t dark:border-coolgray-200">
                                            <td class="p-2">{{ $item['name'] }}</td>
                                            <td class="p-2">{{ $item['status'] }}</td>
                                            <td class="p-2">{{ $item['error'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @else
                <div @if ($instanceMigrationRunning) wire:poll.2s="refreshInstanceMigration" @endif
                    class="flex flex-col gap-6">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-forms.button isHighlighted wire:click="startInstanceMigration"
                            wire:loading.attr="disabled" wire:target="startInstanceMigration"
                            :disabled="$instanceMigrationRunning">
                            <span wire:loading.remove wire:target="startInstanceMigration">
                                {{ $instanceDryRun ? 'Run Dry Run' : 'Start Instance Migration' }}
                            </span>
                            <span wire:loading wire:target="startInstanceMigration">Queuing…</span>
                        </x-forms.button>
                        @if ($instanceMigrationRunning)
                            <div class="text-sm text-neutral-500">Updating every 2 seconds…</div>
                        @endif
                    </div>

                    <x-callout type="info" title="Full instance migration">
                        This installs Coolify on the target VM, immediately restores this dashboard into
                        <code>coolify-db</code> (database + APP_KEY + SSH keys), copies persistent volumes from every
                        managed server onto that VM, and points all resources at localhost on the new host. Source
                        servers are left running until you cut over DNS.
                    </x-callout>

                    <x-callout type="warning" title="Use a fresh VM">
                        Prefer a new VPS. If a previous run already installed Coolify but left an empty dashboard, start
                        the migration again — the installer is skipped and the source <code>coolify-db</code> is restored
                        into it. Ports 80/443/8000 will be used by the new Coolify dashboard and proxy.
                    </x-callout>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-forms.input required id="instanceTargetIp" label="Target IP / hostname"
                            helper="SSH address of the new VM." :disabled="$instanceMigrationRunning" />
                        <x-forms.input required id="instanceTargetUser" label="SSH user"
                            :disabled="$instanceMigrationRunning" />
                        <x-forms.input required id="instanceTargetPort" type="number" label="SSH port"
                            :disabled="$instanceMigrationRunning" />
                        <x-forms.select required id="instancePrivateKeyId" label="SSH private key"
                            helper="Key that can log in to the target VM as the SSH user."
                            :disabled="$instanceMigrationRunning">
                            <option value="">Select private key</option>
                            @foreach ($privateKeys as $key)
                                <option value="{{ $key['id'] }}">{{ $key['name'] }}</option>
                            @endforeach
                        </x-forms.select>
                    </div>

                    <div class="md:w-96">
                        <x-forms.checkbox id="instanceDryRun" label="Dry run only"
                            helper="Validate SSH connectivity and that the target does not already have Coolify."
                            :disabled="$instanceMigrationRunning" />
                    </div>

                    @if ($instanceStatus !== '')
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-end justify-between gap-2">
                                <div>
                                    <h4>Instance migration progress</h4>
                                    <div class="text-sm text-neutral-500">
                                        Status:
                                        {{ \App\Enums\InstanceMigrationStatus::tryFrom($instanceStatus)?->label() ?? $instanceStatus }}
                                    </div>
                                </div>
                                <div class="text-sm font-medium">{{ $instanceProgress }}%</div>
                            </div>

                            <div class="w-full h-2 overflow-hidden rounded bg-neutral-200 dark:bg-coolgray-200">
                                <div class="h-full transition-all duration-500
                                    {{ $instanceStatus === 'failed' ? 'bg-red-500' : ($instanceStatus === 'completed' ? 'bg-green-500' : 'bg-coollabs') }}"
                                    style="width: {{ $instanceProgress }}%"></div>
                            </div>

                            @if ($instanceSteps !== [])
                                <ol class="flex flex-col gap-2">
                                    @foreach ($instanceSteps as $step)
                                        <li wire:key="step-{{ $step['status'] }}"
                                            class="flex items-start gap-3 rounded-lg border p-3 dark:border-coolgray-200
                                            {{ $step['state'] === 'active' ? 'border-coollabs dark:border-coollabs' : '' }}
                                            {{ $step['state'] === 'failed' ? 'border-red-500/50' : '' }}">
                                            <div class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs
                                                {{ $step['state'] === 'done' ? 'bg-green-500 text-white' : '' }}
                                                {{ $step['state'] === 'active' ? 'bg-coollabs text-white' : '' }}
                                                {{ $step['state'] === 'failed' ? 'bg-red-500 text-white' : '' }}
                                                {{ $step['state'] === 'pending' ? 'bg-neutral-200 text-neutral-500 dark:bg-coolgray-200' : '' }}">
                                                @if ($step['state'] === 'done')
                                                    ✓
                                                @elseif ($step['state'] === 'failed')
                                                    !
                                                @elseif ($step['state'] === 'active')
                                                    …
                                                @else
                                                    {{ $loop->iteration }}
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="font-medium">{{ $step['label'] }}</div>
                                                @if (! empty($step['note']))
                                                    <div class="text-sm text-neutral-500">{{ $step['note'] }}</div>
                                                @elseif ($step['state'] === 'active')
                                                    <div class="text-sm text-neutral-500">In progress…</div>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif

                            @if ($instanceDashboardUrl !== '')
                                <div class="text-sm">Dashboard: <a class="underline"
                                        href="{{ $instanceDashboardUrl }}" target="_blank"
                                        rel="noopener">{{ $instanceDashboardUrl }}</a>
                                </div>
                            @endif
                            @if ($instanceError !== '')
                                <div class="text-sm text-red-500 break-words">{{ $instanceError }}</div>
                            @endif

                            @if ($items !== [])
                                <div class="overflow-x-auto border rounded-lg dark:border-coolgray-200">
                                    <table class="w-full text-sm">
                                        <thead class="text-left dark:bg-coolgray-100">
                                            <tr>
                                                <th class="p-2">Resource</th>
                                                <th class="p-2">Status</th>
                                                <th class="p-2">Error</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($items as $item)
                                                <tr wire:key="instance-item-{{ $item['name'] ?? $loop->index }}"
                                                    class="border-t dark:border-coolgray-200">
                                                    <td class="p-2">{{ $item['name'] ?? '' }}</td>
                                                    <td class="p-2">{{ $item['status'] ?? '' }}</td>
                                                    <td class="p-2">{{ $item['error'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
                </div>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
