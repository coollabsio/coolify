<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="Database details"
            description="Manage the identity and container image for this MariaDB database.">
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
                        helper="Use a published MariaDB image from Docker Hub." />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Credentials"
            description="Keep these values aligned with the credentials configured inside MariaDB.">
            @if ($database->started_at)
                <x-callout type="warning" title="Keep credentials synchronized">
                    Changing values here does not update MariaDB. Update MariaDB first, then synchronize the values here
                    so backups and other automations continue working.
                </x-callout>
            @endif
            <div class="{{ $database->started_at ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Root password" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="Root password" id="mariadbRootPassword" type="password"
                        :required="(bool) $database->started_at" canGate="update" :canResource="$database" />
                @endif
                <x-forms.input label="Normal user" id="mariadbUser" required canGate="update"
                    :canResource="$database" />
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Normal user password" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="Normal user password" id="mariadbPassword" type="password" required
                        canGate="update" :canResource="$database" />
                @endif
                <x-forms.input label="Initial database" id="mariadbDatabase"
                    placeholder="If empty, it will match the normal user."
                    :readonly="(bool) $database->started_at" canGate="update" :canResource="$database"
                    helper="{{ $database->started_at ? 'You can only change this in the database.' : null }}" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Runtime and network"
            description="Configure Docker runtime options and host port mappings.">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-forms.input
                        helper="Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation."
                        placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                        id="customDockerRunOptions" label="Custom Docker options" canGate="update"
                        :canResource="$database" />
                </div>
                <x-forms.input placeholder="3000:3306" id="portsMappings" label="Port mappings"
                    helper="Comma-separated host-to-container mappings, for example 3000:3306."
                    canGate="update" :canResource="$database" />
            </div>
            <div class="mt-4">
                <livewire:project.database.mariadb.status-info :database="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Public access"
            description="Expose this database through the managed TCP proxy.">
            <x-slot:actions>
                @if ($isPublic)
                    <x-slide-over fullScreen>
                        <x-slot:title>Proxy logs</x-slot:title>
                        <x-slot:content>
                            <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                        </x-slot:content>
                        <x-forms.button @click="slideOverOpen=true">View logs</x-forms.button>
                    </x-slide-over>
                @endif
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.listbox id="isPublic" label="Access" live onChange="instantSave"
                    :disabled="! auth()->user()->can('update', $database)" :options="[
                        ['value' => false, 'label' => 'Private'],
                        ['value' => true, 'label' => 'Public through TCP proxy'],
                    ]" />
                <x-forms.input type="number" placeholder="3306" disabled="{{ $isPublic }}" id="publicPort"
                    label="Public port" canGate="update" :canResource="$database" />
                <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                    label="Proxy timeout" helper="Timeout in seconds. The default is 3600."
                    canGate="update" :canResource="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Configuration"
            description="Override the MariaDB configuration used by this container.">
            <x-forms.textarea label="Custom MariaDB configuration" rows="10" id="mariadbConf"
                canGate="update" :canResource="$database" />
        </x-application.settings-section>

        <x-application.settings-section title="Log delivery"
            description="Forward container logs to the drain configured on the server.">
            <x-forms.listbox id="isLogDrainEnabled" label="Log drain" live onChange="instantSaveAdvanced"
                :disabled="! auth()->user()->can('update', $database)" :options="[
                    ['value' => false, 'label' => 'Do not forward logs'],
                    ['value' => true, 'label' => 'Forward logs to the server drain'],
                ]" />
        </x-application.settings-section>
    </form>
</div>
