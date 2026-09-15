<div class="application-settings-form">
    <form class="flex flex-col gap-4" wire:submit="createAgeKey">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="description" label="Description" />
            <div class="lg:col-span-2">
                <x-forms.input realtimeValidation id="publicKey" monospace placeholder="age1..." label="Public key"
                    required />
            </div>
        </div>
        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <button type="submit"
                class="button button-highlighted">
                Continue
            </button>
        </div>
    </form>
</div>
