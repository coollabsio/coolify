<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Discord</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($model->discord_enabled)
                <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox instantSave id="model.discord_enabled" label="Notification Enabled" />
        </div>
        <x-forms.input type="password"
            helper="Generate a webhook in Discord.<br>Example: https://discord.com/api/webhooks/...." required
            id="model.discord_webhook_url" label="Webhook" />
    </form>
    @if (data_get($model, 'discord_enabled'))
        <h3 class="mt-4">Subscribe to events</h3>
        <div class="w-64">
            @if (is_dev())
                <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications_test" label="Test" />
            @endif
            <h4 class="mt-4">General</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications_status_changes"
                label="Container Status Changes" />
            <h4 class="mt-4">Applications</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications_deployments"
                label="Deployments" />
            <h4 class="mt-4">Databases</h4>
            <x-forms.checkbox instantSave="saveModel" id="model.discord_notifications_database_backups"
                label="Backup Statuses" />
        </div>
    @endif
</div>
