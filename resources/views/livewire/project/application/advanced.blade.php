<div>
    @php
        $canUpdate = auth()->user()->can('update', $application);
        $labelsManagedByCoolify = $application->settings->is_container_label_readonly_enabled;
        $generalRouteParameters = [
            'project_uuid' => request()->route('project_uuid'),
            'environment_uuid' => request()->route('environment_uuid'),
            'application_uuid' => request()->route('application_uuid'),
        ];
    @endphp

    <div class="flex flex-col gap-6">
        <x-application.settings-section id="advanced-build-section" title="Build"
            helper="Fine-tune how images are built for this application.">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="disableBuildCache" label="Build cache" onChange="instantSave"
                    helper="Disabling the cache forces a completely fresh Docker build on every deployment."
                    :options="[
                        ['value' => false, 'label' => 'Use Docker build cache'],
                        ['value' => true, 'label' => 'Rebuild from scratch every time'],
                    ]" x-bind:disabled="@js(!$canUpdate)" />
                <x-forms.listbox id="injectBuildArgsToDockerfile" label="Build arguments" onChange="instantSave"
                    helper="When injected automatically, Coolify adds ARG statements to your Dockerfile for build-time variables. Manage them manually to preserve Docker build cache."
                    :options="[
                        ['value' => true, 'label' => 'Inject build args automatically'],
                        ['value' => false, 'label' => 'Managed manually in Dockerfile'],
                    ]" x-bind:disabled="@js(!$canUpdate)" />
                <x-forms.listbox id="includeSourceCommitInBuild" label="Source commit availability" onChange="instantSave"
                    helper="SOURCE_COMMIT (git commit hash) is always available at runtime. Making it available during build invalidates the cache on every commit."
                    :options="[
                        ['value' => false, 'label' => 'Runtime only (preserves cache)'],
                        ['value' => true, 'label' => 'Available during build'],
                    ]" x-bind:disabled="@js(!$canUpdate)" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="advanced-container-section" title="Container"
            helper="Control how the deployed container is named.">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isConsistentContainerNameEnabled" label="Container naming" onChange="instantSave"
                    helper="With a consistent name the container is always called {{ $application->uuid }}. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                    :options="[
                        ['value' => false, 'label' => 'Generated name (rolling updates)'],
                        ['value' => true, 'label' => 'Consistent name (no rolling updates)'],
                    ]" x-bind:disabled="@js(!$canUpdate)" />
                @if ($isConsistentContainerNameEnabled === false)
                    <x-forms.input
                        helper="You can add a custom name for your container.<br><br>The name is saved automatically and converted to slug format. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                        id="customInternalName" label="Custom container name" canGate="update"
                        wire:change="saveCustomName" :canResource="$application" />
                @endif
            </div>
        </x-application.settings-section>

        @if ($application->git_based())
            <x-application.settings-section id="advanced-deployment-section" title="Deployment"
                helper="Automatic deployments and pull request previews.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isAutoDeployEnabled" label="Auto deploy" onChange="instantSave"
                        helper="Automatically deploy new commits based on Git webhooks."
                        :options="[
                            ['value' => true, 'label' => 'Deploy on push (webhooks)'],
                            ['value' => false, 'label' => 'Manual deployments only'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isPreviewDeploymentsEnabled" label="Preview deployments" onChange="instantSave"
                        helper="Automatically deploy Preview Deployments for all opened PRs.<br><br>Closing a PR deletes its Preview Deployment."
                        :options="[
                            ['value' => false, 'label' => 'Disabled'],
                            ['value' => true, 'label' => 'Deploy opened pull requests'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isPrDeploymentsPublicEnabled" label="PR deployment access" onChange="instantSave"
                        helper="When public, anyone can trigger PR deployments. Otherwise fork PRs are blocked and only repository owners, members, and collaborators can trigger them."
                        :options="[
                            ['value' => false, 'label' => 'Repository members only'],
                            ['value' => true, 'label' => 'Public (fork PRs allowed)'],
                        ]" x-bind:disabled="@js(!$canUpdate || !$isPreviewDeploymentsEnabled)" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="advanced-git-section" title="Git"
                helper="Options applied while cloning the repository during builds.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGitSubmodulesEnabled" label="Submodules" onChange="instantSave"
                        helper="Allow Git submodules during the build process."
                        :options="[
                            ['value' => true, 'label' => 'Clone submodules'],
                            ['value' => false, 'label' => 'Skip submodules'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isGitLfsEnabled" label="Git LFS" onChange="instantSave"
                        helper="Allow Git LFS during the build process."
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isGitShallowCloneEnabled" label="Clone depth" onChange="instantSave"
                        helper="Shallow cloning (--depth=1) speeds up deployments by only fetching the latest commit — useful for large repositories."
                        :options="[
                            ['value' => false, 'label' => 'Full history'],
                            ['value' => true, 'label' => 'Shallow clone (latest commit only)'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                </div>
            </x-application.settings-section>
        @endif

        @if ($application->build_pack === 'dockercompose')
            <x-application.settings-section id="advanced-compose-section" title="Docker compose"
                helper="Advanced behavior for compose-based deployments.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isRawComposeDeploymentEnabled" label="Compose deployment" onChange="instantSave"
                        helper="WARNING: Advanced use cases only. In raw mode your compose file is deployed as-is — nothing is modified by Coolify and you need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation</a>."
                        :options="[
                            ['value' => false, 'label' => 'Managed by Coolify'],
                            ['value' => true, 'label' => 'Raw (deploy file as-is)'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isConnectToDockerNetworkEnabled" label="Predefined network" onChange="instantSave"
                        helper="By default a compose resource only gets its own internal network. Connecting to a Coolify predefined network may require different internal DNS names. More info <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>here</a>."
                        :options="[
                            ['value' => false, 'label' => 'Isolated network only'],
                            ['value' => true, 'label' => 'Connect to predefined network'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                </div>
            </x-application.settings-section>
        @endif

        <x-application.settings-section id="advanced-proxy-section" title="Proxy"
            helper="How the proxy serves traffic for this application.">
            @if ($labelsManagedByCoolify)
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGzipEnabled" label="Gzip compression" onChange="instantSave"
                        helper="Some services compress data by default — in that case you do not need this."
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                    <x-forms.listbox id="isStripprefixEnabled" label="Path prefixes" onChange="instantSave"
                        helper="Strip Prefix removes prefixes from paths, like /api/ to /."
                        :options="[
                            ['value' => true, 'label' => 'Strip prefixes'],
                            ['value' => false, 'label' => 'Keep paths as-is'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                </div>
            @else
                <x-empty size="sm" title="Proxy behavior is managed through labels"
                    description="Container labels are managed manually for this application. Switch label management back to Coolify to configure the proxy here.">
                    <x-slot:icon>
                        <x-reicon name="globe" class="size-8" />
                    </x-slot:icon>
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

        <x-application.settings-section id="advanced-operations-section" title="Operations"
            helper="Shutdown and restart behavior for this application's containers.">
            <div class="grid w-full gap-4 lg:grid-cols-2">
                <x-forms.input type="number" id="stopGracePeriod" label="Stop grace period (seconds)"
                    placeholder="{{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }}" wire:change="saveStopGracePeriod"
                    helper="How long to wait for graceful shutdown during rolling updates, manual stops, and restarts. Applies to all containers for this application. Saved automatically. Default: {{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }} seconds. Range: {{ MIN_STOP_GRACE_PERIOD_SECONDS }}-{{ MAX_STOP_GRACE_PERIOD_SECONDS }} seconds (1 hour)."
                    min="{{ MIN_STOP_GRACE_PERIOD_SECONDS }}" max="{{ MAX_STOP_GRACE_PERIOD_SECONDS }}"
                    canGate="update" :canResource="$application" />
                <x-forms.input type="number" min="0" id="maxRestartCount" label="Max restart count"
                    wire:change="saveMaxRestartCount"
                    helper="Maximum number of crash restarts before Coolify automatically stops the application and sends a notification. Saved automatically. Set to 0 to disable the limit."
                    canGate="update" :canResource="$application" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="advanced-logs-section" title="Logs"
            helper="Forward container logs to an external endpoint.">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isLogDrainEnabled" label="Log drain" onChange="instantSave"
                    helper="Drain logs to the log drain endpoint configured in your Server settings."
                    :options="[
                        ['value' => false, 'label' => 'Disabled'],
                        ['value' => true, 'label' => 'Send logs to the log drain endpoint'],
                    ]" x-bind:disabled="@js(!$canUpdate)" />
            </div>
        </x-application.settings-section>

        @if ($application->build_pack !== 'dockercompose')
            <x-application.settings-section id="advanced-gpu-section" title="GPU"
                helper="Give this application access to the host's GPUs. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGpuEnabled" label="GPU access" onChange="instantSave"
                        :options="[
                            ['value' => false, 'label' => 'Disabled'],
                            ['value' => true, 'label' => 'Enabled'],
                        ]" x-bind:disabled="@js(!$canUpdate)" />
                </div>
                @if ($isGpuEnabled)
                    <form id="gpu-settings-form" wire:submit="submit"
                        class="mt-5 flex w-full flex-col gap-4 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                        <x-unsaved-bar action="submit" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input label="GPU driver" id="gpuDriver" canGate="update" :canResource="$application" />
                            <x-forms.input label="GPU count" placeholder="Empty means use all GPUs" id="gpuCount"
                                canGate="update" :canResource="$application" />
                        </div>
                        <x-forms.input label="GPU device ids" placeholder="0,2"
                            helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                            id="gpuDeviceIds" canGate="update" :canResource="$application" />
                        <x-forms.textarea rows="6" label="GPU options" id="gpuOptions" canGate="update"
                            :canResource="$application" />
                    </form>
                @endif
            </x-application.settings-section>
        @endif
    </div>
</div>
