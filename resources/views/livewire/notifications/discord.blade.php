<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Discord</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->discord_enabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave id="team.discord_enabled" label="Enabled" />
        </div>
        <x-forms.input type="password"
            helper="Generate a webhook in Discord.<br>Example: https://discord.com/api/webhooks/...." required
            id="team.discord_webhook_url" label="Webhook" />
    </form>
    @if (data_get($team, 'discord_enabled'))
        <h2 class="mt-4">Subscribe to events</h2>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="team.discord_notifications_test" label="Test" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="team.discord_notifications_status_changes"
                label="Container Status Changes" />
            <x-forms.checkbox instantSave="saveModel" id="team.discord_notifications_deployments"
                label="Application Deployments" />
            <x-forms.checkbox instantSave="saveModel" id="team.discord_notifications_database_backups"
                label="Backup Status" />
            <x-forms.checkbox instantSave="saveModel" id="team.discord_notifications_scheduled_tasks"
                label="Scheduled Tasks Status" />
        </div>
    @endif
</div>
