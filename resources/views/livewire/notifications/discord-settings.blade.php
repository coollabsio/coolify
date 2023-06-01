<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h3>Discord</h3>
            <x-forms.button class="w-16 mt-4" type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-col gap-2 xl:flex-row w-96">
            <x-forms.checkbox instantSave id="model.smtp_attributes.discord_active" label="Notification Enabled" />
        </div>
        <div class="flex flex-col gap-2 xl:flex-row w-96">
            <x-forms.input required id="model.smtp_attributes.discord_webhook" label="Webhook" />
        </div>
        <div>

        </div>
    </form>
</div>
