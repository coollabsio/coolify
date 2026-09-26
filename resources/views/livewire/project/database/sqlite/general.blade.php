<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="Database details"
            description="Manage the identity and container image for this SQLite database.">
            <x-slot:actions>
                <x-status-badge status="Experimental" type="warning" />
                <x-modal-input title="Resource details" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$database" />
                </x-modal-input>
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
                <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
                <div class="lg:col-span-2">
                    <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                        helper="Use a published peakimages/sqlite image from Docker Hub." />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="Database files"
            description="Applications use these databases by mounting the data volume.">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="File names" id="sqliteDatabases" placeholder="database.sqlite" required
                    canGate="update" :canResource="$database"
                    helper="Comma-separated file names. Missing files are created in the data volume on the next start. Removing a name does not delete its file. Backups restore into the first file." />
            </div>
        </x-application.settings-section>

        <livewire:project.database.sqlite.connect-application :database="$database" />

        <x-application.settings-section title="Runtime" description="Configure Docker runtime options.">
            <x-forms.input
                helper="Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation."
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                id="customDockerRunOptions" label="Custom Docker options" canGate="update"
                :canResource="$database" />
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
