<form wire:submit="submit">
    <x-unsaved-bar action="submit" />

    <section class="application-settings-section">
        <div class="application-settings-section-header">
            <div>
                <h2>Backup schedule</h2>
                <p>Choose what to back up, when it runs, and how long it may run.</p>
            </div>
            <div class="flex items-center gap-2">
                @if (! $backupEnabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>
                        Enable backup
                    </x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">
                        Disable backup
                    </x-forms.button>
                @endif
                @if (str($status)->startsWith('running'))
                    <x-forms.button type="button" wire:click="backupNow">Back up now</x-forms.button>
                @endif
            </div>
        </div>

        <div class="application-settings-section-body space-y-5">
            @if ($backup->database_type === 'App\Models\StandalonePostgresql' && $backup->database_id !== 0
                    || $backup->database_type === 'App\Models\StandaloneMysql'
                    || $backup->database_type === 'App\Models\StandaloneMariadb')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="dumpAll" label="Database selection" onChange="instantSave" :options="[
                        ['value' => true, 'label' => 'Back up all databases'],
                        ['value' => false, 'label' => 'Choose databases'],
                    ]" />
                    @if (! $backup->dump_all)
                        <x-forms.input label="Databases to back up"
                            helper="Comma-separated database names. Leave empty to include the default database."
                            id="databasesToBackup" />
                    @endif
                </div>
            @elseif ($backup->database_type === 'App\Models\StandaloneMongodb')
                <x-forms.input label="Databases to include"
                    helper="Use database:collection1,collection2|database2 to exclude selected collections. Leave empty to include all databases and collections."
                    id="databasesToBackup" />
            @elseif ($backup->database_type === 'App\Models\StandaloneClickhouse')
                <x-forms.input label="Databases to back up"
                    helper="Comma-separated database names. Leave empty to include the default database."
                    id="databasesToBackup" />
            @endif

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input label="Frequency" id="frequency" required />
                <x-forms.input label="Timezone" id="timezone" disabled
                    helper="Uses the deployment server timezone, or the instance timezone when none is configured."
                    required />
                <x-forms.input label="Timeout" id="timeout" type="number" min="60"
                    helper="Maximum backup runtime in seconds." required />
            </div>
        </div>
    </section>
</form>
