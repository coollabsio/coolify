<div>
    <x-slot:title>
        Instance Backup | Coolify
    </x-slot>

    <x-settings.navbar />

    <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
        @if ($server->isFunctional())
            @if (isset($database) && isset($backup))
                <form wire:submit="submit">
                    <x-unsaved-bar action="submit" />

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

                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <x-application.settings-section title="Instance backup">
                    <x-empty title="Backup is not configured"
                        description="Coolify needs an internal database resource to create automatic backups." size="sm">
                        <x-slot:icon>
                            <x-reicon name="database" class="size-6" />
                        </x-slot:icon>
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
</div>
