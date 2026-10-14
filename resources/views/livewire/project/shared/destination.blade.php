<div>
    @if ($resource->getMorphClass() === 'App\Models\Application')
        @php
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
                                <x-status-summary :status="$resource->status" />
                            </div>
                            <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[13px] text-neutral-500 dark:text-fg-dim">
                                <span>Network</span>
                                <code
                                    class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($resource, 'destination.network') }}</code>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <a href="{{ route('server.show', ['server_uuid' => data_get($resource, 'destination.server.uuid')]) }}"
                            {{ wireNavigate() }} class="button">Open server</a>
                        <x-application.restart-limit-warning :application="$resource" />
                        @if ($hasAdditionalDestinations)
                            @can('deploy', $resource)
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                                    @keydown.escape.window="open = false">
                                    <button type="button" class="button gap-1.5" title="Server actions" @click="open = !open"
                                        :aria-expanded="open" aria-haspopup="menu">
                                        Actions
                                        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                            <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                        </span>
                                    </button>
                                    <div x-cloak x-show="open" x-transition.origin.top.right
                                        class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-52! min-w-52!" role="menu">
                                        <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                            wire:click="redeploy('{{ data_get($resource, 'destination.id') }}','{{ data_get($resource, 'destination.server.id') }}')" @click="open = false">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Deploy
                                        </button>
                                    @if (str($resource->status)->startsWith('running'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                            wire:click="stop('{{ data_get($resource, 'destination.server.id') }}')"
                                            @click="open = false">
                                            <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                            Stop
                                        </button>
                                    @endif
                                    </div>
                                </div>
                            @endcan
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
                            @endphp
                            <div class="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between"
                                wire:key="destination-{{ $destination->id }}">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                                        <x-reicon name="servers" class="size-[18px]" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="truncate text-sm font-semibold text-black dark:text-fg">
                                                {{ data_get($destination, 'server.name') }}
                                            </h4>
                                            <x-status-summary :status="$destinationStatus->value()" />
                                        </div>
                                        <p
                                            class="mt-1 flex flex-wrap items-center gap-1.5 text-[13px] text-neutral-500 dark:text-fg-dim">
                                            <span>Network</span>
                                            <code
                                                class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-xs text-neutral-700 dark:bg-white/[0.05] dark:text-fg-dim">{{ data_get($destination, 'network') }}</code>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                    <a href="{{ route('server.show', ['server_uuid' => data_get($destination, 'server.uuid')]) }}"
                                        {{ wireNavigate() }} class="button">Open server</a>
                                    @can('deploy', $resource)
                                        <div class="relative" x-data="{ open: false }" @click.outside="open = false"
                                            @keydown.escape.window="open = false">
                                            <button type="button" class="button gap-1.5" title="Server actions" @click="open = !open"
                                                :aria-expanded="open" aria-haspopup="menu">
                                                Actions
                                                <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                                </span>
                                            </button>
                                            <div x-cloak x-show="open" x-transition.origin.top.right
                                                class="listbox-panel top-full! right-0! left-auto! z-[90]! mt-1! w-52! min-w-52!" role="menu">
                                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                                    wire:click="redeploy('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')" @click="open = false">
                                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                                    Deploy
                                                </button>
                                            @can('update', $resource)
                                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                                    wire:click="promote('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')"
                                                    @click="open = false">
                                                    <x-reicon name="shield-star" class="size-3.5 opacity-70" />
                                                    Make primary
                                                </button>
                                            @endcan
                                            @if ($destinationStatus->startsWith('running'))
                                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                                    wire:click="stop('{{ data_get($destination, 'server.id') }}')"
                                                    @click="open = false">
                                                    <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                                    Stop
                                                </button>
                                            @endif
                                            @can('update', $resource)
                                                <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                                                    @click="open = false; document.getElementById('destination-remove-trigger-{{ $destination->id }}')?.click()">
                                                    <x-reicon name="trash" class="size-3.5 text-error" />
                                                    Remove
                                                </button>
                                            @endcan
                                            </div>
                                        </div>
                                    @endcan
                                    @can('update', $resource)
                                        <div class="hidden" aria-hidden="true">
                                            <x-modal-confirmation title="Remove server from application?" isErrorButton
                                                buttonTitle="Remove"
                                                submitAction="removeServer({{ data_get($destination, 'id') }},{{ data_get($destination, 'server.id') }})"
                                                :actions="[
                                                    'This will stop the application on this server and remove it as a deployment destination.',
                                                ]" confirmationText="{{ data_get($destination, 'server.name') }}"
                                                confirmationLabel="Enter the server name to confirm removal"
                                                shortConfirmationLabel="Server name">
                                                <x-slot:trigger>
                                                    <button id="destination-remove-trigger-{{ $destination->id }}" type="button">Remove</button>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        </div>
                                    @endcan
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
                            description="Every usable server is already attached, or no additional server networks are configured."
                            icon-name="servers" />
                    @endif
                </x-application.settings-section>
            @endif
        </div>
    @else
        {{-- Databases and services are single-server only; match the application primary card without multi-server UI. --}}
        @php
            $primaryStatus = str($resource->realStatus());
            $primaryStatusType = match (true) {
                $primaryStatus->startsWith('running') => 'success',
                $primaryStatus->startsWith('exited') => 'error',
                $primaryStatus->startsWith(['starting', 'restarting']) => 'warning',
                default => 'neutral',
            };
            $primaryStatusLabel = $primaryStatus->before(':')->headline()->value() ?: 'Unknown';
        @endphp

        <div class="flex flex-col gap-6">
            <x-application.settings-section id="primary-server-section" title="Primary server"
                helper="The server and network used by this resource.">
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
                        <a href="{{ route('server.show', ['server_uuid' => data_get($resource, 'destination.server.uuid')]) }}"
                            {{ wireNavigate() }} class="button">Open server</a>
                        @if (method_exists($resource, 'stoppedAfterRestartLimit'))
                            <x-application.restart-limit-warning :application="$resource" />
                        @endif
                        @if ($primaryStatus->startsWith('running'))
                            <x-status.running :status="$primaryStatus->value()" />
                        @elseif ($primaryStatus->startsWith(['starting', 'restarting']))
                            <x-status.restarting :status="$primaryStatus->value()" />
                        @elseif ($primaryStatus->startsWith('exited'))
                            <x-status.stopped :status="$primaryStatus->value()" />
                        @else
                            <x-status-badge :status="$primaryStatusLabel" :type="$primaryStatusType" />
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </div>
    @endif

    <livewire:project.shared.container-networks :resource="$resource" lazy />
</div>
