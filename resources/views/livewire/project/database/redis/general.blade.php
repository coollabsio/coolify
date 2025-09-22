<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="database.name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="database.description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="database.image" required canGate="update" :canResource="$database"
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/redis'>https://hub.docker.com/_/redis</a>" />
        </div>
        <div class="flex flex-col gap-2">
            @if ($database->started_at)
                <div class="pt-2 dark:text-warning">If you change the values in the database, please sync it here,
                    otherwise
                    automations won't work. <br>Changing them here will not change the values in the database.
                </div>
                <div class="flex gap-2">
                    @if (version_compare($redis_version, '6.0', '>='))
                        <x-forms.input label="Username" id="redis_username"
                            helper="You can only change this in the database." canGate="update" :canResource="$database" />
                    @endif
                    <x-forms.input label="Password" id="redis_password" type="password"
                        helper="You can only change this in the database." canGate="update" :canResource="$database" />
                </div>
            @else
                <div class="pt-2 dark:text-warning">You can only change the username and password in the database after
                    initial start.</div>
                <div class="flex gap-2">
                    @if (version_compare($redis_version, '6.0', '>='))
                        <x-forms.input label="Username" id="redis_username" required
                            helper="You can change the Redis Username in the input field below or by editing the value of the REDIS_USERNAME environment variable.
                    <br><br>
                    If you change the Redis Username in the database, please sync it here, otherwise automations (like backups) won't work.
                    <br><br>
                    Note: If the environment variable REDIS_USERNAME is set as a shared variable (environment, project, or team-based), this input field will become read-only."
                            :disabled="$this->isSharedVariable('REDIS_USERNAME')" canGate="update" :canResource="$database" />
                    @endif
                    <x-forms.input label="Password" id="redis_password" type="password" required
                        helper="You can change the Redis Password in the input field below or by editing the value of the REDIS_PASSWORD environment variable.
                <br><br>
                If you change the Redis Password in the database, please sync it here, otherwise automations (like backups) won't work.
                <br><br>
                Note: If the environment variable REDIS_PASSWORD is set as a shared variable (environment, project, or team-based), this input field will become read-only."
                        :disabled="$this->isSharedVariable('REDIS_PASSWORD')" canGate="update" :canResource="$database" />
                </div>
            @endif
        </div>
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
            id="database.custom_docker_run_options" label="Custom Docker Options" canGate="update" :canResource="$database" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="database.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input label="Redis URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="db_url" canGate="update" :canResource="$database" />
            @if ($db_url_public)
                <x-forms.input label="Redis URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="db_url_public" canGate="update" :canResource="$database" />
            @endif
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center justify-between w-full">
                    <h3>SSL Configuration</h3>
                    @if ($database->enable_ssl && $certificateValidUntil)
                        <x-modal-confirmation title="Regenerate SSL Certificates"
                            buttonTitle="Regenerate SSL Certificates" :actions="[
                                'The SSL certificate of this database will be regenerated.',
                                'You must restart the database after regenerating the certificate to start using the new certificate.',
                            ]"
                            submitAction="regenerateSslCertificate" :confirmWithText="false" :confirmWithPassword="false" />
                    @endif
                </div>
            </div>
            @if ($database->enable_ssl && $certificateValidUntil)
                <span class="text-sm">Valid until:
                    @if (now()->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expired</span>
                    @elseif(now()->addDays(30)->gt($certificateValidUntil))
                        <span class="text-red-500">{{ $certificateValidUntil->format('d.m.Y H:i:s') }} - Expiring
                            soon</span>
                    @else
                        <span>{{ $certificateValidUntil->format('d.m.Y H:i:s') }}</span>
                    @endif
                </span>
            @endif
            <div class="flex flex-col gap-2">
                <div class="w-64" wire:key='enable_ssl'>
                    @if (str($database->status)->contains('exited'))
                        <x-forms.checkbox id="database.enable_ssl" label="Enable SSL"
                            wire:model.live="database.enable_ssl" instantSave="instantSaveSSL" canGate="update"
                            :canResource="$database" />
                    @else
                        <x-forms.checkbox id="database.enable_ssl" label="Enable SSL"
                            wire:model.live="database.enable_ssl" instantSave="instantSaveSSL" disabled
                            helper="Database should be stopped to change this settings." canGate="update"
                            :canResource="$database" />
                    @endif
                </div>
            </div>
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
                <x-forms.checkbox instantSave id="database.is_public" label="Make it publicly available"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input placeholder="5432" disabled="{{ data_get($database, 'is_public') }}"
                id="database.public_port" label="Public Port" canGate="update" :canResource="$database" />
        </div>
        <x-forms.textarea placeholder="# maxmemory 256mb
# maxmemory-policy allkeys-lru
# timeout 300"
            helper="You only need to provide the Redis directives you want to override — Redis will use default values for everything else. <br/><br/>
⚠️ <strong>Important:</strong> Coolify automatically applies the requirepass directive using the password shown in the Password field above. If you override requirepass in your custom configuration, make sure it matches the password field to avoid authentication issues. <br/><br/>
🔗 <strong>Tip:</strong> <a target='_blank' class='underline dark:text-white' href='https://raw.githubusercontent.com/redis/redis/7.2/redis.conf'>View the full Redis default configuration</a> to see what options are available."
            label="Custom Redis Configuration" rows="10" id="database.redis_conf" canGate="update"
            :canResource="$database" />



        <h3 class="pt-4">Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="database.is_log_drain_enabled" label="Drain Logs"
                canGate="update" :canResource="$database" />
        </div>

    </form>
</div>
