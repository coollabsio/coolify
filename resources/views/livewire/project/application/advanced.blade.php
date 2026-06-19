<div>
    <div class="flex flex-col md:w-96">
        <div class="flex items-center gap-2">
            <h2>Advanced</h2>
        </div>
        <div>Advanced configuration for your application.</div>
        <div class="flex flex-col gap-1 pt-4">
            <h3>Build</h3>
            <x-forms.checkbox helper="Disable Docker build cache on every deployment." instantSave
                id="disableBuildCache" label="Disable Build Cache" canGate="update" :canResource="$application" />
            <x-forms.checkbox
                helper="When enabled, Coolify automatically adds ARG statements to your Dockerfile for build-time variables. Disable this if you manage ARGs manually in your Dockerfile to preserve Docker build cache."
                instantSave id="injectBuildArgsToDockerfile" label="Inject Build Args to Dockerfile" canGate="update"
                :canResource="$application" />
            <x-forms.checkbox
                helper="When enabled, SOURCE_COMMIT (git commit hash) is available during Docker build. Disable to preserve cache across different commits - SOURCE_COMMIT will still be available at runtime."
                instantSave id="includeSourceCommitInBuild" label="Include Source Commit in Build" canGate="update"
                :canResource="$application" />

            <h3 class="pt-4">Container</h3>
            <x-forms.checkbox
                helper="The deployed container will have the same name ({{ $application->uuid }}). <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                instantSave id="isConsistentContainerNameEnabled" label="Consistent Container Names" canGate="update"
                :canResource="$application" />
            @if ($isConsistentContainerNameEnabled === false)
                <form class="flex items-end gap-2 " wire:submit.prevent='saveCustomName'>
                    <x-forms.input
                        helper="You can add a custom name for your container.<br><br>The name will be converted to slug format when you save it. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                        instantSave id="customInternalName" label="Custom Container Name" canGate="update"
                        :canResource="$application" />
                    <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
                </form>
            @endif

            @if ($application->git_based())
                <h3 class="pt-4">Deployment</h3>
                <x-forms.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                    id="isAutoDeployEnabled" label="Auto Deploy" canGate="update" :canResource="$application" />
                <x-forms.checkbox
                    helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br><br>Closing a PR will delete Preview Deployments."
                    instantSave id="isPreviewDeploymentsEnabled" label="Preview Deployments" canGate="update"
                    :canResource="$application" />
                <x-forms.checkbox
                    helper="When enabled, anyone can trigger PR deployments. When disabled, fork PRs are blocked and only repository owners, members, and collaborators can trigger PR deployments."
                    instantSave id="isPrDeploymentsPublicEnabled" label="Allow Public PR Deployments" canGate="update"
                    :canResource="$application" :disabled="!$isPreviewDeploymentsEnabled" />

                <h3 class="pt-4">Git</h3>
                <x-forms.checkbox instantSave id="isGitSubmodulesEnabled" label="Submodules"
                    helper="Allow Git Submodules during build process." canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave id="isGitLfsEnabled" label="LFS"
                    helper="Allow Git LFS during build process." canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave id="isGitShallowCloneEnabled" label="Shallow Clone"
                    helper="Use shallow cloning (--depth=1) to speed up deployments by only fetching the latest commit history. This reduces clone time and resource usage, especially for large repositories."
                    canGate="update" :canResource="$application" />
            @endif

            @if ($application->build_pack === 'dockercompose')
                <h3 class="pt-4">Docker Compose</h3>
                <x-forms.checkbox instantSave id="isRawComposeDeploymentEnabled" label="Raw Compose Deployment"
                    helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation.</a>"
                    canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave id="isConnectToDockerNetworkEnabled" label="Connect To Predefined Network"
                    helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>this</a>."
                    canGate="update" :canResource="$application" />
            @endif

            <h3 class="pt-4">Proxy</h3>
            @if ($application->settings->is_container_label_readonly_enabled)
                <x-forms.checkbox
                    helper="Your application will be available only on https if your domain starts with https://..."
                    instantSave id="isForceHttpsEnabled" label="Force Https" canGate="update" :canResource="$application" />
                <x-forms.checkbox label="Enable Gzip Compression"
                    helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this."
                    instantSave id="isGzipEnabled" canGate="update" :canResource="$application" />
                <x-forms.checkbox helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api."
                    instantSave id="isStripprefixEnabled" label="Strip Prefixes" canGate="update" :canResource="$application" />
            @else
                <x-forms.checkbox disabled
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." instantSave
                    id="isForceHttpsEnabled" label="Force Https" canGate="update" :canResource="$application" />
                <x-forms.checkbox label="Enable Gzip Compression" disabled
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." instantSave
                    id="isGzipEnabled" canGate="update" :canResource="$application" />
                <x-forms.checkbox
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." disabled
                    instantSave id="isStripprefixEnabled" label="Strip Prefixes" canGate="update" :canResource="$application" />
            @endif
            <h3 class="pt-4">Operations</h3>
            <form class="flex items-end gap-2" wire:submit.prevent='saveStopGracePeriod'>
                <x-forms.input
                    type="number"
                    id="stopGracePeriod"
                    label="Stop Grace Period (seconds)"
                    placeholder="{{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }}"
                    helper="How long to wait for graceful shutdown during rolling updates, manual stops, and restarts. Applies to all containers for this application. Default: {{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }} seconds. Range: {{ MIN_STOP_GRACE_PERIOD_SECONDS }}-{{ MAX_STOP_GRACE_PERIOD_SECONDS }} seconds (1 hour)."
                    min="{{ MIN_STOP_GRACE_PERIOD_SECONDS }}"
                    max="{{ MAX_STOP_GRACE_PERIOD_SECONDS }}"
                    canGate="update"
                    :canResource="$application"
                />
                <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
            </form>
            <form class="flex items-end gap-2" wire:submit.prevent='saveMaxRestartCount'>
                <x-forms.input
                    type="number"
                    min="0"
                    helper="Maximum number of crash restarts before Coolify automatically stops the application and sends a notification. Set to 0 to disable the limit."
                    id="maxRestartCount"
                    label="Max Restart Count"
                    canGate="update"
                    :canResource="$application"
                />
                <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
            </form>
            <h3 class="pt-4">Logs</h3>
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave id="isLogDrainEnabled" label="Drain Logs" canGate="update" :canResource="$application" />
        </div>

    </div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        @if ($application->build_pack !== 'dockercompose')
            <div class="flex gap-2 items-end pt-4">
                <h3>GPU</h3>
                @if ($isGpuEnabled)
                    <x-forms.button canGate="update" :canResource="$application" type="submit">Save</x-forms.button>
                @endif
            </div>
        @endif
        @if ($application->build_pack !== 'dockercompose')
            <div class="md:w-96 pb-4">
                <x-forms.checkbox
                    helper="Enable GPU usage for this application. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>."
                    instantSave id="isGpuEnabled" label="Enable GPU" canGate="update" :canResource="$application" />
            </div>
        @endif
        @if ($isGpuEnabled)
            <div class="flex flex-col w-full gap-2 ">
                <div class="flex gap-2 items-end">
                    <x-forms.input label="GPU Driver" id="gpuDriver" canGate="update" :canResource="$application">
                    </x-forms.input>
                    <x-forms.input label="GPU Count" placeholder="empty means use all GPUs" id="gpuCount"
                        canGate="update" :canResource="$application">
                    </x-forms.input>
                </div>
                <x-forms.input label="GPU Device Ids" placeholder="0,2"
                    helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                    id="gpuDeviceIds" canGate="update" :canResource="$application"> </x-forms.input>
                <x-forms.textarea rows="10" label="GPU Options" id="gpuOptions" canGate="update"
                    :canResource="$application"> </x-forms.textarea>
            </div>
        @endif
    </form>
</div>
