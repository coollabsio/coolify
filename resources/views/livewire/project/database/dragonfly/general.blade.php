<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section id="database-details-section" title="Database details"
            description="Manage the identity, container image, and credentials of this Dragonfly database.">
            <x-slot:actions>
                <x-modal-input title="Resource details" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$database" />
                </x-modal-input>
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
                <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
                <div class="lg:col-span-2">
                    <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database" />
                </div>
            </div>
            <section id="credentials-section" class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Credentials <x-helper helper="Keep these values aligned with the credentials configured inside Dragonfly." /></h3>
                @if ($database->started_at)
                    @if ($isPasswordHiddenForMember)
                        <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                    @else
                        <x-forms.input label="Password" id="dragonflyPassword" type="password" required readonly
                            helper="You can only change this in the database." canGate="update" :canResource="$database" />
                    @endif
                @else
                    <x-callout type="warning" title="Verify the initial credentials">
                        You can only change this password here before the first start. Later changes must be made inside the
                        database.
                    </x-callout>
                    <div class="mt-4">
                        @if ($isPasswordHiddenForMember)
                            <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                        @else
                            <x-forms.input label="Password" id="dragonflyPassword" type="password" required
                                canGate="update" :canResource="$database" />
                        @endif
                    </div>
                @endif
            </section>
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
            <section id="connection-section">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Connection <x-helper helper="Use these URLs to connect to the database." /></h3>
                <livewire:project.database.dragonfly.status-info :database="$database" />
            </section>
            <section id="public-access-section" class="relative mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Public access <x-helper helper="Expose this database through the managed TCP proxy." /></h3>
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
            </section>
        </x-application.settings-section>

        <x-application.settings-section id="container-section" title="Container"
            description="Docker options and log forwarding for the database container.">
            <x-forms.input
                helper="Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation."
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                id="customDockerRunOptions" label="Custom Docker options" canGate="update"
                :canResource="$database" />
            <section id="log-delivery-section" class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Log delivery <x-helper helper="Forward container logs to the drain configured on the server." /></h3>
                <x-forms.listbox canGate="update" :canResource="$database" id="isLogDrainEnabled" label="Log drain" live onChange="instantSaveAdvanced"
                    :disabled="! auth()->user()->can('update', $database)" :options="[
                        ['value' => false, 'label' => 'Do not forward logs'],
                        ['value' => true, 'label' => 'Forward logs to the server drain'],
                    ]" />
            </section>
        </x-application.settings-section>
    </form>
</div>
