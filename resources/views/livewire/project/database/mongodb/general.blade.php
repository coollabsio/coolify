<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="Database details"
            description="Manage the identity and container image for this MongoDB database.">
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
                        helper="Use a published MongoDB image from Docker Hub." />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Credentials"
            description="Keep these values aligned with the credentials configured inside MongoDB.">
            @if ($database->started_at)
                <x-callout type="warning" title="Keep credentials synchronized">
                    Changing values here does not update MongoDB. Update MongoDB first, then synchronize the values here
                    so backups and other automations continue working.
                </x-callout>
            @endif
            <div class="{{ $database->started_at ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                <x-forms.input label="{{ $database->started_at ? 'Initial username' : 'Username' }}"
                    id="mongoInitdbRootUsername" placeholder="If empty: root"
                    :required="!$database->started_at" canGate="update" :canResource="$database" />
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="{{ $database->started_at ? 'Initial password' : 'Password' }}"
                        id="mongoInitdbRootPassword" type="password" required canGate="update"
                        :canResource="$database" />
                @endif
                <x-forms.input label="{{ $database->started_at ? 'Initial database' : 'Database' }}"
                    id="mongoInitdbDatabase" placeholder="If empty, it will match the username."
                    :readonly="(bool) $database->started_at" :required="!$database->started_at"
                    canGate="update" :canResource="$database"
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
                <x-forms.input placeholder="3000:27017" id="portsMappings" label="Port mappings"
                    helper="Comma-separated host-to-container mappings, for example 3000:27017."
                    canGate="update" :canResource="$database" />
            </div>
            <div class="mt-4">
                <livewire:project.database.mongodb.status-info :database="$database" />
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
                <x-forms.input type="number" placeholder="27017" disabled="{{ $isPublic }}" id="publicPort"
                    label="Public port" canGate="update" :canResource="$database" />
                <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                    label="Proxy timeout" helper="Timeout in seconds. The default is 3600."
                    canGate="update" :canResource="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Configuration"
            description="Override the MongoDB configuration used by this container.">
            <x-forms.textarea label="Custom MongoDB configuration" rows="10" id="mongoConf"
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
