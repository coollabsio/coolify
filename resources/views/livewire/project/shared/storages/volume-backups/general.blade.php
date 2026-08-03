<form wire:submit="save" class="application-settings-form">
    <x-unsaved-bar action="save" />

    <x-application.settings-section title="Backup schedule"
        description="Choose when this storage is archived and how long each backup may run.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (!$enabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>Enable backup</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">Disable backup</x-forms.button>
                @endif
                <x-forms.button type="button" wire:click="backupNow">Back up now</x-forms.button>
            </div>
        </x-slot:actions>

        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
            <span class="text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ $backup?->targetType() ?? ($storage instanceof \App\Models\LocalFileVolume ? 'Directory' : 'Volume') }}
            </span>
            <code class="truncate text-[12px] font-medium text-neutral-900 dark:text-fg">
                {{ $backup?->targetName() ?? ($storage instanceof \App\Models\LocalFileVolume ? $storage->fs_path : $storage->name) }}
            </code>
        </div>

        <x-callout type="warning" title="File-level consistency">
            Archives created while the application writes to this storage can be inconsistent. Stopping containers
            during the archive is safer, but briefly interrupts the application.
        </x-callout>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <x-forms.listbox id="stopDuringBackup" label="Archive behavior" live onChange="instantSave"
                :options="[
                    ['value' => false, 'label' => 'Keep containers running'],
                    ['value' => true, 'label' => 'Stop containers during archive'],
                ]" />
            <x-forms.input id="frequency" label="Frequency" required
                helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
            <x-forms.input id="timezone" label="Timezone" disabled
                helper="Uses the backup server timezone, or the instance timezone when none is configured." required />
            <x-forms.input id="timeout" type="number" min="60" max="36000" label="Timeout"
                helper="Maximum backup runtime in seconds." required />
        </div>
    </x-application.settings-section>
</form>
