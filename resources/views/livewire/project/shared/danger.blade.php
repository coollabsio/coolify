<div>
    @php
        $resourceLabel = match ($resource?->type()) {
            'application' => 'application',
            'service' => 'service',
            'service-application' => 'service application',
            'service-database' => 'service database',
            'cluster-application' => 'application',
            default => 'resource',
        };
        $isClusterApplication = $resource instanceof \App\Models\NodeWorkload;
    @endphp
    @if ($resource && ! $isClusterApplication && !data_get($resource, 'destination.server')?->isFunctional())
        <x-callout type="warning" title="Server is not reachable" class="mb-4">
            Coolify cannot stop or remove Docker resources on this server. Deleting removes this {{ $resourceLabel }}
            from Coolify only. Its containers, volumes, networks, and configuration files remain on the server and can
            start again when the server is back.
        </x-callout>
    @endif
    @if ($isClusterApplication && $unreachableServers !== [])
        <x-callout type="warning" title="Server is not reachable" class="mb-4">
            Coolify cannot remove the container on {{ implode(', ', $unreachableServers) }}. Deleting this
            application fails until {{ count($unreachableServers) > 1 ? 'these servers are' : 'the server is' }}
            reachable again. Remove it from Coolify only to delete it anyway; its container may remain on the server.
        </x-callout>
    @endif
    <x-application.settings-section id="danger-zone-section" title="Danger zone"
        helper="Destructive resource actions cannot be undone.">
        @if (in_array($resource?->type(), ['standalone-redis', 'standalone-keydb', 'standalone-dragonfly']))
            <x-danger-zone title="Flush all data" class="mb-4">
                <p>
                    Permanently remove every key from
                    <strong class="font-semibold text-black dark:text-fg">{{ $resourceName }}</strong>.
                    The database keeps running.
                </p>
                <x-slot:action>
                    <x-modal-confirmation title="Flush all data?" buttonTitle="Flush all data" isErrorButton
                        submitAction="flush" :actions="['Permanently remove every key from every logical database.']"
                        confirmationText="{{ $resourceName }}"
                        confirmationLabel="Enter the database name to confirm flushing all data"
                        shortConfirmationLabel="Database name" :disabled="auth()->user()->cannot('manage', $resource)"
                        disabledTooltip="You do not have permission to flush this database." />
                </x-slot:action>
            </x-danger-zone>
        @endif
        <x-danger-zone title="Delete {{ $resourceLabel }}">
                    @if ($isClusterApplication)
                        <p>
                            Permanently delete
                            <strong class="font-semibold text-black dark:text-fg">{{ $resourceName }}</strong>,
                            remove its container from every server it runs on, and delete its configuration.
                        </p>
                        <ul class="space-y-1 text-xs">
                            <li>• Its domains stop routing and its environment variables and firewall rules are deleted.</li>
                            <li>• Non-persistent data in its containers will be permanently removed.</li>
                            <li>• This {{ $resourceLabel }} cannot be restored from Coolify after deletion.</li>
                        </ul>
                    @else
                        <p>
                            Permanently delete
                            <strong class="font-semibold text-black dark:text-fg">{{ $resourceName }}</strong>,
                            stop its containers, and remove the selected Docker resources and configuration.
                        </p>
                        <ul class="space-y-1 text-xs">
                            <li>• Active deployments will be stopped.</li>
                            <li>• Selected volumes and stored data may be permanently removed.</li>
                            <li>• This {{ $resourceLabel }} cannot be restored from Coolify after deletion.</li>
                        </ul>
                    @endif
                <x-slot:action>
                    @if ($canDelete)
                        <x-modal-confirmation title="Delete {{ $resourceLabel }}?"
                            buttonTitle="Delete {{ $resourceLabel }}"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="[$isClusterApplication
                                ? 'Permanently delete this application and remove its container from every server.'
                                : 'Permanently delete this resource and its selected Docker resources.']"
                            confirmationText="{{ $resourceName }}"
                            confirmationLabel="Enter the resource name to confirm permanent deletion"
                            shortConfirmationLabel="Resource name" />
                        @if ($isClusterApplication)
                            <x-modal-confirmation title="Remove {{ $resourceLabel }} from Coolify only?"
                                buttonTitle="Remove from Coolify only"
                                isErrorButton submitAction="deleteFromCoolifyOnly"
                                :actions="[
                                    'Permanently remove this application from Coolify.',
                                    'Leave its containers on the servers.',
                                ]"
                                warningMessage="Coolify will no longer track or manage the Docker resources for this {{ $resourceLabel }}. Use this only when normal cleanup cannot complete."
                                confirmationText="{{ $resourceName }}"
                                confirmationLabel="Enter the resource name to confirm metadata-only deletion"
                                shortConfirmationLabel="Resource name" />
                        @endif
                    @else
                        <x-forms.button isError disabled tooltip="You do not have permission to delete this resource.">
                            Delete {{ $resourceLabel }}
                        </x-forms.button>
                    @endif
                </x-slot:action>
        </x-danger-zone>

        @if (!$canDelete)
            <div class="mt-4">
                <x-callout type="danger" title="Insufficient permissions">
                    Contact a team administrator if this resource must be deleted.
                </x-callout>
            </div>
        @endif
    </x-application.settings-section>
</div>
