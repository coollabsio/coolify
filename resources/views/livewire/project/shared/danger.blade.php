<div>
    @php
        $resourceLabel = match ($resource?->type()) {
            'application' => 'application',
            'service' => 'service',
            'service-application' => 'service application',
            'service-database' => 'service database',
            default => 'resource',
        };
    @endphp
    @if ($resource instanceof \App\Models\Service && !$resource->server?->isFunctional())
        <x-callout type="warning" title="Server is not reachable" class="mb-4">
            Coolify cannot remove or verify Docker resources on this server. The deletion dialog will default to
            removing this service from Coolify only. Its containers, volumes, networks, and configuration files may
            remain on the server.
        </x-callout>
    @endif
    <x-application.settings-section id="danger-zone-section" title="Danger zone"
        helper="Destructive resource actions cannot be undone.">
        <x-danger-zone title="Delete {{ $resourceLabel }}">
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
                <x-slot:action>
                    @if ($canDelete)
                        <x-modal-confirmation title="Delete {{ $resourceLabel }}?"
                            buttonTitle="Delete {{ $resourceLabel }}"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="['Permanently delete this resource and its selected Docker resources.']"
                            confirmationText="{{ $resourceName }}"
                            confirmationLabel="Enter the resource name to confirm permanent deletion"
                            shortConfirmationLabel="Resource name" />
                        @if ($resource instanceof \App\Models\Service)
                            <x-modal-confirmation title="Remove service from Coolify only?"
                                buttonTitle="Remove from Coolify only"
                                isErrorButton submitAction="deleteFromCoolifyOnly"
                                :actions="[
                                    'Permanently remove this service from Coolify.',
                                    'Leave all containers, volumes, networks, and configuration files on the server.',
                                ]"
                                warningMessage="Coolify will no longer track or manage the Docker resources for this service. Use this only when normal cleanup cannot complete."
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
