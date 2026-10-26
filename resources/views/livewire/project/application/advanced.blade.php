<div>
    @php
        $canUpdate = auth()->user()->can('update', $application);
        $labelsManagedByCoolify = $application->settings->is_container_label_readonly_enabled;
        // Use model UUIDs: Livewire update requests do not carry page route params.
        $generalRouteParameters = [
            'project_uuid' => data_get($application, 'environment.project.uuid'),
            'environment_uuid' => data_get($application, 'environment.uuid'),
            'application_uuid' => $application->uuid,
        ];
    @endphp

    <div class="flex flex-col gap-6">
        <x-application.settings-section id="advanced-build-section" title="Build & deploy"
            helper="How images are built, how deployments start, and how the repository is cloned.">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="disableBuildCache" label="Build cache" onChange="instantSave"
                    helper="Disabling the cache forces a completely fresh Docker build on every deployment."
                    :options="[
                        ['value' => false, 'label' => 'Use Docker build cache'],
                        ['value' => true, 'label' => 'Rebuild from scratch every time'],
                    ]" :disabled="! $canUpdate" />
                <x-forms.listbox id="injectBuildArgsToDockerfile" label="Build arguments" onChange="instantSave"
                    helper="When injected automatically, Coolify adds ARG statements to your Dockerfile for build-time variables. Manage them manually to preserve Docker build cache."
                    :options="[
                        ['value' => true, 'label' => 'Inject build args automatically'],
                        ['value' => false, 'label' => 'Managed manually in Dockerfile'],
                    ]" :disabled="! $canUpdate" />
                <x-forms.listbox id="includeSourceCommitInBuild" label="Source commit availability" onChange="instantSave"
                    helper="SOURCE_COMMIT (git commit hash) is always available at runtime. Making it available during build invalidates the cache on every commit."
                    :options="[
                        ['value' => false, 'label' => 'Runtime only (preserves cache)'],
                        ['value' => true, 'label' => 'Available during build'],
                    ]" :disabled="! $canUpdate" />
            </div>
            @if ($application->git_based())
                <x-application.settings-subsection id="advanced-deployment-section" title="Deployment"
                    helper="Automatic deployments from Git webhooks.">
                    <div class="grid w-full gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="isAutoDeployEnabled" label="Auto deploy" onChange="instantSave"
                            helper="Automatically deploy new commits based on Git webhooks."
                            :options="[
                                ['value' => true, 'label' => 'Deploy on push (webhooks)'],
                                ['value' => false, 'label' => 'Manual deployments only'],
                            ]" :disabled="! $canUpdate" />
                    </div>
                </x-application.settings-subsection>

                <x-application.settings-subsection id="advanced-git-section" title="Git"
                    helper="Options applied while cloning the repository during builds.">
                    <div class="grid w-full gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="isGitSubmodulesEnabled" label="Submodules" onChange="instantSave"
                            helper="Allow Git submodules during the build process."
                            :options="[
                                ['value' => true, 'label' => 'Clone submodules'],
                                ['value' => false, 'label' => 'Skip submodules'],
                            ]" :disabled="! $canUpdate" />
                        <x-forms.listbox id="isGitLfsEnabled" label="Git LFS" onChange="instantSave"
                            helper="Allow Git LFS during the build process."
                            :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" :disabled="! $canUpdate" />
                        <x-forms.listbox id="isGitShallowCloneEnabled" label="Clone depth" onChange="instantSave"
                            helper="Shallow cloning (--depth=1) speeds up deployments by only fetching the latest commit, useful for large repositories."
                            :options="[
                                ['value' => false, 'label' => 'Full history'],
                                ['value' => true, 'label' => 'Shallow clone (latest commit only)'],
                            ]" :disabled="! $canUpdate" />
                    </div>
                </x-application.settings-subsection>
            @endif
            @if ($application->build_pack === 'dockercompose')
                <x-application.settings-subsection id="advanced-compose-section" title="Docker compose"
                    helper="Advanced behavior for compose-based deployments.">
                    <div class="grid w-full gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="isRawComposeDeploymentEnabled" label="Compose deployment" onChange="instantSave"
                            helper="WARNING: Advanced use cases only. In raw mode your compose file is deployed as-is. Nothing is modified by Coolify and you need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation</a>."
                            :options="[
                                ['value' => false, 'label' => 'Managed by Coolify'],
                                ['value' => true, 'label' => 'Raw (deploy file as-is)'],
                            ]" :disabled="! $canUpdate" />
                        <x-forms.listbox id="isConnectToDockerNetworkEnabled" label="Predefined network" onChange="instantSave"
                            helper="By default a compose resource only gets its own internal network. Connecting to a Coolify predefined network may require different internal DNS names. More info <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>here</a>."
                            :options="[
                                ['value' => false, 'label' => 'Isolated network only'],
                                ['value' => true, 'label' => 'Connect to predefined network'],
                            ]" :disabled="! $canUpdate" />
                    </div>
                </x-application.settings-subsection>
            @endif
        </x-application.settings-section>

        <x-application.settings-section id="advanced-container-section" title="Container"
            helper="Naming, shutdown, logs, and GPU access for the deployed container.">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isConsistentContainerNameEnabled" label="Container naming" onChange="instantSave"
                    helper="With a consistent name the container is always called {{ $application->uuid }}. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                    :options="[
                        ['value' => false, 'label' => 'Generated name (rolling updates)'],
                        ['value' => true, 'label' => 'Consistent name (no rolling updates)'],
                    ]" :disabled="! $canUpdate" />
                @if ($isConsistentContainerNameEnabled === true)
                    <form wire:submit="saveCustomName" class="w-full">
                        <x-unsaved-bar action="saveCustomName" targets="customInternalName" />
                        <x-forms.input
                            helper="You can add a custom name for your container.<br><br>The name is converted to slug format when saved. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                            id="customInternalName" label="Custom container name" canGate="update"
                            :canResource="$application" />
                    </form>
                @else
                    <form wire:submit="saveCustomNamePrefix" class="w-full">
                        <x-unsaved-bar action="saveCustomNamePrefix" targets="customContainerNamePrefix" />
                        <x-forms.input
                            helper="Optional prefix for generated container names. Containers are named <span class='font-bold'>prefix-timestamp</span>, for example <span class='font-bold'>shop-api-20260908T141530</span>, instead of starting with <span class='font-bold'>{{ $application->uuid }}</span>.<br><br>The prefix is converted to slug format when saved, can be up to {{ \App\Models\ApplicationSetting::MAX_CONTAINER_NAME_PREFIX_LENGTH }} characters and must be unique on this server. Rolling updates keep working."
                            id="customContainerNamePrefix" label="Container name prefix" placeholder="e.g. my-api"
                            canGate="update" :canResource="$application" />
                    </form>
                @endif
            </div>
            <x-application.settings-subsection id="advanced-operations-section" title="Operations"
                helper="Shutdown and restart behavior for this application's containers.">
                <div class="grid w-full gap-4 lg:grid-cols-2">
                    <x-forms.input type="number" id="stopGracePeriod" label="Stop grace period (seconds)"
                        placeholder="{{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }}" wire:change="saveStopGracePeriod"
                        helper="How long to wait for graceful shutdown during rolling updates, manual stops, and restarts. Applies to all containers for this application. Saved automatically. Default: {{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }} seconds. Range: {{ MIN_STOP_GRACE_PERIOD_SECONDS }}-{{ MAX_STOP_GRACE_PERIOD_SECONDS }} seconds (1 hour)."
                        min="{{ MIN_STOP_GRACE_PERIOD_SECONDS }}" max="{{ MAX_STOP_GRACE_PERIOD_SECONDS }}"
                        canGate="update" :canResource="$application" />
                    @if ($application->build_pack !== 'dockercompose')
                        <x-forms.input type="number" min="0" max="{{ MAX_ROLLING_UPDATE_DRAIN_SECONDS }}"
                            id="rollingUpdateDrainSeconds" label="Rolling update drain time (seconds)"
                            wire:change="saveRollingUpdateDrainSeconds"
                            helper="How long to wait after the new container is healthy before the old containers stop during a rolling update. The proxy needs this time to send traffic to the new container. Saved automatically. Default: {{ DEFAULT_ROLLING_UPDATE_DRAIN_SECONDS }} seconds. Range: 0-{{ MAX_ROLLING_UPDATE_DRAIN_SECONDS }} seconds. Set to 0 to turn off the wait."
                            canGate="update" :canResource="$application" />
                    @endif
                    <x-forms.input type="number" min="0" id="maxRestartCount" label="Max restart count"
                        wire:change="saveMaxRestartCount"
                        helper="Maximum number of restarts before Coolify automatically stops the application and sends a notification. Saved automatically. Set to 0 to disable the limit."
                        canGate="update" :canResource="$application" />
                </div>
            </x-application.settings-subsection>
            <x-application.settings-subsection id="advanced-logs-section" title="Logs"
                helper="Forward container logs to an external endpoint.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isLogDrainEnabled" label="Log drain" onChange="instantSave"
                        helper="Drain logs to the log drain endpoint configured in your Server settings."
                        :options="[
                            ['value' => false, 'label' => 'Disabled'],
                            ['value' => true, 'label' => 'Send logs to the log drain endpoint'],
                        ]" :disabled="! $canUpdate" />
                </div>
            </x-application.settings-subsection>
            @if ($application->build_pack !== 'dockercompose')
                <x-application.settings-subsection id="advanced-gpu-section" title="GPU"
                    helper="Give this application access to the host's GPUs. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>.">
                    <x-slot:actions>
                        @php($canRefreshGpus = $gpuServers->contains(fn ($gpuServer) => $gpuServer->isFunctional()))
                        <x-forms.button type="button" wire:click="refreshGpus" canGate="update"
                            :canResource="$gpuServers->first()" :disabled="! $canRefreshGpus"
                            :tooltip="$canRefreshGpus ? null : 'Validate the server connection first.'">
                            <x-reicon name="refresh" class="size-3.5" />
                            Refresh GPUs
                        </x-forms.button>
                    </x-slot:actions>
                    <x-callout type="info" title="Detected GPUs" class="mb-5">
                        @foreach ($gpuServers as $gpuServer)
                            <div wire:key="gpu-server-{{ $gpuServer->id }}" @class(['mt-2' => ! $loop->first])>
                                <p class="font-medium text-neutral-700 dark:text-fg">
                                    <a href="{{ route('server.show', ['server_uuid' => $gpuServer->uuid]) }}"
                                        class="underline">{{ $gpuServer->name }}</a>
                                    @if (data_get($gpuServer->server_metadata, 'collected_at'))
                                        <span class="font-normal text-neutral-500 dark:text-fg-dim">· checked
                                            {{ \Illuminate\Support\Carbon::parse(data_get($gpuServer->server_metadata, 'collected_at'))->diffForHumans() }}</span>
                                    @endif
                                </p>
                                <x-server.gpu-list :server="$gpuServer" />
                            </div>
                        @endforeach
                    </x-callout>
                    <div class="grid w-full gap-4 sm:grid-cols-2">
                        <x-forms.listbox id="isGpuEnabled" label="GPU access" onChange="instantSave"
                            :options="[
                                ['value' => false, 'label' => 'Disabled'],
                                ['value' => true, 'label' => 'Enabled'],
                            ]" :disabled="! $canUpdate" />
                    </div>
                    @if ($isGpuEnabled)
                        <form id="gpu-settings-form" wire:submit="submit"
                            class="mt-5 flex w-full flex-col gap-4 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                            {{-- Scope to GPU form fields; sibling instantSave listboxes share this component. --}}
                            <x-unsaved-bar action="submit"
                                targets="gpuDriver,gpuCount,gpuDeviceIds,gpuOptions" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input label="GPU driver" id="gpuDriver" canGate="update" :canResource="$application" />
                                <x-forms.input label="GPU count" placeholder="Empty means use all GPUs" helper="A number, or all." id="gpuCount"
                                    canGate="update" :canResource="$application" />
                            </div>
                            <x-forms.input label="GPU device ids" placeholder="0,2"
                                helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                                id="gpuDeviceIds" canGate="update" :canResource="$application" />
                            <x-forms.textarea rows="6" label="GPU options" id="gpuOptions" placeholder="key=value"
                                helper="Driver options. Use one key=value pair per line, or a YAML mapping." canGate="update"
                                :canResource="$application" />
                        </form>
                    @endif
                </x-application.settings-subsection>
            @endif
        </x-application.settings-section>

        <x-application.settings-section id="advanced-proxy-section" title="Proxy"
            helper="How the proxy serves traffic for this application.">
            @if ($labelsManagedByCoolify)
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGzipEnabled" label="Gzip compression" onChange="instantSave"
                        helper="Some services compress data by default. In that case you do not need this."
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isStripprefixEnabled" label="Path prefixes" onChange="instantSave"
                        helper="Strip Prefix removes prefixes from paths, like /api/ to /."
                        :options="[
                            ['value' => true, 'label' => 'Strip prefixes'],
                            ['value' => false, 'label' => 'Keep paths as-is'],
                        ]" :disabled="! $canUpdate" />
                    @if ($isTraefikProxy)
                        <x-forms.listbox id="certificateResolverMode" label="Certificate resolver"
                            onChange="saveCertificateResolverMode"
                            helper="Traefik certificate resolver for this application's HTTPS routes. Choose <span class='font-bold'>None</span> for domains that use certificates from Traefik's dynamic configuration (for example Cloudflare Origin CA certificates). Redeploy the application to apply the change."
                            :options="[
                                ['value' => 'server', 'label' => 'Server setting ('.$serverCertificateResolver.')'],
                                ['value' => 'none', 'label' => 'None (use your own certificates)'],
                                ['value' => 'custom', 'label' => 'Custom resolver'],
                            ]" :disabled="! $canUpdate" />
                        @if ($certificateResolverMode === 'custom')
                            <form wire:submit="saveCertificateResolver" class="w-full">
                                <x-unsaved-bar action="saveCertificateResolver" targets="certificateResolverName" />
                                <x-forms.input id="certificateResolverName" label="Resolver name" placeholder="cloudflare"
                                    required
                                    helper="Name of a certificate resolver defined in your Traefik configuration. Letters, numbers, hyphens, and underscores only."
                                    canGate="update" :canResource="$application" />
                            </form>
                        @endif
                    @endif
                </div>
            @else
                <x-empty size="sm" title="Proxy behavior is managed through labels"
                    description="Container labels are managed manually for this application. Switch label management back to Coolify to configure the proxy here."
                    icon-name="globe">
                    <x-slot:contents>
                        <a class="button"
                            href="{{ route('project.application.configuration', $generalRouteParameters) }}#container-labels-section"
                            {{ wireNavigate() }}>
                            Go to Container labels
                        </a>
                    </x-slot:contents>
                </x-empty>
            @endif
        </x-application.settings-section>

    </div>
</div>
