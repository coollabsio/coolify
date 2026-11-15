@php
    $hasPorts = $publishedPorts !== [];
    $gridClass = 'grid-cols-[minmax(0,10rem)_minmax(0,8rem)_minmax(0,1fr)_5rem]';
@endphp

<div class="flex flex-col gap-6">
    @unless ($publishedPortsSupported)
        <x-callout type="warning" title="Not every server can publish ports yet">
            Publishing ports needs the latest Sentinel on every server of the cluster. A server with an older
            Sentinel keeps the ports closed until it is upgraded.
        </x-callout>
    @endunless

    <x-application.settings-section id="cluster-application-published-ports" title="Published ports" :flush="$hasPorts"
        helper="Opens a TCP or UDP port of this {{ $workload->isDatabase() ? 'database' : 'application' }} on every server of the cluster, without a redeploy. HTTP domains do not need a published port.<br><br><span class='font-bold dark:text-warning'>An empty source list opens the port to everyone who can reach a server IP of the cluster.</span><br><br>A connection to a server that does not run the {{ $workload->isDatabase() ? 'database' : 'application' }} goes over the cluster network, and the {{ $workload->isDatabase() ? 'database' : 'application' }} sees the address of that server, not the address of the client. Point DNS at a server that runs it when the client address matters.">
        <x-slot:actions>
            @can('update', $workload)
                <x-modal-input title="Publish port" :closeOutside="false" :wireIgnore="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            Publish port
                        </button>
                    </x-slot:content>
                    <form wire:submit="publishPort" class="application-settings-form flex flex-col gap-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <x-forms.listbox id="newPortProtocol" htmlId="cluster-application-port-protocol" label="Protocol"
                                portal :options="[
                                    ['value' => 'tcp', 'label' => 'TCP'],
                                    ['value' => 'udp', 'label' => 'UDP'],
                                ]" />
                            <x-forms.input id="newPortContainerPort" type="number" label="Container port" placeholder="5432" required
                                helper="The port that the container listens on." />
                            <x-forms.input id="newPortHostPort" type="number" label="Server port" placeholder="Container port"
                                helper="The port on the servers of the cluster. Empty uses the container port." />
                        </div>
                        <x-forms.textarea id="newPortSources" label="Allowed sources" rows="3" monospace
                            placeholder="203.0.113.10 198.51.100.0/24"
                            helper="One IPv4 address or CIDR on each line, at most 64. Empty allows every source." />
                        <div class="flex justify-end pt-2">
                            <x-forms.button type="submit" isHighlighted>Publish</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @if ($hasPorts)
            <div class="data-table-header {{ $gridClass }}">
                <span>Server port</span>
                <span>Container port</span>
                <span>Allowed sources</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="data-table w-full">
                @foreach ($publishedPorts as $port)
                    <div wire:key="cluster-application-published-port-{{ $port['uuid'] }}" class="env-table-item">
                        <div class="data-table-row {{ $gridClass }} py-2!">
                            <span class="min-w-0 truncate font-mono text-[12px]">{{ $port['protocol'] }} {{ $port['hostPort'] }}</span>
                            <span class="min-w-0 truncate font-mono text-[12px]">→ {{ $port['containerPort'] }}</span>
                            <span class="min-w-0 truncate text-[12px]" title="{{ implode(', ', $port['sources']) }}">
                                @if ($port['sources'] === [])
                                    <span class="text-warning">Everyone</span>
                                @else
                                    <span class="font-mono">{{ implode(', ', $port['sources']) }}</span>
                                @endif
                            </span>
                            <div class="flex items-center justify-end gap-1">
                                @can('update', $workload)
                                    <x-modal-confirmation class="!w-auto shrink-0" title="Remove published port?" buttonTitle="Remove"
                                        isErrorButton canGate="update" :canResource="$workload"
                                        submitAction="removePublishedPort('{{ $port['uuid'] }}')" :actions="[
                                            'Port '.$port['label'].' is closed on every server of the cluster.',
                                        ]" :confirmWithPassword="false" :confirmWithText="false"
                                        step2ButtonText="Remove port">
                                        <x-slot:trigger>
                                            <button type="button"
                                                class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                                title="Remove port" aria-label="Remove port {{ $port['label'] }}">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </button>
                                        </x-slot:trigger>
                                    </x-modal-confirmation>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty size="sm" title="No published ports"
                description="Publish a port to connect to this {{ $workload->isDatabase() ? 'database' : 'application' }} from outside the cluster." icon-name="network" />
        @endif
    </x-application.settings-section>

    @if ($hasPorts && $publishedPortServers !== [])
        <x-application.settings-section id="cluster-application-published-port-servers" title="Server addresses" flush
            helper="Every server of the cluster serves the published ports on its address.">
            <div class="data-table-header grid-cols-[minmax(0,1fr)_minmax(0,10rem)_minmax(0,12rem)]">
                <span>Server</span>
                <span>Address</span>
                <span>Client address</span>
            </div>
            <div class="data-table w-full">
                @foreach ($publishedPortServers as $server)
                    <div wire:key="cluster-application-published-port-server-{{ $server['ip'] }}" class="data-table-row grid-cols-[minmax(0,1fr)_minmax(0,10rem)_minmax(0,12rem)] py-2!">
                        <span class="min-w-0 truncate text-[13px]">{{ $server['name'] }}</span>
                        <span class="min-w-0 truncate font-mono text-[12px]">{{ $server['ip'] }}</span>
                        <span class="min-w-0 truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                            {{ $server['runsApplication'] ? 'Kept' : 'Shows the address of this server' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</div>
