<div>
    <h1>New Team</h1>
    <div class="subtitle">Add a new team.</div>
    <form class="flex flex-col gap-2" wire:submit='submit'>
        <x-forms.input autofocus id="name" label="Name" required />
        <x-forms.input id="description" label="Description" />
        <x-forms.button type="submit">
            Save Team
        </x-forms.button>
    </form>
</div>
