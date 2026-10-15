<form class="space-y-4" wire:submit="submit">
    <x-forms.input placeholder="staging" id="name" label="Name" required />

    <footer
        class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit"
            defaultClass="button button-highlighted">
            Create environment
        </x-forms.button>
    </footer>
</form>
