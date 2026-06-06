<div wire:init="refreshNetworksInBackground" class="flex flex-col gap-6">
    <div class="box-without-bg flex flex-col gap-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h3>Network Connections</h3>
                <div class="text-sm text-neutral-500">Configure Docker networks for this resource and reconcile them with the container's live runtime connections.</div>
            </div>
            <x-forms.button isHighlighted wire:click="showConnectNetworkForm" :disabled="$availableNetworks->isEmpty()">
                Connect network
            </x-forms.button>
        </div>

        <div wire:loading.flex wire:target="refreshNetworksInBackground" class="text-xs text-neutral-500">
            Refreshing networks...
        </div>

        @if ($refreshWarning)
            <x-callout type="warning" title="Refresh unavailable">
                {{ $refreshWarning }}
            </x-callout>
        @elseif ($availableNetworks->isEmpty())
            <div class="text-sm text-neutral-500">
                No Docker networks found for this server. Create or import one from Destinations.
            </div>
        @endif

        @if ($showConnectForm && ! $availableNetworks->isEmpty())
            <form wire:submit="connectNetwork" class="flex flex-col gap-4 rounded border border-neutral-800 p-4">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-forms.select id="selectedNetworkId" label="Network" :disabled="(bool) $editingAttachmentId" required>
                        <option value="">Select a network</option>
                        @foreach ($availableNetworks as $network)
                            <option value="{{ $network->id }}" @disabled(! $network->is_active || $network->is_system)>
                                {{ $network->display_name }}{{ $network->is_active ? '' : ' - inactive' }}{{ $network->is_system ? ' - system' : '' }}
                            </option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.input id="aliases" label="Aliases" placeholder="api, backend, backend-internal" />
                    <div class="flex flex-wrap items-center gap-6">
                        <x-forms.checkbox id="isPrimary" label="Primary" />
                        <x-forms.checkbox id="isRequired" label="Required" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-forms.button type="submit" isHighlighted>
                        {{ $editingAttachmentId ? 'Save connection' : 'Connect network' }}
                    </x-forms.button>
                    <x-forms.button wire:click="resetForm">Cancel</x-forms.button>
                </div>
            </form>
        @endif
    </div>

    <div class="box-without-bg flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3>Connected Networks</h3>
        </div>

        @if ($attachments->isEmpty())
            <div class="text-sm text-neutral-500">
                This resource is not connected to any additional Docker networks.
            </div>
        @else
            <div class="flex flex-col gap-3 lg:hidden">
                @foreach ($attachments as $attachment)
                    <div wire:key="network-connection-card-{{ $attachment->id }}" class="rounded border border-neutral-800 bg-neutral-900/40 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ $attachment->dockerNetwork?->display_name }}</div>
                                <div class="text-xs text-neutral-500">{{ $attachment->dockerNetwork?->docker_network_name }}</div>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    <x-status-badge :status="$this->roleLabel($attachment->dockerNetwork)" type="neutral" />
                                    <x-status-badge
                                        :status="$this->networkTypeLabel($attachment->dockerNetwork)"
                                        :type="$attachment->dockerNetwork?->managed_by_coolify ? 'success' : 'neutral'" />
                                    <x-status-badge
                                        :status="$this->attachmentModeLabel($attachment)"
                                        :type="$this->attachmentModeType($attachment)" />
                                    @if ($attachment->is_primary)
                                        <x-status-badge status="Primary" type="success" />
                                    @endif
                                    @if ($attachment->is_required)
                                        <x-status-badge status="Required" type="warning" />
                                    @endif
                                </div>
                            </div>
                            <x-status-badge
                                :status="$this->statusLabel($attachment)"
                                :type="$attachment->status?->value === 'attached' ? 'success' : 'neutral'" />
                        </div>

                        @if (count($attachment->aliases ?? []) > 0)
                            <div class="mt-3 text-sm text-neutral-300">{{ collect($attachment->aliases ?? [])->join(', ') }}</div>
                        @endif

                        @foreach ($this->warnings($attachment) as $warning)
                            <div class="mt-2 text-xs text-warning">{{ $warning }}</div>
                        @endforeach

                        @if ($attachment->last_error)
                            <div class="mt-3 text-sm text-error">{{ $attachment->last_error }}</div>
                        @endif

                        <div class="mt-4">
                            <x-network-attachment-actions :attachment="$attachment" />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="hidden overflow-x-auto lg:block">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-neutral-800 text-left text-xs uppercase text-neutral-500">
                            <th class="px-3 py-3">Network</th>
                            <th class="px-3 py-3">Type</th>
                            <th class="px-3 py-3">Aliases</th>
                            <th class="px-3 py-3">Options</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attachments as $attachment)
                            <tr wire:key="network-connection-{{ $attachment->id }}" class="border-b border-neutral-900 align-top">
                                <td class="px-3 py-3">
                                    <div class="font-medium">{{ $attachment->dockerNetwork?->display_name }}</div>
                                    <div class="text-xs text-neutral-500">{{ $attachment->dockerNetwork?->docker_network_name }}</div>
                                    @foreach ($this->warnings($attachment) as $warning)
                                        <div class="mt-1 text-xs text-warning">{{ $warning }}</div>
                                    @endforeach
                                    @if ($attachment->last_error)
                                        <div class="mt-1 text-xs text-error">{{ $attachment->last_error }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <x-status-badge :status="$this->roleLabel($attachment->dockerNetwork)" type="neutral" />
                                        <x-status-badge
                                            :status="$this->networkTypeLabel($attachment->dockerNetwork)"
                                            :type="$attachment->dockerNetwork?->managed_by_coolify ? 'success' : 'neutral'" />
                                        <x-status-badge
                                            :status="$this->attachmentModeLabel($attachment)"
                                            :type="$this->attachmentModeType($attachment)" />
                                    </div>
                                </td>
                                <td class="px-3 py-3">{{ collect($attachment->aliases ?? [])->join(', ') ?: '-' }}</td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @if ($attachment->is_primary)
                                            <x-status-badge status="Primary" type="success" />
                                        @endif
                                        @if ($attachment->is_required)
                                            <x-status-badge status="Required" type="warning" />
                                        @endif
                                        @if (! $attachment->is_primary && ! $attachment->is_required)
                                            <span class="text-neutral-500">-</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <x-status-badge
                                        :status="$this->statusLabel($attachment)"
                                        :type="$attachment->status?->value === 'attached' ? 'success' : 'neutral'" />
                                </td>
                                <td class="px-3 py-3">
                                    <x-network-attachment-actions :attachment="$attachment" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
