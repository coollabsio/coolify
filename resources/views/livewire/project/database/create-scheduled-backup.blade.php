<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />
    <x-forms.button type="submit" @click="modalOpen=false">
        Save
    </x-forms.button>
</form>
