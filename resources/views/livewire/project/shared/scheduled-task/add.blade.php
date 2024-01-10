<dialog id="newTask" class="modal">
    <form method="dialog" class="flex flex-col gap-2 rounded modal-box" wire:submit='submit'>
        <h3 class="text-lg font-bold">Add Scheduled Task</h3>
        <x-forms.input placeholder="Run cron" id="name" label="Name" required />
        <x-forms.input placeholder="php artisan schedule:run" id="command" label="Command" required />
        <x-forms.input placeholder="0 0 * * * or daily" id="frequency" label="Frequency" required />
        <x-forms.input placeholder="php" id="container" helper="You can leave it empty if your resource only have one container." label="Container name" />
        <x-forms.button onclick="newTask.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
