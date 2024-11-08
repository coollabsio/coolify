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
            @if ($discordEnabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave="instantSaveDiscordEnabled" id="discordEnabled" label="Enabled" />
        </div>
        <x-forms.input type="password"
            helper="Generate a webhook in Discord.<br>Example: https://discord.com/api/webhooks/...." required
            id="discordWebhookUrl" label="Webhook" />
    </form>
    @if ($discordEnabled)
        <h2 class="mt-4">Subscribe to events</h2>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="discordNotificationsTest" label="Test" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="discordNotificationsStatusChanges"
                label="Container Status Changes" />
            <x-forms.checkbox instantSave="saveModel" id="discordNotificationsDeployments"
                label="Application Deployments" />
            <x-forms.checkbox instantSave="saveModel" id="discordNotificationsDatabaseBackups" label="Backup Status" />
            <x-forms.checkbox instantSave="saveModel" id="discordNotificationsScheduledTasks"
                label="Scheduled Tasks Status" />
            <x-forms.checkbox instantSave="saveModel" id="discordNotificationsServerDiskUsage"
                label="Server Disk Usage" />
        </div>
    @endif
</div>
