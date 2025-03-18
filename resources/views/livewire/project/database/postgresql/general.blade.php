<div>
    <dialog id="newInitScript" class="modal">
        <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit='save_new_init_script'>
            <h3 class="text-lg font-bold">Add Init Script</h3>
            <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="Filename" required />
            <x-forms.textarea placeholder="CREATE DATABASE test;" id="new_content" label="Content" required />
            <x-forms.button onclick="newInitScript.close()" type="submit">
                Save
            </x-forms.button>
        </form>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
            <x-forms.input label="Name" id="database.name" />
            <x-forms.input label="Description" id="database.description" />
            <x-forms.input label="Image" id="database.image" required
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/postgres'>https://hub.docker.com/_/postgres</a>" />
        </div>
        <div class="pt-2 dark:text-warning">If you change the values in the database, please sync it here, otherwise
            automations (like backups) won't work.
        </div>
        @if ($database->started_at)
            <div class="flex xl:flex-row flex-col gap-2">
                <x-forms.input label="Username" id="database.postgres_user" placeholder="If empty: postgres"
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." />
                <x-forms.input label="Password" id="database.postgres_password" type="password" required
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." />
                <x-forms.input label="Initial Database" id="database.postgres_db"
                    placeholder="If empty, it will be the same as Username." readonly
                    helper="You can only change this in the database." />
            </div>
        @else
            <div class="flex xl:flex-row flex-col gap-2 pb-2">
                <x-forms.input label="Username" id="database.postgres_user" placeholder="If empty: postgres" />
                <x-forms.input label="Password" id="database.postgres_password" type="password" required />
                <x-forms.input label="Initial Database" id="database.postgres_db"
                    placeholder="If empty, it will be the same as Username." />
            </div>
        @endif
        <div class="flex gap-2">
            <x-forms.input label="Initial Database Arguments" id="database.postgres_initdb_args"
                placeholder="If empty, use default. See in docker docs." />
            <x-forms.input label="Host Auth Method" id="database.postgres_host_auth_method"
                placeholder="If empty, use default. See in docker docs." />
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

            <x-forms.input label="Postgres URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="Postgres URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="db_url_public" />
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
        </div>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2">
                <x-forms.checkbox id="database.enable_ssl" label="Enable SSL" wire:model.live="database.enable_ssl"
                    instantSave="instantSaveSSL" />
                @if ($database->enable_ssl)
                    <div class="mx-2">
                        <x-forms.select id="database.ssl_mode" label="SSL Mode" wire:model.live="database.ssl_mode"
                            instantSave="instantSaveSSL"
                            helper="Choose the SSL verification mode for PostgreSQL connections">
                            <option value="allow" title="Allow insecure connections">allow (insecure)</option>
                            <option value="prefer" title="Prefer secure connections">prefer (secure)</option>
                            <option value="require" title="Require secure connections">require (secure)</option>
                            <option value="verify-ca" title="Verify CA certificate">verify-ca (secure)</option>
                            <option value="verify-full" title="Verify full certificate">verify-full (secure)</option>
                        </x-forms.select>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between py-2">
                <div class="flex items-center gap-2">
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
            <div class="flex flex-col gap-2">
                <x-forms.checkbox instantSave id="database.is_public" label="Make it publicly available" />
                <x-forms.input placeholder="5432" disabled="{{ data_get($database, 'is_public') }}"
                    id="database.public_port" label="Public Port" />
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <x-forms.textarea label="Custom PostgreSQL Configuration" rows="10" id="database.postgres_conf" />
        </div>
    </form>

    <div class="flex flex-col gap-4 pt-4">
        <h3>Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="database.is_log_drain_enabled" label="Drain Logs" />
        </div>

        <div class="pb-16">
            <div class="flex items-center gap-2 pb-2">
                <h3>Initialization scripts</h3>
                <x-modal-input buttonTitle="+ Add" title="New Init Script">
                    <form class="flex flex-col w-full gap-2 rounded" wire:submit='save_new_init_script'>
                        <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="Filename"
                            required />
                        <x-forms.textarea rows="20" placeholder="CREATE DATABASE test;" id="new_content"
                            label="Content" required />
                        <x-forms.button type="submit">
                            Save
                        </x-forms.button>
                    </form>
                </x-modal-input>
            </div>
            <div class="flex flex-col gap-2">
                @forelse(data_get($database,'init_scripts', []) as $script)
                    <livewire:project.database.init-script :script="$script" :wire:key="$script['index']" />
                @empty
                    <div>No initialization scripts found.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
