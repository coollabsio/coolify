<form class="application-settings-form flex w-full flex-col gap-4" wire:submit="submit">
    <x-forms.input id="name" label="Name" required />
    <x-forms.input id="description" label="Description" />
    <div class="flex justify-end">
        <x-forms.button type="submit"
            class="bg-coollabs! text-white! hover:bg-coollabs-100!">Create team</x-forms.button>
    </div>
</form>
