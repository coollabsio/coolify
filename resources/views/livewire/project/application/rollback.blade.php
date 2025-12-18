<div x-init="$wire.loadDeployments">
    <div class="flex items-center gap-2">
        <h2>Deployment History & Rollback</h2>
        @can('view', $application)
            <x-forms.button wire:click='loadDeployments(true)'>Refresh</x-forms.button>
        @endcan
    </div>
    <div class="pb-4">
        Roll back to any previous deployment. Instant rollback is available when the Docker image still exists.
        If the image has been cleaned up, a rebuild will be triggered using the saved configuration.
    </div>

    @if($serverRetentionDisabled)
        <x-callout type="warning" class="mb-4">
            Image retention is disabled at the server level. This setting has no effect until the server administrator enables it.
        </x-callout>
    @endif

    <div class="pb-4">
        <form wire:submit="saveSettings" class="flex items-end gap-2 w-96">
            <x-forms.input id="dockerImagesToKeep" type="number" min="0" max="100" label="Images to keep for rollback"
                helper="Number of Docker images to keep for rollback during cleanup. Set to 0 to only keep the currently running image. PR images are always deleted during cleanup.<br><br><strong>Note:</strong> Server administrators can disable image retention at the server level, which overrides this setting."
                canGate="update" :canResource="$application" :disabled="$serverRetentionDisabled" />
            <x-forms.button canGate="update" :canResource="$application" type="submit" :disabled="$serverRetentionDisabled">Save</x-forms.button>
        </form>
    </div>

    <div wire:target='loadDeployments' wire:loading.remove>
        <div class="flex flex-col gap-2">
            @forelse ($deployments as $deployment)
                <div class="bg-white border rounded-sm dark:border-coolgray-300 dark:bg-coolgray-100 border-neutral-200 p-4">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                @if (data_get($deployment, 'is_current'))
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-md bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                        CURRENT
                                    </span>
                                @endif
                                <span class="font-mono text-sm">
                                    {{ Str::limit(data_get($deployment, 'commit'), 12) }}
                                </span>
                            </div>
                            @php
                                $date = data_get($deployment, 'created_at');
                                $interval = \Illuminate\Support\Carbon::parse($date);
                            @endphp
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $interval->diffForHumans() }} ({{ $interval->format('Y-m-d H:i:s') }})
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            @if (data_get($deployment, 'has_config'))
                                @if (data_get($deployment, 'image_exists'))
                                    <span class="text-xs text-green-600 dark:text-green-400">Image available</span>
                                @else
                                    <span class="text-xs text-yellow-600 dark:text-yellow-400">Rebuild required</span>
                                @endif
                            @else
                                <span class="text-xs text-red-600 dark:text-red-400">Config not saved</span>
                            @endif

                            @can('deploy', $application)
                                @if (data_get($deployment, 'is_current'))
                                    <x-forms.button disabled tooltip="This deployment is currently running.">
                                        Current
                                    </x-forms.button>
                                @elseif (data_get($deployment, 'can_instant_rollback'))
                                    <x-forms.button
                                        wire:click="rollbackToDeployment('{{ data_get($deployment, 'deployment_uuid') }}')"
                                        isHighlighted>
                                        Instant Rollback
                                    </x-forms.button>
                                @elseif (data_get($deployment, 'can_rebuild_rollback'))
                                    <x-forms.button
                                        wire:click="rollbackToDeployment('{{ data_get($deployment, 'deployment_uuid') }}')"
                                        class="bg-yellow-600 hover:bg-yellow-700 dark:bg-yellow-700 dark:hover:bg-yellow-600">
                                        Rollback (Rebuild)
                                    </x-forms.button>
                                @else
                                    <x-forms.button disabled tooltip="Configuration not saved for this deployment. Only new deployments will have rollback support.">
                                        Unavailable
                                    </x-forms.button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-gray-500 dark:text-gray-400">
                    No deployment history found. Deploy your application to enable rollback functionality.
                </div>
            @endforelse
        </div>
    </div>
    <div wire:target='loadDeployments' wire:loading>Loading deployment history...</div>
</div>
