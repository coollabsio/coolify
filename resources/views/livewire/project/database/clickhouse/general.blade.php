<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="Database details"
            description="Manage the identity and container image for this ClickHouse database.">
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
                        helper="Use a published clickhouse/clickhouse-server image from Docker Hub." />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Credentials"
            description="Keep these values aligned with the credentials configured inside ClickHouse.">
            @if (!$database->started_at)
                <x-callout type="warning" title="Verify the initial credentials">
                    You can only change these credentials here before the first start. Later changes must be made inside
                    the database.
                </x-callout>
            @endif
            <div class="{{ !$database->started_at ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                <x-forms.input label="{{ $database->started_at ? 'Initial username' : 'Username' }}"
                    id="clickhouseAdminUser" placeholder="If empty: clickhouse"
                    :readonly="(bool) $database->started_at" required canGate="update" :canResource="$database" />
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="{{ $database->started_at ? 'Initial password' : 'Password' }}"
                        id="clickhouseAdminPassword" type="password" required
                        :readonly="(bool) $database->started_at" canGate="update" :canResource="$database"
                        helper="{{ $database->started_at ? 'You can only change this in the database.' : null }}" />
                @endif
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
                <x-forms.input placeholder="3000:8123" id="portsMappings" label="Port mappings"
                    helper="Comma-separated host-to-container mappings, for example 3000:8123."
                    canGate="update" :canResource="$database" />
            </div>
            <div class="mt-4">
                <livewire:project.database.clickhouse.status-info :database="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Public access" class="relative"
            description="Expose this database through the managed TCP proxy.">
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
            <x-table.loading target="instantSave" text="Updating public access..." />
            <div class="grid gap-4 lg:grid-cols-2">
                <div wire:key="public-access-{{ $publicPort ?: 'unset' }}">
                    <x-forms.listbox id="isPublic" label="Access" live onChange="instantSave"
                        :disabled="! auth()->user()->can('update', $database)" canGate="update" :canResource="$database" :options="[
                            ['value' => false, 'label' => 'Private'],
                            ['value' => true, 'label' => blank($publicPort) ? 'Public through TCP proxy (set public port first)' : 'Public through TCP proxy', 'disabled' => blank($publicPort)],
                        ]" />
                </div>
                <x-forms.input type="number" placeholder="8123" disabled="{{ $isPublic }}" id="publicPort"
                    label="Public port" canGate="update" :canResource="$database" />
                <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                    label="Proxy timeout" helper="Timeout in seconds. The default is 3600."
                    canGate="update" :canResource="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Log delivery"
            description="Forward container logs to the drain configured on the server.">
            <x-forms.listbox canGate="update" :canResource="$database" id="isLogDrainEnabled" label="Log drain" live onChange="instantSaveAdvanced"
                :disabled="! auth()->user()->can('update', $database)" :options="[
                    ['value' => false, 'label' => 'Do not forward logs'],
                    ['value' => true, 'label' => 'Forward logs to the server drain'],
                ]" />
        </x-application.settings-section>
    </form>
</div>
