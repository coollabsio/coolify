<div>
    @if ($resource instanceof \App\Models\Service && $missingRequiredEnvironmentVariableCount > 0)
        @php
            $environmentVariablesUrl = route('project.service.environment-variables', [
                'project_uuid' => $resource->environment->project->uuid,
                'environment_uuid' => $resource->environment->uuid,
                'service_uuid' => $resource->uuid,
            ]);
        @endphp
        <x-popup-small :compact-after="5000" compact-storage-key="required-environment-variables:{{ $resource->uuid }}">
            <x-slot:title>
                {{ $missingRequiredEnvironmentVariableCount === 1 ? 'Required environment variable missing' : 'Required environment variables missing' }}
            </x-slot:title>
            <x-slot:icon>
                <x-reicon name="alert-triangle" class="size-4" />
            </x-slot:icon>
            <x-slot:description>
                <span>
                    {{ implode(', ', $missingRequiredEnvironmentVariableNames) }} must be set before this service can be deployed.
                    <a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                        class="ml-0.5 inline-flex items-center gap-0.5 font-semibold text-coollabs transition-colors hover:text-coollabs-100 dark:text-warning dark:hover:text-warning/80">
                        Open environment variables
                        <x-reicon name="arrow-right" class="size-2.5" />
                    </a>
                </span>
            </x-slot:description>
        </x-popup-small>
    @endif

    @if ($isConfigurationChanged && !is_null($resource->config_hash) && !$resource->isExited())
        @php
            $currentConfigurationHash = $resource instanceof \App\Models\Application
                ? $resource->deploymentConfigurationHash()
                : md5((string) $resource->config_hash);
        @endphp
        <div wire:key="configuration-warning-{{ $currentConfigurationHash }}"
            x-data="{ configurationDiffModalOpen: false, expandedRows: {} }"
            @open-configuration-diff.window="configurationDiffModalOpen = true">
            @teleport('#configuration-warning-hud-slot')
                <div>
                    <x-configuration-warning :diff="$configurationDiff" />
                </div>
            @endteleport

            @teleport('#configuration-warning-hud-slot-mobile')
                <div>
                    <x-configuration-warning :diff="$configurationDiff" />
                </div>
            @endteleport

            @if (data_get($configurationDiff, 'count'))
                <template x-teleport="body">
                    <div x-show="configurationDiffModalOpen" x-cloak
                        class="fixed inset-0 z-99 flex h-screen w-screen items-center justify-center p-4"
                        @keydown.escape.window="configurationDiffModalOpen = false">
                        <div x-show="configurationDiffModalOpen" x-transition.opacity
                            class="absolute inset-0 h-full w-full bg-black/55 backdrop-blur-[3px]"
                            @click="configurationDiffModalOpen = false"></div>
                        <div x-show="configurationDiffModalOpen" x-trap.inert.noscroll="configurationDiffModalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="application-settings-form application-settings-section relative max-h-[calc(100dvh-2rem)] w-full max-w-6xl overflow-hidden"
                            style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                            <header>
                                <div class="flex items-center gap-1.5">
                                    <x-reicon name="alert-triangle"
                                        class="size-3.5 text-amber-600 dark:text-warning" />
                                    <h3>Configuration changes</h3>
                                </div>
                                <button type="button" @click="configurationDiffModalOpen = false"
                                    class="flex size-6 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-black/5 hover:text-neutral-800 dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                    <x-reicon name="x" class="size-3.5" />
                                </button>
                            </header>
                            <div
                                class="application-settings-section-body min-h-0 flex-1 overflow-y-auto !p-3">
                                <div
                                    class="mb-3 flex items-center justify-between gap-2 rounded-md bg-amber-50 px-2.5 py-1.5 ring-1 ring-amber-200 dark:bg-warning/[0.07] dark:ring-warning/15">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold text-neutral-900 dark:text-fg">
                                                {{ data_get($configurationDiff, 'count') }}
                                                {{ data_get($configurationDiff, 'count') === 1 ? 'change' : 'changes' }}
                                                · Deploy again to apply
                                            </p>
                                        </div>
                                    </div>
                                    <x-status-badge
                                        :status="data_get($configurationDiff, 'requires_build') ? 'Rebuild required' : 'Redeploy required'"
                                        type="warning" />
                                </div>
                                <x-deployment.configuration-diff :diff="$configurationDiff" compact />
                            </div>
                        </div>
                    </div>
                </template>
            @endif
        </div>
    @endif
</div>
