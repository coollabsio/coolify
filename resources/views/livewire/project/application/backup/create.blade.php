<form class="application-settings-form flex w-full flex-col gap-4" wire:submit="submit">
    @if ($targets->isEmpty())
        <x-empty size="sm" title="No backup targets"
            description="Add a persistent volume or directory mount before configuring a backup."
            icon-name="storages" />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.listbox id="targetKey" label="Backup target" required :options="$targets->map(fn ($target) => [
                'value' => $target['key'],
                'label' => $target['type'] . ': ' . $target['name'],
            ])->all()" x-bind:disabled="{{ $targetLocked ? 'true' : 'false' }}" />
            <x-forms.input id="frequency" placeholder="daily or 0 0 * * *"
                helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression."
                label="Frequency" required />
        </div>

        <div class="mt-2 flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="submit"
                class="button-highlighted">
                Create schedule
            </x-forms.button>
        </div>
    @endif
</form>
