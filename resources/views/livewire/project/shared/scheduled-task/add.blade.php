<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="Run cron" id="name" label="Name"  />
    <x-forms.input placeholder="php artisan schedule:run" id="command" label="Command"  />
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency" label="Frequency"  />
    <x-forms.input placeholder="php" id="container"
        helper="You can leave it empty if your resource only have one container." label="Container name" />
    <x-forms.button @click="slideOverOpen=false" type="submit">
        Save
    </x-forms.button>
</form>
