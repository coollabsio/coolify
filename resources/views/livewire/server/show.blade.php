<div x-data
    x-init="@if ($server->hetzner_server_id && $server->cloudProviderToken && !$hetznerServerStatus) $wire.checkHetznerServerStatus(); @endif @if ($server->vultr_instance_id && $server->cloudProviderToken) $wire.checkVultrInstanceStatus(); @endif @if ($server->digitalocean_droplet_id && $server->cloudProviderToken && !$digitalOceanDropletStatus) $wire.checkDigitalOceanDropletStatus(); @endif">
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(24) }} | Server | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="general" />
        <div class="w-full min-w-0">
            @if ($server->isLocalhost())
                @include('livewire.server.partials.localhost-general')
            @else
                @php
                    $provider = match (true) {
                        filled($server->hetzner_server_id) => 'Hetzner',
                        filled($server->digitalocean_droplet_id) => 'DigitalOcean',
                        filled($server->vultr_instance_id) => 'Vultr',
                        default => null,
                    };
                    $providerStatus = match ($provider) {
                        'Hetzner' => $hetznerServerStatus,
                        'DigitalOcean' => $digitalOceanDropletStatus,
                        'Vultr' => $vultrInstanceStatus,
                        default => null,
                    };
                    $providerStatusType = match (true) {
                        in_array($providerStatus, ['running', 'active']) => 'success',
                        in_array($providerStatus, ['starting', 'initializing', 'pending', 'new']) => 'warning',
                        in_array($providerStatus, ['off', 'stopped', 'suspended', 'archive', 'deleted']) => 'error',
                        default => 'neutral',
                    };
                    $hasLinkableCloudProviders = (!$server->hetzner_server_id && $availableHetznerTokens->isNotEmpty())
                        || (!$server->vultr_instance_id && $availableVultrTokens->isNotEmpty())
                        || (!$server->digitalocean_droplet_id && $availableDigitalOceanTokens->isNotEmpty());
                    $hetznerMatch = $matchedHetznerServer
                        ? [
                            'name' => $matchedHetznerServer['name'] ?? 'Hetzner server',
                            'id' => $matchedHetznerServer['id'] ?? null,
                            'status' => $matchedHetznerServer['status'] ?? null,
                        ]
                        : null;
                    $digitalOceanMatch = $matchedDigitalOceanDroplet
                        ? [
                            'name' => $matchedDigitalOceanDroplet['name'] ?? 'DigitalOcean Droplet',
                            'id' => $matchedDigitalOceanDroplet['id'] ?? null,
                            'status' => $matchedDigitalOceanDroplet['status'] ?? null,
                        ]
                        : null;
                    $vultrMatch = $matchedVultrInstance
                        ? [
                            'name' => $matchedVultrInstance['label'] ?? $matchedVultrInstance['hostname'] ?? 'Vultr instance',
                            'id' => $matchedVultrInstance['id'] ?? null,
                            'status' => $matchedVultrInstance['status'] ?? null,
                        ]
                        : null;
                @endphp

                <form wire:submit.prevent="submit" class="application-settings-form flex flex-col gap-6">
                    {{-- isBuildServer uses instantSave; keep dirty tracking on explicit-save fields. --}}
                    <x-unsaved-bar action="submit"
                        targets="name,description,ip,user,port,connectionTimeout,serverTimezone,wildcardDomain" />

                    <x-application.settings-section id="server-overview-section" title="Server overview"
                        helper="Connection health, provider state, operating system, and hardware details.">
                        <x-slot:actions>
                            @if ($provider)
                                <x-status-badge :label="$provider . ($providerStatus ? ' · ' . ucfirst($providerStatus) : '')"
                                    :type="$providerStatusType" />
                                @if ($provider === 'Hetzner')
                                    <x-forms.button type="button" class="size-8! px-0!"
                                        wire:click.prevent="checkHetznerServerStatus(true)"
                                        title="Refresh provider status">
                                        <x-reicon name="refresh" class="size-3.5" />
                                    </x-forms.button>
                                @elseif ($provider === 'DigitalOcean')
                                    <x-forms.button type="button" class="size-8! px-0!"
                                        wire:click.prevent="checkDigitalOceanDropletStatus(true)"
                                        title="Refresh provider status">
                                        <x-reicon name="refresh" class="size-3.5" />
                                    </x-forms.button>
                                @elseif ($provider === 'Vultr')
                                    <x-forms.button type="button" class="size-8! px-0!"
                                        wire:click.prevent="checkVultrInstanceStatus(true)"
                                        title="Refresh provider status">
                                        <x-reicon name="refresh" class="size-3.5" />
                                    </x-forms.button>
                                @endif
                            @endif
                            @if ($server->server_metadata)
                                <x-forms.button type="button" class="size-8! px-0!"
                                    wire:click="refreshServerMetadata" title="Refresh server details">
                                    <x-reicon name="refresh" class="size-3.5" />
                                </x-forms.button>
                            @endif
                            @if ($server->isTransferredAway())
                                <x-status-badge label="Transferred away" type="warning" />
                            @else
                                <x-status-badge :label="$server->isFunctional() ? 'Ready' : 'Validation required'"
                                    :type="$server->isFunctional() ? 'success' : 'warning'" />
                            @endif
                        </x-slot:actions>

                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4.5" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-neutral-950 dark:text-fg">
                                    {{ $server->name }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    @if ($server->isTransferredAway())
                                        This server was migrated away from this Coolify instance and cannot be managed here.
                                    @elseif ($server->isFunctional())
                                        The server is reachable, validated, and ready to host resources.
                                    @else
                                        Validate the SSH connection before using this server.
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($server->server_metadata)
                            @include('livewire.server.partials.server-details', ['server' => $server])
                        @else
                            <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button type="button" wire:click="refreshServerMetadata">
                                    <x-reicon name="refresh" class="size-3.5" />
                                    Fetch server details
                                </x-forms.button>
                            </div>
                        @endif
                    </x-application.settings-section>

                    <x-application.settings-section id="server-connection-section" title="Connection"
                        helper="Configure how Coolify identifies, reaches, and validates this server.">
                        <x-slot:actions>
                            @if ($hasLinkableCloudProviders)
                                <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                    <button type="button" class="button" @click="open = !open">
                                        <x-reicon name="plus" class="size-3.5" />
                                        Link provider
                                    </button>
                                    <div x-cloak x-show="open" x-transition.origin.top.right
                                        class="absolute top-9 right-0 z-50 w-56 rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                                        @if (!$server->hetzner_server_id && $availableHetznerTokens->isNotEmpty())
                                            <x-server.provider-link-modal :server="$server" provider="hetzner"
                                                providerLabel="Hetzner" tokenModel="selectedHetznerTokenId"
                                                :tokens="$availableHetznerTokens" manualModel="manualHetznerServerId"
                                                manualLabel="Server ID" manualPlaceholder="12345678"
                                                searchByIdMethod="searchHetznerServerById"
                                                searchByIpMethod="searchHetznerServer" linkMethod="linkToHetzner"
                                                :searchError="$hetznerSearchError" :noMatch="$hetznerNoMatchFound"
                                                :matched="$hetznerMatch" />
                                        @endif
                                        @if (!$server->digitalocean_droplet_id && $availableDigitalOceanTokens->isNotEmpty())
                                            <x-server.provider-link-modal :server="$server" provider="digitalocean"
                                                providerLabel="DigitalOcean"
                                                tokenModel="selectedDigitalOceanTokenId"
                                                :tokens="$availableDigitalOceanTokens"
                                                manualModel="manualDigitalOceanDropletId"
                                                manualLabel="Droplet ID" manualPlaceholder="12345678"
                                                searchByIdMethod="searchDigitalOceanDropletById"
                                                searchByIpMethod="searchDigitalOceanDroplet"
                                                linkMethod="linkToDigitalOcean"
                                                :searchError="$digitalOceanSearchError"
                                                :noMatch="$digitalOceanNoMatchFound" :matched="$digitalOceanMatch" />
                                        @endif
                                        @if (!$server->vultr_instance_id && $availableVultrTokens->isNotEmpty())
                                            <x-server.provider-link-modal :server="$server" provider="vultr"
                                                providerLabel="Vultr" tokenModel="selectedVultrTokenId"
                                                :tokens="$availableVultrTokens" manualModel="manualVultrInstanceId"
                                                manualLabel="Instance ID" manualPlaceholder="6d4b…"
                                                searchByIdMethod="searchVultrInstanceById"
                                                searchByIpMethod="searchVultrInstance" linkMethod="linkToVultr"
                                                :searchError="$vultrSearchError" :noMatch="$vultrNoMatchFound"
                                                :matched="$vultrMatch" />
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <x-process-dialog closeWithX mobileFullscreen size="xl" :open="$isValidating">
                                <x-slot:title>Validate and configure</x-slot:title>
                                <x-slot:content>
                                    <livewire:server.validate-and-install :server="$server"
                                        :ask="$server->isFunctional() && ! $isValidating" />
                                </x-slot:content>
                                <x-forms.button type="button" :isHighlighted="! $server->isFunctional()"
                                    @click="processDialogOpen = true" wire:click.prevent="validateServer">
                                    <x-reicon :name="$server->isFunctional() ? 'refresh' : 'alert-circle'" class="size-3.5" />
                                    {{ $server->isFunctional() ? 'Revalidate connection' : 'Validate connection' }}
                                </x-forms.button>
                            </x-process-dialog>
                        </x-slot:actions>

                        @if ($server->isTransferredAway())
                            <x-callout type="warning" title="Transferred to another instance" class="mb-4">
                                This server was migrated away from this Coolify instance. It cannot be revalidated or
                                managed here. Use the target instance, or delete this server when you no longer need the
                                archive.
                            </x-callout>
                        @endif

                        @if ($this->limaStartCommand)
                            <x-callout type="info" title="Start this Lima VM locally" class="mb-4">
                                <code
                                    class="mt-2 block overflow-x-auto rounded-lg bg-neutral-950 px-3 py-2 font-mono text-[11px] text-neutral-200">{{ $this->limaStartCommand }}</code>
                            </x-callout>
                        @endif

                        @if ($server->isForceDisabled() && isCloud())
                            <x-callout type="danger" title="Server disabled" class="mb-4">
                                This server is disabled because the current plan server limit was exceeded.
                            </x-callout>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input canGate="update" :canResource="$server" id="name" label="Name"
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" id="description"
                                label="Description" :disabled="$isValidating" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="password" id="ip"
                                label="IP address or domain"
                                helper="Enter a hostname or IP address without http:// or https://."
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" id="user" label="SSH user"
                                required :disabled="$isValidating" />
                            <x-forms.input canGate="update" :canResource="$server" type="number" id="port"
                                label="SSH port" required :disabled="$isValidating" />
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-3">
                            <x-forms.input canGate="update" :canResource="$server" type="number"
                                id="connectionTimeout" label="Connection timeout"
                                helper="Seconds to wait before an SSH connection fails." min="1" max="300"
                                required :disabled="$isValidating" />
                            <x-forms.searchable-listbox id="serverTimezone" label="Server timezone"
                                helper="Used for backups, cron jobs, and displayed timestamps."
                                searchPlaceholder="Search timezones" emptyText="No matching timezone"
                                :options="collect($this->timezones)->map(fn ($timezone) => [
                                    'value' => $timezone,
                                    'label' => $timezone,
                                ])->all()" :disabled="$isValidating || !auth()->user()->can('update', $server)" />
                            @if (!$isSwarmWorker && !$isBuildServer)
                                <x-forms.input canGate="update" :canResource="$server"
                                    placeholder="https://example.com" id="wildcardDomain" label="Wildcard domain"
                                    helper="New resources can receive generated subdomains from this domain."
                                    :disabled="$isValidating" />
                            @endif
                        </div>

                        @if (!$server->isLocalhost())
                            <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                @if ($isBuildServerLocked)
                                    <x-forms.checkbox disabled id="isBuildServer"
                                        helper="This server already hosts resources and cannot become build-only."
                                        label="Use as a dedicated build server" />
                                @else
                                    <x-forms.checkbox canGate="update" :canResource="$server" instantSave
                                        id="isBuildServer" label="Use as a dedicated build server"
                                        helper="Build servers compile applications but do not host deployments. Enabling this makes the server build-only."
                                        :disabled="$isValidating" />
                                @endif
                            </div>
                        @endif
                    </x-application.settings-section>

                    @if ($server->validation_logs)
                        <x-application.settings-section title="Previous validation output"
                            helper="The latest output produced while checking this server.">
                            <div
                                class="max-h-72 overflow-auto rounded-lg bg-neutral-950 p-4 font-mono text-xs leading-5 text-neutral-300">
                                {!! $server->validation_logs !!}
                            </div>
                        </x-application.settings-section>
                    @endif
                </form>
            @endif
        </div>
    </div>
</div>
