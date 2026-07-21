<form wire:submit="save" class="flex flex-col gap-4">
    <div>
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            <h2>General</h2>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <x-forms.button type="submit" class="w-full sm:w-auto">Save</x-forms.button>
                @if (!$enabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>Enable Backup</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">Disable Backup</x-forms.button>
                @endif

                <x-forms.button type="button" wire:click="backupNow" class="w-full sm:w-auto">Backup Now</x-forms.button>
            </div>
        </div>
        <p class="pt-1 text-sm text-neutral-600 dark:text-neutral-400">
            {{ $backup?->targetType() ?? ($storage instanceof \App\Models\LocalFileVolume ? 'Directory' : 'Volume') }}:
            <span class="font-medium text-neutral-800 dark:text-neutral-200">
                {{ $backup?->targetName() ?? ($storage instanceof \App\Models\LocalFileVolume ? $storage->fs_path : $storage->name) }}
            </span>
        </p>
    </div>

    <div class="p-3 text-sm rounded bg-warning/10 text-warning">
        Backups made while the application is writing to this storage may be inconsistent or corrupted. You can
        gracefully stop containers during the archive step for a safer file-level backup, but this briefly
        interrupts the application.
    </div>

    <div class="w-full max-w-md">
        <x-forms.checkbox instantSave id="stopDuringBackup" label="Stop containers while creating the archive"
            helper="Off by default. Containers using this storage are gracefully stopped and restarted immediately after the archive is created." />
    </div>

    <div class="flex flex-col gap-4">
        <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
            <x-forms.input id="frequency" label="Frequency" required
                helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression." />
            <x-forms.input id="timezone" label="Timezone" disabled
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)" required />
            <x-forms.input id="timeout" type="number" min="60" max="36000" label="Timeout"
                helper="The timeout of the backup job in seconds." required />
        </div>
    </div>
</form>
