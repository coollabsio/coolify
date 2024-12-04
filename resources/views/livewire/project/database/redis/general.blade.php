<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="database.name" />
            <x-forms.input label="Description" id="database.description" />
            <x-forms.input label="Image" id="database.image" required
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/redis'>https://hub.docker.com/_/redis</a>" />
        </div>
        <div class="flex flex-col gap-2">
            @if (version_compare($redis_version, '6.0', '>='))
                <x-forms.input label="Username" id="redis_username" required
                    helper="You can change the Redis Username in the input field below or by editing the value of the REDIS_USERNAME environment variable.
                    <br><br>
                    If you change the Redis Username in the database, please sync it here, otherwise automations (like backups) won't work.
                    <br><br>
                    Note: If the environment variable REDIS_USERNAME is set as a shared variable (environment, project, or team-based), this input field will become read-only."
                    :disabled="$this->isSharedVariable('REDIS_USERNAME')" />
            @endif
            <x-forms.input label="Password" id="redis_password" type="password" required
                helper="You can change the Redis Password in the input field below or by editing the value of the REDIS_PASSWORD environment variable.
                <br><br>
                If you change the Redis Password in the database, please sync it here, otherwise automations (like backups) won't work.
                <br><br>
                Note: If the environment variable REDIS_PASSWORD is set as a shared variable (environment, project, or team-based), this input field will become read-only."
                :disabled="$this->isSharedVariable('REDIS_PASSWORD')" />
        </div>
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
            id="database.custom_docker_run_options" label="Custom Docker Options" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="database.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433" />
            </div>
            <x-forms.input label="Redis URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="Redis URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="db_url_public" />
            @endif
        </div>
        <div>
            <div class="flex flex-col py-2 w-64">
                <div class="flex items-center gap-2 pb-2">
                    <div class="flex items-center">
                        <h3>Proxy</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                    </div>
                    @if (data_get($database, 'is_public'))
                        <x-slide-over fullScreen>
                            <x-slot:title>Proxy Logs</x-slot:title>
                            <x-slot:content>
                                <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                    container="{{ data_get($database, 'uuid') }}-proxy" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !data_get($database, 'is_public') }}"
                                @click="slideOverOpen=true">Logs</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="database.is_public" label="Make it publicly available" />
            </div>
            <x-forms.input placeholder="5432" disabled="{{ data_get($database, 'is_public') }}"
                id="database.public_port" label="Public Port" />
        </div>
        <x-forms.textarea
            helper="<a target='_blank' class='underline dark:text-white' href='https://raw.githubusercontent.com/redis/redis/7.2/redis.conf'>Redis Default Configuration</a>"
            label="Custom Redis Configuration" rows="10" id="database.redis_conf" />
        <h3 class="pt-4">Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="database.is_log_drain_enabled" label="Drain Logs" />
        </div>

    </form>
</div>
