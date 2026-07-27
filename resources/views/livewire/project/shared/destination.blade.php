<div>
    @if ($resource->getMorphClass() === 'App\Models\Application')
        @php
            $primaryStatus = str($resource->realStatus());
            $primaryStatusType = match (true) {
                $primaryStatus->startsWith('running') => 'success',
                $primaryStatus->startsWith('exited') => 'error',
                $primaryStatus->startsWith(['starting', 'restarting']) => 'warning',
                default => 'neutral',
            };
            $primaryStatusLabel = $primaryStatus->before(':')->headline()->value() ?: 'Unknown';
            $additionalDestinations = $resource->additional_networks;
            $hasAdditionalDestinations = $additionalDestinations->isNotEmpty();
        @endphp

        <div class="flex flex-col gap-6">
            <x-application.settings-section id="primary-server-section" title="Primary server"
                helper="The default server and network used for this application's deployments.">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                            <x-reicon name="servers" class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                    {{ data_get($resource, 'destination.server.name') }}
                                </h4>
                                <span
                                    class="rounded-sm bg-coollabs/10 px-1.5 py-0.5 text-[11px] font-medium text-coollabs dark:bg-warning/10 dark:text-warning">
                                    Primary
                                </span>
                            </div>
                            <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[13px] text-neutral-500 dark:text-fg-dim">
                                <span>Network</span>
                                <code
                                    class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($resource, 'destination.network') }}</code>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        @if ($primaryStatus->startsWith('running'))
                            <x-status.running :status="$primaryStatus->value()" />
                        @elseif ($primaryStatus->startsWith(['starting', 'restarting']))
                            <x-status.restarting :status="$primaryStatus->value()" />
                        @elseif ($primaryStatus->startsWith('exited'))
                            <x-status.stopped :status="$primaryStatus->value()" />
                        @else
                            <x-status-badge :status="$primaryStatusLabel" :type="$primaryStatusType" />
                        @endif
                        @if ($hasAdditionalDestinations)
                            <x-forms.button canGate="deploy" :canResource="$resource"
                                wire:click="redeploy('{{ data_get($resource, 'destination.id') }}','{{ data_get($resource, 'destination.server.id') }}')">
                                Deploy
                            </x-forms.button>
                            @if ($primaryStatus->startsWith('running'))
                                <x-forms.button isError canGate="deploy" :canResource="$resource"
                                    wire:click="stop('{{ data_get($resource, 'destination.server.id') }}')">
                                    Stop
                                </x-forms.button>
                            @endif
                        @endif
                    </div>
                </div>
            </x-application.settings-section>

            @if ($hasAdditionalDestinations && data_get($resource, 'build_pack') !== 'dockercompose')
                <x-application.settings-section id="additional-servers-section" title="Additional servers"
                    helper="Deploy this application to more servers and choose which destination is primary." flush>
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($additionalDestinations as $destination)
                            @php
                                $destinationStatus = str(data_get($destination, 'pivot.status'));
                                $destinationStatusType = match (true) {
                                    $destinationStatus->startsWith('running') => 'success',
                                    $destinationStatus->startsWith('exited') => 'error',
                                    $destinationStatus->startsWith(['starting', 'restarting']) => 'warning',
                                    default => 'neutral',
                                };
                                $destinationStatusLabel = $destinationStatus->before(':')->headline()->value() ?: 'Unknown';
                            @endphp
                            <div class="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between"
                                wire:key="destination-{{ $destination->id }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                                        <x-reicon name="servers" class="size-[18px]" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                            {{ data_get($destination, 'server.name') }}
                                        </h4>
                                        <p
                                            class="mt-1 flex flex-wrap items-center gap-1.5 text-[13px] text-neutral-500 dark:text-fg-dim">
                                            <span>Network</span>
                                            <code
                                                class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($destination, 'network') }}</code>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                    @if ($destinationStatus->startsWith('running'))
                                        <x-status.running :status="$destinationStatus->value()" />
                                    @elseif ($destinationStatus->startsWith(['starting', 'restarting']))
                                        <x-status.restarting :status="$destinationStatus->value()" />
                                    @elseif ($destinationStatus->startsWith('exited'))
                                        <x-status.stopped :status="$destinationStatus->value()" />
                                    @else
                                        <x-status-badge :status="$destinationStatusLabel" :type="$destinationStatusType" />
                                    @endif
                                    <x-forms.button canGate="deploy" :canResource="$resource"
                                        wire:click="redeploy('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">
                                        Deploy
                                    </x-forms.button>
                                    <x-forms.button canGate="update" :canResource="$resource"
                                        wire:click="promote('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">
                                        Make primary
                                    </x-forms.button>
                                    @if ($destinationStatus->startsWith('running'))
                                        <x-forms.button isError canGate="deploy" :canResource="$resource"
                                            wire:click="stop('{{ data_get($destination, 'server.id') }}')">
                                            Stop
                                        </x-forms.button>
                                    @endif
                                    <x-modal-confirmation title="Remove server from application?" isErrorButton
                                        buttonTitle="Remove"
                                        :disabled="!auth()->user()->can('update', $resource)"
                                        :authDisabled="!auth()->user()->can('update', $resource)"
                                        submitAction="removeServer({{ data_get($destination, 'id') }},{{ data_get($destination, 'server.id') }})"
                                        :actions="[
                                            'This will stop the application on this server and remove it as a deployment destination.',
                                        ]" confirmationText="{{ data_get($destination, 'server.name') }}"
                                        confirmationLabel="Enter the server name to confirm removal"
                                        shortConfirmationLabel="Server name" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-application.settings-section>
            @endif

            @if (data_get($resource, 'build_pack') !== 'dockercompose')
                <x-application.settings-section id="available-servers-section" title="Add another server"
                    helper="Attach another available server and network as a deployment destination." :flush="$resource->persistentStorages()->count() === 0">
                    @if ($resource->persistentStorages()->count() > 0)
                        <x-callout type="warning" title="Additional servers are unavailable">
                            This application has persistent storage volumes. Applications with persistent storage
                            cannot use multiple servers because those volumes are not shared between hosts.
                        </x-callout>
                    @elseif ($networks->isNotEmpty())
                        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @foreach ($networks as $network)
                                <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                                    wire:key="available-destination-{{ $network->id }}">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div
                                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                                            <x-reicon name="plus" class="size-[18px]" />
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                                {{ data_get($network, 'server.name') }}
                                            </h4>
                                            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                                Network
                                                <code
                                                    class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($network, 'name') }}</code>
                                            </p>
                                        </div>
                                    </div>
                                    <x-forms.button canGate="update" :canResource="$resource"
                                        wire:click="addServer('{{ $network->id }}','{{ data_get($network, 'server.id') }}')">
                                        Add server
                                    </x-forms.button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <x-empty size="sm" title="No servers available"
                            description="Every usable server is already attached, or no additional server networks are configured.">
                            <x-slot:icon>
                                <x-reicon name="servers" class="size-8" />
                            </x-slot:icon>
                        </x-empty>
                    @endif
                </x-application.settings-section>
            @endif
        </div>
    @else
        @php
            $resourceStatus = str($resource->realStatus());
            [$resourceStatusLabel, $resourceStatusType] = match (true) {
                $resourceStatus->startsWith('running') => ['Running', 'success'],
                $resourceStatus->startsWith(['starting', 'restarting']) => ['Starting', 'warning'],
                $resourceStatus->startsWith('exited') => ['Stopped', 'error'],
                default => [$resourceStatus->before(':')->headline()->value() ?: 'Unknown', 'neutral'],
            };
        @endphp

        <div class="space-y-6">
            <x-application.settings-section title="Primary server"
                helper="The default server and network used by this resource.">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                            <x-reicon name="servers" class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                    {{ data_get($resource, 'destination.server.name') }}
                                </h4>
                                <x-status-badge status="Primary" type="neutral" />
                            </div>
                            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                Network
                                <code
                                    class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($resource, 'destination.network') }}</code>
                            </p>
                        </div>
                    </div>
                    <x-status-badge :status="$resourceStatusLabel" :type="$resourceStatusType" />
                </div>
            </x-application.settings-section>

            @if ($resource?->additional_networks?->count() > 0
                    && data_get($resource, 'build_pack') !== 'dockercompose')
                <x-application.settings-section title="Additional servers"
                    helper="Other deployment destinations attached to this resource." flush>
                    <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($resource->additional_networks as $destination)
                            @php
                                $destinationStatus = str(data_get($destination, 'pivot.status'));
                                [$destinationStatusLabel, $destinationStatusType] = match (true) {
                                    $destinationStatus->startsWith('running') => ['Running', 'success'],
                                    $destinationStatus->startsWith(['starting', 'restarting']) => ['Starting', 'warning'],
                                    $destinationStatus->startsWith('exited') => ['Stopped', 'error'],
                                    default => [$destinationStatus->before(':')->headline()->value() ?: 'Unknown', 'neutral'],
                                };
                            @endphp
                            <div class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                                wire:key="destination-{{ $destination->id }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.05] dark:text-fg-dim">
                                        <x-reicon name="servers" class="size-[18px]" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                            {{ data_get($destination, 'server.name') }}
                                        </h4>
                                        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                            Network
                                            <code
                                                class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($destination, 'network') }}</code>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-status-badge :status="$destinationStatusLabel"
                                        :type="$destinationStatusType" />
                                    <x-forms.button
                                        wire:click="redeploy('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">
                                        Deploy
                                    </x-forms.button>
                                    <x-forms.button
                                        wire:click="promote('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">
                                        Make primary
                                    </x-forms.button>
                                    @if ($destinationStatus->startsWith('running'))
                                        <x-forms.button isError
                                            wire:click="stop('{{ data_get($destination, 'server.id') }}')">Stop</x-forms.button>
                                    @endif
                                    <x-modal-confirmation title="Remove server from resource?" isErrorButton
                                        buttonTitle="Remove"
                                        submitAction="removeServer({{ data_get($destination, 'id') }},{{ data_get($destination, 'server.id') }})"
                                        :actions="['This deployment destination will be removed.']"
                                        confirmationText="{{ data_get($destination, 'server.name') }}"
                                        confirmationLabel="Enter the server name to confirm removal."
                                        shortConfirmationLabel="Server Name" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-application.settings-section>
            @endif
        </div>
    @endif
</div>
