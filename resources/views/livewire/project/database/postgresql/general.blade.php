<div class="application-settings-form flex flex-col gap-6">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section id="database-details-section" title="Database details"
            description="Manage the identity, container image, and credentials of this PostgreSQL database.">
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
                        helper="Use a published PostgreSQL image from Docker Hub." />
                </div>
            </div>
            <section id="credentials-section" class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Credentials <x-helper helper="Keep these values aligned with the credentials configured inside PostgreSQL." /></h3>
                @if ($database->started_at)
                    <x-callout type="warning" title="Keep credentials synchronized">
                        Changing values here does not update PostgreSQL. Update PostgreSQL first, then synchronize the
                        values here so backups and other automations continue working.
                    </x-callout>
                @endif
                <div class="{{ $database->started_at ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                    <x-forms.input label="Username" id="postgresUser" placeholder="If empty: postgres"
                        canGate="update" :canResource="$database" />
                    @if ($isPasswordHiddenForMember)
                        <x-forms.input label="Password" disabled value="Hidden (only admins can view)" />
                    @else
                        <x-forms.input label="Password" id="postgresPassword" type="password" required
                            canGate="update" :canResource="$database" />
                    @endif
                    <x-forms.input label="Initial database" id="postgresDb"
                        placeholder="If empty, it will match the username."
                        :readonly="(bool) $database->started_at" canGate="update" :canResource="$database"
                        helper="{{ $database->started_at ? 'You can only change this in the database.' : null }}" />
                </div>
            </section>
            <section id="initialization-section" class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Initialization <x-helper helper="Configure the options used when PostgreSQL creates its initial data directory." /></h3>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input label="Initial database arguments" id="postgresInitdbArgs"
                        placeholder="Leave empty to use the image default." canGate="update" :canResource="$database" />
                    <x-forms.input label="Host authentication method" id="postgresHostAuthMethod"
                        placeholder="Leave empty to use the image default." canGate="update" :canResource="$database" />
                </div>
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
                <livewire:project.database.postgresql.status-info :database="$database" />
            </section>
            <section id="public-access-section" class="relative mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Public access <x-helper helper="Expose this database through the managed TCP proxy." /></h3>
                <x-table.loading target="instantSave" text="Updating public access..." />
                <div class="grid gap-4 lg:grid-cols-2">
                    <div wire:key="public-access-{{ $publicPort ?: 'unset' }}">
                        <x-forms.listbox id="isPublic" label="Access" live onChange="instantSave" :onChangeArgs="[]"
                            :disabled="! auth()->user()->can('update', $database)" canGate="update" :canResource="$database" :options="[
                                ['value' => false, 'label' => 'Private'],
                                ['value' => true, 'label' => blank($publicPort) ? 'Public through TCP proxy (set public port first)' : 'Public through TCP proxy', 'disabled' => blank($publicPort)],
                            ]" />
                    </div>
                    <x-forms.input type="number" placeholder="5432" disabled="{{ $isPublic }}" id="publicPort"
                        label="Public port" canGate="update" :canResource="$database" />
                    <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                        label="Proxy timeout" helper="Timeout in seconds. The default is 3600."
                        canGate="update" :canResource="$database" />
                    <x-forms.input placeholder="3000:5432" id="portsMappings" label="Port mappings"
                        helper="Comma-separated host-to-container mappings, for example 3000:5432."
                        canGate="update" :canResource="$database" />
                </div>
            </section>
        </x-application.settings-section>

        <x-application.settings-section id="container-section" title="Container"
            description="Docker options, configuration, and log forwarding for the database container.">
            <x-forms.input
                helper="Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation."
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                id="customDockerRunOptions" label="Custom Docker options" canGate="update"
                :canResource="$database" />
            <section id="configuration-section" class="mt-5 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                <h3 class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-black dark:text-fg">Configuration <x-helper helper="Override the PostgreSQL configuration used by this container." /></h3>
                <x-forms.textarea label="Custom PostgreSQL configuration" rows="10" id="postgresConf"
                    canGate="update" :canResource="$database" />
            </section>
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

    <x-application.settings-section id="initialization-scripts-section" title="Initialization scripts"
        description="Run SQL files in order when PostgreSQL initializes for the first time." flush>
        <x-slot:actions>
            @can('update', $database)
                <x-modal-input buttonTitle="+ Add" title="New initialization script">
                    <form class="flex w-full flex-col gap-4" wire:submit="save_new_init_script">
                        <x-forms.input placeholder="create_test_db.sql" id="new_filename" label="Filename" required />
                        <x-forms.textarea rows="16" placeholder="CREATE DATABASE test;" id="new_content"
                            label="Content" required />
                        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <x-forms.button type="submit">Add script</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </x-slot:actions>
        <div class="divide-y divide-neutral-200 dark:divide-border-subtle">
            @forelse($initScripts ?? [] as $script)
                <livewire:project.database.init-script :database="$database" :script="$script"
                    :wire:key="'init-script-'.md5($script['filename'])" />
            @empty
                <x-empty title="No initialization scripts"
                    description="Add a SQL file to run during the first PostgreSQL initialization." />
            @endforelse
        </div>
    </x-application.settings-section>
</div>
