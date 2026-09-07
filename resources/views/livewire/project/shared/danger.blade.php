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
    <x-application.settings-section id="danger-zone-section" title="Danger zone"
        helper="Destructive resource actions cannot be undone.">
        <div
            class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="text-sm font-semibold text-red-700 dark:text-error">Delete {{ $resourceLabel }}</h4>
                        <x-status-badge status="Permanent" type="error" />
                    </div>
                    <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                        Permanently delete
                        <strong class="font-semibold text-black dark:text-fg">{{ $resourceName }}</strong>,
                        stop its containers, and remove the selected Docker resources and configuration.
                    </p>
                    <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                        <li>• Active deployments will be stopped.</li>
                        <li>• Selected volumes and stored data may be permanently removed.</li>
                        <li>• This {{ $resourceLabel }} cannot be restored from Coolify after deletion.</li>
                    </ul>
                </div>

                <div class="shrink-0">
                    @if ($canDelete)
                        <x-modal-confirmation title="Delete {{ $resourceLabel }}?"
                            buttonTitle="Delete {{ $resourceLabel }}"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="['Permanently delete this resource and its selected Docker resources.']"
                            confirmationText="{{ $resourceName }}"
                            confirmationLabel="Enter the resource name to confirm permanent deletion"
                            shortConfirmationLabel="Resource name" />
                    @else
                        <x-forms.button disabled tooltip="You do not have permission to delete this resource.">
                            Delete {{ $resourceLabel }}
                        </x-forms.button>
                    @endif
                </div>
            </div>
        </div>

        @if (!$canDelete)
            <div class="mt-4">
                <x-callout type="danger" title="Insufficient permissions">
                    Contact a team administrator if this resource must be deleted.
                </x-callout>
            </div>
        @endif
    </x-application.settings-section>
</div>
