<form class="flex flex-col gap-2 pb-6" wire:submit.prevent='submit'>
    <div class="flex items-end gap-2">
        <h2>General</h2>
        <x-forms.button type="submit">
            Save
        </x-forms.button>
    </div>
    <div class="flex gap-2">
        <x-forms.input id="team.name" label="Name" required />
        <x-forms.input id="team.description" label="Description" />
    </div>
</form>
