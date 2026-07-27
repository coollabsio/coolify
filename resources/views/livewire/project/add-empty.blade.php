<form class="space-y-4" wire:submit="submit">
    <div class="grid gap-4 md:grid-cols-2">
        <x-forms.input placeholder="Your project name" id="name" label="Name" required />
        <x-forms.input placeholder="A short project description" id="description" label="Description" />
    </div>

    <p
        class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-[12px] text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
        A production environment will be created automatically.
    </p>

    <footer class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <x-forms.button type="submit"
            defaultClass="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
            Create project
        </x-forms.button>
    </footer>
</form>
