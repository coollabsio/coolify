<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Discord</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($model->discord->enabled)
                <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox instantSave id="model.discord.enabled" label="Notification Enabled" />
        </div>
        <x-forms.input type="string"
            helper="Generate a webhook in Discord.<br>Example: https://discord.com/api/webhooks/...." required
            id="model.discord.webhook_url" label="Webhook" />
    </form>
    @if (data_get($model, 'discord.enabled'))
        <h4 class="mt-4">Subscribe to events</h4>
        <div class="w-64 ">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications.test"
                    label="Test Notifications" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications.deployments"
                label="New Deployments" />
        </div>
    @endif
</div>
