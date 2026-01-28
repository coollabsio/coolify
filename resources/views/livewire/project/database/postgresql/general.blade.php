<div>
    <dialog id="newInitScript" class="modal">
        <form method="dialog" class="flex flex-col gap-2 rounded-sm modal-box" wire:submit='save_new_init_script'>
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
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
            <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/postgres'>https://hub.docker.com/_/postgres</a>" />
        </div>
        <div class="pt-2 dark:text-warning">If you change the values in the database, please sync it here, otherwise
            automations (like backups) won't work.
        </div>
        @if ($database->started_at)
            <div class="flex xl:flex-row flex-col gap-2">
                <x-forms.input label="Username" id="postgresUser" placeholder="If empty: postgres" canGate="update"
                    :canResource="$database"
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." />
                <x-forms.input label="Password" id="postgresPassword" type="password" required canGate="update"
                    :canResource="$database"
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." />
                <x-forms.input label="Initial Database" id="postgresDb"
                    placeholder="If empty, it will be the same as Username." readonly
                    helper="You can only change this in the database." />
            </div>
        @else
            <div class="flex xl:flex-row flex-col gap-2 pb-2">
                <x-forms.input label="Username" id="postgresUser" placeholder="If empty: postgres" canGate="update"
                    :canResource="$database" />
                <x-forms.input label="Password" id="postgresPassword" type="password" required canGate="update"
                    :canResource="$database" />
                <x-forms.input label="Initial Database" id="postgresDb"
                    placeholder="If empty, it will be the same as Username." canGate="update" :canResource="$database" />
            </div>
        @endif
        <div class="flex gap-2">
            <x-forms.input label="Initial Database Arguments" canGate="update" :canResource="$database" id="postgresInitdbArgs"
                placeholder="If empty, use default. See in docker docs." />
            <x-forms.input label="Host Auth Method" canGate="update" :canResource="$database" id="postgresHostAuthMethod"
                placeholder="If empty, use default. See in docker docs." />
        </div>
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' {{ wireNavigate() }} href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
            id="customDockerRunOptions" label="Custom Docker Options" canGate="update" :canResource="$database" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433"
                    canGate="update" :canResource="$database" />
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
            <div class="flex items-center gap-2 py-2">
                <h3>SSL Configuration</h3>
                @if ($enableSsl && $certificateValidUntil)
                    <x-modal-confirmation title="Regenerate SSL Certificates" buttonTitle="Regenerate SSL Certificates"
                        :actions="[
                            'The SSL certificate of this database will be regenerated.',
                            'You must restart the database after regenerating the certificate to start using the new certificate.',
                        ]" submitAction="regenerateSslCertificate" :confirmWithText="false"
                        :confirmWithPassword="false" />
                @endif
            </div>
            @if ($enableSsl && $certificateValidUntil)
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
                <div class="w-64" wire:key='enable_ssl'>
                    @if ($database->isExited())
                        <x-forms.checkbox id="enableSsl" label="Enable SSL" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" canGate="update" :canResource="$database" />
                    @else
                        <x-forms.checkbox id="enableSsl" label="Enable SSL" wire:model.live="enableSsl"
                            instantSave="instantSaveSSL" disabled
                            helper="Database should be stopped to change this settings." />
                    @endif
                </div>
                @if ($enableSsl)
                    <div class="mx-2">
                        @if ($database->isExited())
                            <x-forms.select id="sslMode" label="SSL Mode" wire:model.live="sslMode"
                                instantSave="instantSaveSSL"
                                helper="Choose the SSL verification mode for PostgreSQL connections" canGate="update"
                                :canResource="$database">
                                <option value="allow" title="Allow insecure connections">allow (insecure)</option>
                                <option value="prefer" title="Prefer secure connections">prefer (secure)</option>
                                <option value="require" title="Require secure connections">require (secure)</option>
                                <option value="verify-ca" title="Verify CA certificate">verify-ca (secure)</option>
                                <option value="verify-full" title="Verify full certificate">verify-full (secure)
                                </option>
                            </x-forms.select>
                        @else
                            <x-forms.select id="sslMode" label="SSL Mode" instantSave="instantSaveSSL" disabled
                                helper="Database should be stopped to change this settings.">
                                <option value="allow" title="Allow insecure connections">allow (insecure)</option>
                                <option value="prefer" title="Prefer secure connections">prefer (secure)</option>
                                <option value="require" title="Require secure connections">require (secure)</option>
                                <option value="verify-ca" title="Verify CA certificate">verify-ca (secure)</option>
                                <option value="verify-full" title="Verify full certificate">verify-full (secure)
                                </option>
                            </x-forms.select>
                        @endif
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2 py-2">
                        <h3>Proxy</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                        @if (data_get($database, 'is_public'))
                            <x-slide-over fullScreen>
                                <x-slot:title>Proxy Logs</x-slot:title>
                                <x-slot:content>
                                    <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                        container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                                </x-slot:content>
                                <x-forms.button disabled="{{ !data_get($database, 'is_public') }}"
                                    @click="slideOverOpen=true">Logs</x-forms.button>
                            </x-slide-over>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2 w-64">
                        <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available"
                            canGate="update" :canResource="$database" />
                    </div>
                    <x-forms.input placeholder="5432" disabled="{{ $isPublic }}" id="publicPort"
                        label="Public Port" canGate="update" :canResource="$database" />
                </div>

                <div class="flex flex-col gap-2">
                    <x-forms.textarea label="Custom PostgreSQL Configuration" rows="10" id="postgresConf"
                        canGate="update" :canResource="$database" />
                </div>
            </div>
        </div>
    </form>

    {{-- pgBackRest Configuration Section --}}
    <div class="flex flex-col gap-4 pt-4">
        <div class="flex items-center gap-2">
            <h3>pgBackRest — Incremental Backups</h3>
            @if ($pgbackrestEnabled && $pgbackrestInfo && data_get($pgbackrestInfo, 'installed') && data_get($pgbackrestInfo, 'configured'))
                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-500/20 text-green-400">Configured</span>
                @if (data_get($pgbackrestInfo, 'wal_archiving'))
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-blue-500/20 text-blue-400">WAL Archiving Active</span>
                @endif
            @elseif ($pgbackrestEnabled)
                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-500/20 text-yellow-400">Needs Setup</span>
            @else
                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-neutral-500/20 text-neutral-400">Disabled</span>
            @endif
        </div>

        <div class="text-sm dark:text-gray-400">
            pgBackRest provides full, differential, and incremental backups for PostgreSQL.
            Incremental backups can significantly reduce storage costs for large databases (100GB+).
        </div>

        <div class="w-64">
            <x-forms.checkbox id="pgbackrestEnabled" label="Enable pgBackRest" wire:model.live="pgbackrestEnabled"
                canGate="update" :canResource="$database" />
        </div>

        @if ($pgbackrestEnabled)
            <div class="flex flex-col gap-4 pl-2">
                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                    <x-forms.input label="Stanza Name" id="pgbackrestStanza"
                        helper="A unique name for this backup configuration. Auto-generated if empty."
                        canGate="update" :canResource="$database" />
                    <x-forms.select id="pgbackrestRepoType" label="Repository Type" wire:model.live="pgbackrestRepoType"
                        canGate="update" :canResource="$database">
                        <option value="posix">Local (POSIX filesystem)</option>
                        <option value="s3">S3-Compatible Storage</option>
                    </x-forms.select>
                </div>

                @if ($pgbackrestRepoType === 's3')
                    <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                        @if ($s3Storages && $s3Storages->count() > 0)
                            <x-forms.select id="pgbackrestS3StorageId" label="S3 Storage"
                                helper="Select an S3 storage configured in your team settings."
                                canGate="update" :canResource="$database">
                                <option value="" disabled>Select S3 Storage</option>
                                @foreach ($s3Storages as $s3)
                                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                                @endforeach
                            </x-forms.select>
                        @else
                            <div class="text-sm text-red-500">No S3 Storages configured. Add one in your team settings first.</div>
                        @endif
                    </div>
                @endif

                <div class="flex flex-wrap gap-2 sm:flex-nowrap">
                    <x-forms.input label="Full Backup Retention" id="pgbackrestRetentionFull" type="number" min="1"
                        helper="Number of full backups to retain."
                        canGate="update" :canResource="$database" />
                    <x-forms.input label="Differential Backup Retention" id="pgbackrestRetentionDiff" type="number" min="1"
                        helper="Number of differential backups to retain per full backup."
                        canGate="update" :canResource="$database" />
                </div>

                <div class="flex gap-2">
                    @can('update', $database)
                        <x-forms.button wire:click="savePgBackRestSettings">
                            Save pgBackRest Settings
                        </x-forms.button>
                        @if (str($database->status)->startsWith('running'))
                            <x-forms.button wire:click="setupPgBackRest" isHighlighted
                                wire:loading.attr="disabled" wire:target="setupPgBackRest">
                                <span wire:loading.remove wire:target="setupPgBackRest">
                                    @if ($pgbackrestInfo && data_get($pgbackrestInfo, 'configured'))
                                        Reconfigure pgBackRest
                                    @else
                                        Setup pgBackRest
                                    @endif
                                </span>
                                <span wire:loading wire:target="setupPgBackRest">Setting up...</span>
                            </x-forms.button>
                        @else
                            <x-forms.button disabled helper="Database must be running to setup pgBackRest.">
                                Setup pgBackRest
                            </x-forms.button>
                        @endif
                        <x-forms.button wire:click="loadPgBackRestInfo"
                            wire:loading.attr="disabled" wire:target="loadPgBackRestInfo">
                            <span wire:loading.remove wire:target="loadPgBackRestInfo">Refresh Status</span>
                            <span wire:loading wire:target="loadPgBackRestInfo">Loading...</span>
                        </x-forms.button>
                    @endcan
                </div>

                {{-- pgBackRest Status & Backup History --}}
                @if ($pgbackrestInfo)
                    <div class="flex flex-col gap-2 mt-2">
                        <h4 class="font-medium">Status</h4>
                        <div class="flex gap-4 text-sm">
                            <span>Installed: {{ data_get($pgbackrestInfo, 'installed') ? '✅' : '❌' }}</span>
                            <span>Configured: {{ data_get($pgbackrestInfo, 'configured') ? '✅' : '❌' }}</span>
                            <span>WAL Archiving: {{ data_get($pgbackrestInfo, 'wal_archiving') ? '✅ Active' : '❌ Inactive' }}</span>
                            <span>Total Backups: {{ data_get($pgbackrestInfo, 'backup_count', 0) }}</span>
                        </div>

                        @if (!empty(data_get($pgbackrestInfo, 'backups', [])))
                            <h4 class="font-medium mt-2">Recent Backups</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-neutral-700">
                                            <th class="text-left py-2 px-2">Type</th>
                                            <th class="text-left py-2 px-2">Started</th>
                                            <th class="text-left py-2 px-2">Completed</th>
                                            <th class="text-right py-2 px-2">Size</th>
                                            <th class="text-right py-2 px-2">Repo Size</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach (array_reverse(data_get($pgbackrestInfo, 'backups', [])) as $backup)
                                            <tr class="border-b border-neutral-800">
                                                <td class="py-1 px-2">
                                                    <span class="px-1.5 py-0.5 text-xs rounded
                                                        @if(data_get($backup, 'type') === 'full') bg-green-500/20 text-green-400
                                                        @elseif(data_get($backup, 'type') === 'diff') bg-blue-500/20 text-blue-400
                                                        @else bg-purple-500/20 text-purple-400
                                                        @endif">
                                                        {{ data_get($backup, 'type', 'unknown') }}
                                                    </span>
                                                </td>
                                                <td class="py-1 px-2">{{ data_get($backup, 'timestamp_start', '-') }}</td>
                                                <td class="py-1 px-2">{{ data_get($backup, 'timestamp_stop', '-') }}</td>
                                                <td class="py-1 px-2 text-right">{{ number_format(data_get($backup, 'size', 0) / 1024 / 1024, 1) }} MB</td>
                                                <td class="py-1 px-2 text-right">{{ number_format(data_get($backup, 'repository_size', 0) / 1024 / 1024, 1) }} MB</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-4 pt-4">
        <h3>Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" canGate="update"
                :canResource="$database" />
        </div>

        <div class="pb-16">
            <div class="flex items-center gap-2 pb-2">

                <h3>Initialization scripts</h3>
                @can('update', $database)
                    <x-modal-input buttonTitle="+ Add" title="New Init Script">
                        <form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='save_new_init_script'>
                            <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="Filename"
                                required />
                            <x-forms.textarea rows="20" placeholder="CREATE DATABASE test;" id="new_content"
                                label="Content" required />
                            <x-forms.button type="submit">
                                Save
                            </x-forms.button>
                        </form>
                    </x-modal-input>
                @endcan
            </div>
            <div class="flex flex-col gap-2">
                @forelse($initScripts ?? [] as $script)
                    <livewire:project.database.init-script :script="$script" :wire:key="$script['index']" />
                @empty
                    <div>No initialization scripts found.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
