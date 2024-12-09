<div>
    <x-slot:title>
        Notifications | Coolify
        </x-slot>
        <x-notification.navbar />
        <form wire:submit='submit' class="flex flex-col gap-4">
            <div class="flex items-center gap-2">
                <h2>Slack</h2>
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
                @if ($slackEnabled)
                    <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                        wire:click="sendTestNotification">
                        Send Test Notifications
                    </x-forms.button>
                @endif
            </div>
            <div class="w-32">
                <x-forms.checkbox instantSave="instantSaveSlackEnabled" id="slackEnabled" label="Enabled" />
            </div>
            <x-forms.input type="password"
                helper="Generate a webhook in Slack.<br>Example: https://hooks.slack.com/services/...." required
                id="slackWebhookUrl" label="Webhook" />
        </form>
        @if ($slackEnabled)
            <h2 class="mt-4">Subscribe to events</h2>
            <div class="w-64">
                @if (isDev())
                    <x-forms.checkbox instantSave="saveModel" id="slackNotificationsTest" label="Test" />
                @endif
                <x-forms.checkbox instantSave="saveModel" id="slackNotificationsStatusChanges"
                    label="Container Status Changes" />
                <x-forms.checkbox instantSave="saveModel" id="slackNotificationsDeployments"
                    label="Application Deployments" />
                <x-forms.checkbox instantSave="saveModel" id="slackNotificationsDatabaseBackups" label="Backup Status" />
                <x-forms.checkbox instantSave="saveModel" id="slackNotificationsScheduledTasks"
                    label="Scheduled Tasks Status" />
                <x-forms.checkbox instantSave="saveModel" id="slackNotificationsServerDiskUsage"
                    label="Server Disk Usage" />
            </div>
        @endif
</div>