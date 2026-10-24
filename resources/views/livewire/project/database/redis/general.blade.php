<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section id="database-details-section" title="Database details"
            description="Manage the identity, container image, and credentials of this Redis database.">
            <x-slot:actions>
                <x-modal-input title="Resource details" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$database" />
                </x-modal-input>
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
                <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
                <div class="lg:col-span-2">
                    <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                        helper="Use a published Redis image from Docker Hub." />
                </div>
            </div>
            <x-application.settings-subsection id="credentials-section" title="Credentials" helper="Keep these values aligned with the credentials configured inside Redis.">
                <x-callout type="warning" title="{{ $database->started_at ? 'Keep credentials synchronized' : 'Verify the initial credentials' }}">
                    @if ($database->started_at)
                        Changing values here does not update Redis. Update Redis first, then synchronize the values here so
                        automations continue working.
                    @else
                        These values can only be changed here before the first start.
                    @endif
                </x-callout>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    @if (version_compare($redisVersion, '6.0', '>='))
                        <x-forms.input label="Username" id="redisUsername" :required="!$database->started_at"
                            helper="{{ $database->started_at ? 'You can only change this in the database.' : 'Shared REDIS_USERNAME values make this field read-only.' }}"
                            :disabled="!$database->started_at && $this->isSharedVariable('REDIS_USERNAME')"
                            canGate="update" :canResource="$database" />
                    @endif
                    @if ($isPasswordHiddenForMember)
                        <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                    @elseif ($isPasswordLocked)
                        <x-forms.input label="Password" disabled value="Locked (shown once)"
                            helper="REDIS_PASSWORD is locked. Its value is never shown again." />
                    @else
                        <x-forms.input label="Password" id="redisPassword" type="password"
                            :required="!$database->started_at"
                            helper="{{ $database->started_at ? 'You can only change this in the database.' : 'Shared REDIS_PASSWORD values make this field read-only.' }}"
                            :disabled="!$database->started_at && $this->isSharedVariable('REDIS_PASSWORD')"
                            canGate="update" :canResource="$database" />
                    @endif
                </div>
            </x-application.settings-subsection>
        </x-application.settings-section>

        <x-application.settings-section id="access-section" title="Access"
            description="How applications and clients connect to this database, and whether it is reachable from the internet.">
            <x-slot:actions>
                @if ($isPublic)
                    <x-process-dialog closeWithX size="xl">
                        <x-slot:title>Proxy logs</x-slot:title>
                        <x-slot:content>
                            <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                        </x-slot:content>
                        <x-forms.button @click="processDialogOpen = true">View logs</x-forms.button>
                    </x-process-dialog>
                @endif
            </x-slot:actions>
            <x-application.settings-subsection id="connection-section" title="Connection" helper="Use these URLs to connect to the database." first>
                <livewire:project.database.redis.status-info :database="$database" />
            </x-application.settings-subsection>
            <x-application.settings-subsection id="public-access-section" title="Public access" helper="Expose this database through the managed TCP proxy." class="relative">
                <x-table.loading target="instantSave" text="Updating public access..." />
                <div class="grid gap-4 lg:grid-cols-2">
                    <div wire:key="public-access-{{ $publicPort ?: 'unset' }}">
                        <x-forms.listbox id="isPublic" label="Access" live onChange="instantSave"
                            :disabled="! auth()->user()->can('update', $database)" canGate="update" :canResource="$database" :options="[
                                ['value' => false, 'label' => 'Private'],
                                ['value' => true, 'label' => blank($publicPort) ? 'Public through TCP proxy (set public port first)' : 'Public through TCP proxy', 'disabled' => blank($publicPort)],
                            ]" />
                    </div>
                    <x-forms.input type="number" placeholder="6379" disabled="{{ $isPublic }}" id="publicPort"
                        label="Public port" canGate="update" :canResource="$database" />
                    <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                        label="Proxy timeout" helper="Timeout in seconds. The default is 3600."
                        canGate="update" :canResource="$database" />
                    <x-forms.input placeholder="3000:6379" id="portsMappings" label="Port mappings"
                        helper="Comma-separated host-to-container mappings, for example 3000:6379."
                        canGate="update" :canResource="$database" />
                </div>
            </x-application.settings-subsection>
        </x-application.settings-section>

        <x-application.settings-section id="container-section" title="Container"
            description="Docker options, configuration, and log forwarding for the database container.">
            <x-forms.input
                helper="Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation."
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                id="customDockerRunOptions" label="Custom Docker options" canGate="update"
                :canResource="$database" />
            <x-application.settings-subsection id="configuration-section" title="Configuration" helper="Override only the Redis directives you need. All other defaults remain active.">
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Custom Redis configuration" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.textarea placeholder="# maxmemory 256mb
    # maxmemory-policy allkeys-lru
    # timeout 300"
                        helper="Coolify automatically applies requirepass using the password above. If you override requirepass here, keep both values identical."
                        label="Custom Redis configuration" rows="10" id="redisConf" canGate="update"
                        :canResource="$database" />
                @endif
            </x-application.settings-subsection>
            <x-application.settings-subsection id="log-delivery-section" title="Log delivery" helper="Forward container logs to the drain configured on the server.">
                <x-forms.listbox canGate="update" :canResource="$database" id="isLogDrainEnabled" label="Log drain" live onChange="instantSaveAdvanced"
                    :disabled="! auth()->user()->can('update', $database)" :options="[
                        ['value' => false, 'label' => 'Do not forward logs'],
                        ['value' => true, 'label' => 'Forward logs to the server drain'],
                    ]" />
            </x-application.settings-subsection>
        </x-application.settings-section>
    </form>
</div>
