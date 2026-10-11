<div>
    <x-slot:title>
        Instance Backup | Coolify
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        @if ($server->isFunctional())
            @if (isset($database) && isset($backup))
                <form wire:submit="submit">
                    {{-- Exclude is_backup_before_update_enabled (instantSave) so the bar does not flash. --}}
                    <x-unsaved-bar action="submit" targets="description" />

                    <x-application.settings-section title="Instance database">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input label="Name" readonly id="name" />
                            <x-forms.input label="Description" id="description" />
                            <div class="lg:col-span-2">
                                <x-forms.input label="UUID" readonly id="uuid" />
                            </div>
                            <x-forms.input label="User" readonly id="postgres_user" />
                            <x-forms.input type="password" label="Password" readonly id="postgres_password" />
                        </div>
                    </x-application.settings-section>
                </form>

                <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                    :status="data_get($database, 'status')" />

                <x-application.settings-section title="Backup before update"
                    description="Back up the Coolify database before an update is installed. If the backup fails, the update is not installed.">
                    <div class="max-w-md">
                        <x-forms.listbox id="is_backup_before_update_enabled" label="Database backup"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]"
                            helper="Runs this backup once before every manual or automatic update, in addition to the schedule." />
                    </div>
                </x-application.settings-section>

                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <x-application.settings-section title="Instance backup">
                    <x-empty title="Backup is not configured"
                        description="Coolify needs an internal database resource to create automatic backups."
                        icon-name="database" size="sm">
                        <x-slot:actions>
                            <x-forms.button wire:click="addCoolifyDatabase" isHighlighted>
                                Configure backup
                            </x-forms.button>
                        </x-slot:actions>
                    </x-empty>
                </x-application.settings-section>
            @endif
        @else
            <x-application.settings-section title="Instance backup">
                <x-callout type="danger" title="Localhost is not ready">
                    Validate the localhost connection before configuring instance backups.
                    <a href="{{ route('server.show', [$server->uuid]) }}" class="font-medium underline"
                        {{ wireNavigate() }}>Open server settings</a>
                </x-callout>
            </x-application.settings-section>
        @endif
    </div>
    </x-settings.layout>
</div>
