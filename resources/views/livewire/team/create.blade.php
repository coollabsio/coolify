<form class="application-settings-form flex w-full flex-col gap-4" wire:submit="submit">
    <x-forms.input id="name" label="Name" required />
    <x-forms.input id="description" label="Description" />
    <div class="flex justify-end">
        <x-forms.button type="submit"
            defaultClass="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
            Create team
        </x-forms.button>
    </div>
</form>
