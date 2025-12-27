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
        <form wire:submit="saveSettings" class="flex items-end gap-2">
            <x-forms.input id="dockerImagesToKeep" type="number" min="0" max="100" label="Deployments to keep for rollback"
                helper="Number of deployments (images and configurations) to keep for rollback. Set to 0 to only keep the currently running deployment. PR deployments are always deleted during cleanup.<br><br><strong>Note:</strong> Server administrators can disable retention at the server level, which overrides this setting."
                canGate="update" :canResource="$application" :disabled="$serverRetentionDisabled" />
            <x-forms.button canGate="update" :canResource="$application" type="submit" :disabled="$serverRetentionDisabled">Save</x-forms.button>
        </form>
    </div>

    <div wire:target='loadDeployments' wire:loading.remove>
        <div class="flex flex-col gap-2">
            @forelse ($deployments as $deployment)
                <div @class([
                    'bg-white border rounded-sm dark:bg-coolgray-100 p-4 border-l-2',
                    'border-l-green-500' => data_get($deployment, 'is_current'),
                    'border-neutral-200 dark:border-coolgray-300' => !data_get($deployment, 'is_current'),
                ])>
                    <div class="flex flex-col gap-2">
                        {{-- Row 1: Deployment path --}}
                        <div class="text-xs text-neutral-500 dark:text-neutral-400 font-mono">
                            /data/coolify/applications/{{ $application->uuid }}/deployments/{{ data_get($deployment, 'deployment_uuid') }}
                        </div>

                        {{-- Row 3: Date --}}
                        @php
                            $date = data_get($deployment, 'created_at');
                            $interval = \Illuminate\Support\Carbon::parse($date);
                        @endphp
                        <div class="text-sm text-neutral-500 dark:text-neutral-400">
                            {{ $interval->format('Y-m-d H:i:s') }}
                        </div>

                        {{-- Row 4: Image name (if available) --}}
                        @if (data_get($deployment, 'image_name'))
                            <div class="text-xs text-neutral-500 dark:text-neutral-400 font-mono">
                                Image: {{ data_get($deployment, 'image_name') }}
                            </div>
                        @endif

                        {{-- Row 5: Commit info (git-based) OR Deployment time (pure Dockerfile) + Rollback button --}}
                        <div class="flex items-center gap-2 flex-wrap">
                            @if ($application->dockerfile && data_get($deployment, 'commit') === 'HEAD')
                                {{-- Pure Dockerfile deployment - show deployment timestamp --}}
                                <span class="text-sm text-neutral-500 dark:text-neutral-400">Deployed:</span>
                                <span class="text-sm">{{ data_get($deployment, 'created_at')->format('Y-m-d H:i:s') }}</span>
                            @else
                                {{-- Git-based deployment - show commit info --}}
                                <span class="text-sm text-neutral-500 dark:text-neutral-400">Commit:</span>
                                <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                   target="_blank"
                                   class="underline text-sm">
                                    {{ substr(data_get($deployment, 'commit'), 0, 7) }}
                                </a>
                                @if (data_get($deployment, 'commit_message'))
                                    <span class="text-neutral-500 dark:text-neutral-400">-</span>
                                    <a href="{{ $application->gitCommitLink(data_get($deployment, 'commit')) }}"
                                       target="_blank"
                                       class="text-neutral-500 dark:text-neutral-400 underline text-sm truncate max-w-md">
                                        {{ Str::before(data_get($deployment, 'commit_message'), "\n") }}
                                    </a>
                                @endif
                            @endif

                            @if (!data_get($deployment, 'image_exists'))
                                <span class="text-xs text-yellow-600 dark:text-yellow-400">(Rebuild required)</span>
                            @endif

                            @can('deploy', $application)
                                @if (data_get($deployment, 'can_instant_rollback'))
                                    <x-forms.button
                                        wire:click="rollbackToDeployment('{{ data_get($deployment, 'deployment_uuid') }}')"
                                        class="ml-auto"
                                        isHighlighted>
                                        Rollback
                                    </x-forms.button>
                                @elseif (data_get($deployment, 'can_rebuild_rollback'))
                                    <x-forms.button
                                        wire:click="rollbackToDeployment('{{ data_get($deployment, 'deployment_uuid') }}')"
                                        class="ml-auto"
                                        isHighlighted>
                                        Rollback
                                    </x-forms.button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-neutral-500 dark:text-neutral-400">
                    No deployment history found. Deploy your application to enable rollback functionality.
                </div>
            @endforelse
        </div>
    </div>
    <div wire:target='loadDeployments' wire:loading>Loading deployment history...</div>
</div>
