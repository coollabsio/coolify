<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Telegram</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($telegramEnabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave="instantSaveTelegramEnabled" id="telegramEnabled" label="Enabled" />
        </div>
        <div class="flex gap-2">

            <x-forms.input type="password" autocomplete="new-password"
                helper="Get it from the <a class='inline-block underline dark:text-white' href='https://t.me/botfather' target='_blank'>BotFather Bot</a> on Telegram."
                required id="telegramToken" label="Token" />
            <x-forms.input helper="Recommended to add your bot to a group chat and add its Chat ID here." required
                id="telegramChatId" label="Chat ID" />
        </div>
        @if ($telegramEnabled)
            <h2 class="mt-4">Subscribe to events</h2>
            <div class="flex flex-col gap-4 w-96">
                @if (isDev())
                    <div class="flex flex-col">
                        <h4>Test Notification</h4>
                        <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsTest" label="Enabled" />
                        <x-forms.input
                            helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                            id="telegramNotificationsTestMessageThreadId" label="Custom Topic ID" />
                    </div>
                @endif
                <div class="flex flex-col">
                    <h4>Container Status Changes</h4>
                    <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsStatusChanges" label="Enabled" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="telegramNotificationsStatusChangesMessageThreadId" label="Custom Topic ID" />
                </div>
                <div class="flex flex-col">
                    <h4>Application Deployments</h4>
                    <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsDeployments" label="Enabled" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="telegramNotificationsDeploymentsMessageThreadId" label="Custom Topic ID" />
                </div>
                <div class="flex flex-col">
                    <h4>Database Backup Status</h4>
                    <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsDatabaseBackups"
                        label="Enabled" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="telegramNotificationsDatabaseBackupsMessageThreadId" label="Custom Topic ID" />
                </div>
                <div class="flex flex-col">
                    <h4>Scheduled Tasks Status</h4>
                    <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsScheduledTasks"
                        label="Enabled" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="telegramNotificationsScheduledTasksMessageThreadId" label="Custom Topic ID" />
                </div>
                <div class="flex flex-col">
                    <h4>Server Disk Usage</h4>
                    <x-forms.checkbox instantSave="saveModel" id="telegramNotificationsServerDiskUsage"
                        label="Enabled" />
                </div>
            </div>
        @endif
    </form>
</div>
