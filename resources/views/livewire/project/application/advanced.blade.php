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
                    id="application.settings.is_auto_deploy_enabled" label="Auto Deploy" />
                <x-forms.checkbox
                    helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br><br>Closing a PR will delete Preview Deployments."
                    instantSave id="application.settings.is_preview_deployments_enabled" label="Preview Deployments" />
            @endif
            <x-forms.checkbox
                helper="Your application will be available only on https if your domain starts with https://..."
                instantSave id="is_force_https_enabled" label="Force Https" />
            <x-forms.checkbox label="Enable gzip compression"
                helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this."
                instantSave id="is_gzip_enabled" />
            <x-forms.checkbox helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api."
                instantSave id="is_stripprefix_enabled" label="Strip Prefixes" />
            @if ($application->build_pack === 'dockercompose')
                <x-forms.checkbox instantSave id="application.settings.is_raw_compose_deployment_enabled"
                    label="Raw Compose Deployment"
                    helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation.</a>" />
            @endif
            <h3>Container Names</h3>
            <x-forms.checkbox
                helper="The deployed container will have the same name ({{ $application->uuid }}). <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                instantSave id="application.settings.is_consistent_container_name_enabled"
                label="Consistent Container Names" />
            @if (!$application->settings->is_consistent_container_name_enabled)
                <form class="flex items-end gap-2 pl-2" wire:submit.prevent='saveCustomName'>
                    <x-forms.input
                        helper="You can add a custom name for your container.<br><br>The name will be converted to slug format when you save it. <span class='font-bold dark:text-warning'>You will lose the rolling update feature!</span>"
                        instantSave id="application.settings.custom_internal_name" label="Custom Container Name" />
                    <x-forms.button type="submit">Save</x-forms.button>
                </form>
            @endif
            @if ($application->build_pack === 'dockercompose')
                <h3>Network</h3>
                <x-forms.checkbox instantSave id="application.settings.connect_to_docker_network"
                    label="Connect To Predefined Network"
                    helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>this</a>." />
            @endif
            @if (!$application->settings->is_raw_compose_deployment_enabled)
                <h3>Logs</h3>
                <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                    instantSave id="application.settings.is_log_drain_enabled" label="Drain Logs" />
            @endif

            @if ($application->git_based())
                <h3>Git</h3>
                <x-forms.checkbox instantSave id="application.settings.is_git_submodules_enabled" label="Submodules"
                    helper="Allow Git Submodules during build process." />
                <x-forms.checkbox instantSave id="application.settings.is_git_lfs_enabled" label="LFS"
                    helper="Allow Git LFS during build process." />
            @endif
            {{-- <x-forms.checkbox disabled instantSave id="is_dual_cert" label="Dual Certs?" />
            <x-forms.checkbox disabled instantSave id="is_custom_ssl" label="Is Custom SSL?" />
            <x-forms.checkbox disabled instantSave id="is_http2" label="Is Http2?" /> --}}
        </div>
        @if ($application->build_pack !== 'dockercompose')
            <h3>GPU</h3>
        @endif
        <form wire:submit="submit">
            @if ($application->build_pack !== 'dockercompose')
                <x-forms.checkbox
                    helper="Enable GPU usage for this application. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>."
                    instantSave id="application.settings.is_gpu_enabled" label="Attach GPU" />
                @if ($application->settings->is_gpu_enabled)
                    <h5>GPU Settings</h5>

                    <x-forms.button type="submit">Save</x-forms.button>
                @endif
            @endif
            @if ($application->settings->is_gpu_enabled)
                <div class="flex flex-col w-full gap-2 p-2 xl:flex-row">
                    <x-forms.input label="GPU Driver" id="application.settings.gpu_driver"> </x-forms.input>
                    <x-forms.input label="GPU Count" placeholder="empty means use all GPUs"
                        id="application.settings.gpu_count"> </x-forms.input>
                    <x-forms.input label="GPU Device Ids" placeholder="0,2"
                        helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                        id="application.settings.gpu_device_ids"> </x-forms.input>

                </div>
                <div class="px-2">
                    <x-forms.textarea label="GPU Options" id="application.settings.gpu_options">
                    </x-forms.textarea>
                </div>
            @endif
        </form>
    </div>
</div>
