<form class="application-settings-form flex w-full flex-col gap-4" wire:submit="submit">
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />
    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit" @click="modalOpen=false" isHighlighted>
            Add schedule
        </x-forms.button>
    </div>
</form>
