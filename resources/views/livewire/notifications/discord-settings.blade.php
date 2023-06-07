<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Discord</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                wire:click="sendTestNotification">
                Send Test Notifications
            </x-forms.button>
        </div>
        <div class="flex flex-col gap-2 xl:flex-row w-96">
            <x-forms.checkbox instantSave id="model.extra_attributes.discord_active" label="Notification Enabled" />
        </div>
        <x-forms.input type="string"
            helper="Generate a webhook in Discord.<br>Example: https://discord.com/api/webhooks/...." required
            id="model.extra_attributes.discord_webhook" label="Webhook" />
    </form>
</div>
