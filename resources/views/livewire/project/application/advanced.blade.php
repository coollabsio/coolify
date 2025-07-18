<div>
    <div class="flex flex-col md:w-96">
        <div class="flex items-center gap-2">
            <h2>Advanced</h2>
        </div>
        <div>Advanced configuration for your application.</div>
        <div class="flex flex-col gap-1 pt-4">
            <h3>General</h3>
            @if ($application->git_based())
                <x-forms.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                    id="isAutoDeployEnabled" label="Auto Deploy" />
                <x-forms.checkbox
                    helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br><br>Closing a PR will delete Preview Deployments."
                    instantSave id="isPreviewDeploymentsEnabled" label="Preview Deployments" />
            @endif
            <x-forms.checkbox helper="Disable Docker build cache on every deployment." instantSave id="disableBuildCache"
                label="Disable Build Cache" />

            @if ($application->settings->is_container_label_readonly_enabled)
                <x-forms.checkbox
                    helper="Your application will be available only on https if your domain starts with https://..."
                    instantSave id="isForceHttpsEnabled" label="Force Https" />
                <x-forms.checkbox label="Enable Gzip Compression"
                    helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this."
                    instantSave id="isGzipEnabled" />
                <x-forms.checkbox helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api."
                    instantSave id="isStripprefixEnabled" label="Strip Prefixes" />
            @else
                <x-forms.checkbox disabled
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." instantSave
                    id="isForceHttpsEnabled" label="Force Https" />
                <x-forms.checkbox label="Enable Gzip Compression" disabled
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." instantSave
                    id="isGzipEnabled" />
                <x-forms.checkbox
                    helper="Readonly labels are disabled. You need to set the labels in the labels section." disabled
                    instantSave id="isStripprefixEnabled" label="Strip Prefixes" />
            @endif
            @if ($application->build_pack === 'dockercompose')
                <h3>Docker Compose</h3>
                <x-forms.checkbox instantSave id="isRawComposeDeploymentEnabled" label="Raw Compose Deployment"
                    helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation.</a>" />
            @endif
            <h3 class="pt-4">Container Names</h3>
            <x-forms.checkbox
                helper="The deployed container will have the same name ({{ $application->uuid }}). <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                instantSave id="isConsistentContainerNameEnabled" label="Consistent Container Names" />
            @if ($isConsistentContainerNameEnabled === false)
                <form class="flex items-end gap-2 " wire:submit.prevent='saveCustomName'>
                    <x-forms.input
                        helper="You can add a custom name for your container.<br><br>The name will be converted to slug format when you save it. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                        instantSave id="customInternalName" label="Custom Container Name" />
                    <x-forms.button type="submit">Save</x-forms.button>
                </form>
            @endif
            @if ($application->build_pack === 'dockercompose')
                <h3 class="pt-4">Network</h3>
                <x-forms.checkbox instantSave id="isConnectToDockerNetworkEnabled" label="Connect To Predefined Network"
                    helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>this</a>." />
            @endif
            <h3 class="pt-4">Logs</h3>
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave id="isLogDrainEnabled" label="Drain Logs" />
            @if ($application->git_based())
                <h3>Git</h3>
                <x-forms.checkbox instantSave id="isGitSubmodulesEnabled" label="Submodules"
                    helper="Allow Git Submodules during build process." />
                <x-forms.checkbox instantSave id="isGitLfsEnabled" label="LFS"
                    helper="Allow Git LFS during build process." />
            @endif
        </div>

    </div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        @if ($application->build_pack !== 'dockercompose')
            <div class="flex gap-2 items-end pt-4">
                <h3>GPU</h3>
                @if ($isGpuEnabled)
                    <x-forms.button type="submit">Save</x-forms.button>
                @endif
            </div>
        @endif
        @if ($application->build_pack !== 'dockercompose')
            <div class="md:w-96 pb-4">
                <x-forms.checkbox
                    helper="Enable GPU usage for this application. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>."
                    instantSave id="isGpuEnabled" label="Enable GPU" />
            </div>
        @endif
        @if ($isGpuEnabled)
            <div class="flex flex-col w-full gap-2 ">
                <div class="flex gap-2 items-end">
                    <x-forms.input label="GPU Driver" id="gpuDriver"> </x-forms.input>
                    <x-forms.input label="GPU Count" placeholder="empty means use all GPUs" id="gpuCount">
                    </x-forms.input>
                </div>

                <x-forms.input label="GPU Device Ids" placeholder="0,2"
                    helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                    id="gpuDeviceIds"> </x-forms.input>
                <x-forms.textarea rows="10" label="GPU Options" id="gpuOptions"> </x-forms.textarea>
            </div>
        @endif
    </form>
</div>
