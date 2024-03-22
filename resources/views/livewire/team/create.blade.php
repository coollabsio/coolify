<form class="flex flex-col w-full gap-2" wire:submit='submit'>
    <x-forms.input autofocus id="name" label="Name" required />
    <x-forms.input id="description" label="Description" />
    <x-forms.button type="submit">
        Continue
    </x-forms.button>
</form>
