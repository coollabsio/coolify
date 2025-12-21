<div>
    <div class="flex flex-col md:w-96">
        <div class="flex items-center gap-2">
            <h2>{{ __('application.advanced') }}</h2>
        </div>
        <div>{{ __('application.advanced_configuration_desc') }}</div>
        <div class="flex flex-col gap-1 pt-4">
            <h3>{{ __('application.general_section') }}</h3>
            @if ($application->git_based())
                <x-forms.checkbox helper="{{ __('application.auto_deploy_helper') }}" instantSave
                    id="isAutoDeployEnabled" label="{{ __('application.auto_deploy') }}" canGate="update" :canResource="$application" />
                <x-forms.checkbox
                    helper="{{ __('application.preview_deployments_helper') }}"
                    instantSave id="isPreviewDeploymentsEnabled" label="{{ __('application.preview_deployments') }}" canGate="update"
                    :canResource="$application" />
                <x-forms.checkbox
                    helper="{{ __('application.allow_public_pr_deployments_helper') }}"
                    instantSave id="isPrDeploymentsPublicEnabled" label="{{ __('application.allow_public_pr_deployments') }}" canGate="update"
                    :canResource="$application" :disabled="!$isPreviewDeploymentsEnabled" />
            @endif
            <x-forms.checkbox helper="{{ __('application.disable_build_cache_helper') }}" instantSave
                id="disableBuildCache" label="{{ __('application.disable_build_cache') }}" canGate="update" :canResource="$application" />
            <x-forms.checkbox
                helper="{{ __('application.inject_build_args_helper') }}"
                instantSave id="injectBuildArgsToDockerfile" label="{{ __('application.inject_build_args_to_dockerfile') }}" canGate="update"
                :canResource="$application" />
            <x-forms.checkbox
                helper="{{ __('application.include_source_commit_helper') }}"
                instantSave id="includeSourceCommitInBuild" label="{{ __('application.include_source_commit_in_build') }}" canGate="update"
                :canResource="$application" />

            @if ($application->settings->is_container_label_readonly_enabled)
                <x-forms.checkbox
                    helper="{{ __('application.force_https_helper') }}"
                    instantSave id="isForceHttpsEnabled" label="{{ __('application.force_https') }}" canGate="update" :canResource="$application" />
                <x-forms.checkbox label="{{ __('application.enable_gzip_compression') }}"
                    helper="{{ __('application.enable_gzip_helper') }}"
                    instantSave id="isGzipEnabled" canGate="update" :canResource="$application" />
                <x-forms.checkbox helper="{{ __('application.strip_prefixes_helper') }}"
                    instantSave id="isStripprefixEnabled" label="{{ __('application.strip_prefixes') }}" canGate="update" :canResource="$application" />
            @else
                <x-forms.checkbox disabled
                    helper="{{ __('application.readonly_labels_disabled') }}" instantSave
                    id="isForceHttpsEnabled" label="{{ __('application.force_https') }}" canGate="update" :canResource="$application" />
                <x-forms.checkbox label="{{ __('application.enable_gzip_compression') }}" disabled
                    helper="{{ __('application.readonly_labels_disabled') }}" instantSave
                    id="isGzipEnabled" canGate="update" :canResource="$application" />
                <x-forms.checkbox
                    helper="{{ __('application.readonly_labels_disabled') }}" disabled
                    instantSave id="isStripprefixEnabled" label="{{ __('application.strip_prefixes') }}" canGate="update" :canResource="$application" />
            @endif
            @if ($application->build_pack === 'dockercompose')
                <h3>{{ __('application.docker_compose_section') }}</h3>
                <x-forms.checkbox instantSave id="isRawComposeDeploymentEnabled" label="{{ __('application.raw_compose_deployment') }}"
                    helper="{{ __('application.raw_compose_warning') }}"
                    canGate="update" :canResource="$application" />
            @endif
            <h3 class="pt-4">{{ __('application.container_names') }}</h3>
            <x-forms.checkbox
                helper="{{ str_replace(':uuid', $application->uuid, __('application.consistent_container_names_helper')) }}"
                instantSave id="isConsistentContainerNameEnabled" label="{{ __('application.consistent_container_names') }}" canGate="update"
                :canResource="$application" />
            @if ($isConsistentContainerNameEnabled === false)
                <form class="flex items-end gap-2 " wire:submit.prevent='saveCustomName'>
                    <x-forms.input
                        helper="{{ __('application.custom_container_name_helper') }}"
                        instantSave id="customInternalName" label="{{ __('application.custom_container_name') }}" canGate="update"
                        :canResource="$application" />
                    <x-forms.button canGate="update" :canResource="$application" type="submit">{{ __('common.save') }}</x-forms.button>
                </form>
            @endif
            @if ($application->build_pack === 'dockercompose')
                <h3 class="pt-4">{{ __('application.network') }}</h3>
                <x-forms.checkbox instantSave id="isConnectToDockerNetworkEnabled" label="{{ __('application.connect_to_predefined_network') }}"
                    helper="{{ __('application.connect_to_predefined_network_helper') }}"
                    canGate="update" :canResource="$application" />
            @endif
            <h3 class="pt-4">{{ __('application.logs_section') }}</h3>
            <x-forms.checkbox helper="{{ __('application.drain_logs_helper') }}"
                instantSave id="isLogDrainEnabled" label="{{ __('application.drain_logs') }}" canGate="update" :canResource="$application" />
            @if ($application->git_based())
                <h3>{{ __('application.git_section') }}</h3>
                <x-forms.checkbox instantSave id="isGitSubmodulesEnabled" label="{{ __('application.submodules') }}"
                    helper="{{ __('application.submodules_helper') }}" canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave id="isGitLfsEnabled" label="{{ __('application.lfs') }}"
                    helper="{{ __('application.lfs_helper') }}" canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave id="isGitShallowCloneEnabled" label="{{ __('application.shallow_clone') }}"
                    helper="{{ __('application.shallow_clone_helper') }}"
                    canGate="update" :canResource="$application" />
            @endif
        </div>

    </div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        @if ($application->build_pack !== 'dockercompose')
            <div class="flex gap-2 items-end pt-4">
                <h3>{{ __('application.gpu') }}</h3>
                @if ($isGpuEnabled)
                    <x-forms.button canGate="update" :canResource="$application" type="submit">{{ __('common.save') }}</x-forms.button>
                @endif
            </div>
        @endif
        @if ($application->build_pack !== 'dockercompose')
            <div class="md:w-96 pb-4">
                <x-forms.checkbox
                    helper="{{ __('application.enable_gpu_helper') }}"
                    instantSave id="isGpuEnabled" label="{{ __('application.enable_gpu') }}" canGate="update" :canResource="$application" />
            </div>
        @endif
        @if ($isGpuEnabled)
            <div class="flex flex-col w-full gap-2 ">
                <div class="flex gap-2 items-end">
                    <x-forms.input label="{{ __('application.gpu_driver') }}" id="gpuDriver" canGate="update" :canResource="$application">
                    </x-forms.input>
                    <x-forms.input label="{{ __('application.gpu_count') }}" placeholder="{{ __('application.gpu_count_placeholder') }}" id="gpuCount"
                        canGate="update" :canResource="$application">
                    </x-forms.input>
                </div>
                <x-forms.input label="{{ __('application.gpu_device_ids') }}" placeholder="{{ __('application.gpu_device_ids_placeholder') }}"
                    helper="{{ __('application.gpu_device_ids_helper') }}"
                    id="gpuDeviceIds" canGate="update" :canResource="$application"> </x-forms.input>
                <x-forms.textarea rows="10" label="{{ __('application.gpu_options') }}" id="gpuOptions" canGate="update"
                    :canResource="$application"> </x-forms.textarea>
            </div>
        @endif
    </form>
</div>
