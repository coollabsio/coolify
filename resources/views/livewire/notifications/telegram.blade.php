<div>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Telegram</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->telegram_enabled)
                <x-forms.button class="dark:text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave id="team.telegram_enabled" label="Enabled" />
        </div>
        <div class="flex gap-2">
            <x-forms.input type="password"
                helper="Get it from the <a class='inline-block dark:text-white underline' href='https://t.me/botfather' target='_blank'>BotFather Bot</a> on Telegram."
                required id="team.telegram_token" label="Token" />
            <x-forms.input helper="Recommended to add your bot to a group chat and add its Chat ID here." required
                id="team.telegram_chat_id" label="Chat ID" />
        </div>
        @if (data_get($team, 'telegram_enabled'))
            <h2 class="mt-4">Subscribe to events</h2>
            <div class="w-96">
                @if (isDev())
                    <div class="w-64">
                        <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_test"
                            label="Test" />
                        <x-forms.input
                            helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                            id="team.telegram_notifications_test_message_thread_id" label="Custom Topic ID" />
                    </div>
                @endif
                <div class="w-64">
                    <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_status_changes"
                        label="Container Status Changes" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="team.telegram_notifications_status_changes_message_thread_id" label="Custom Topic ID" />
                </div>
                <div class="w-64">
                    <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_deployments"
                        label="Application Deployments" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="team.telegram_notifications_deployments_message_thread_id" label="Custom Topic ID" />
                </div>
                <div class="w-64">
                    <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_database_backups"
                        label="Backup Status" />
                    <x-forms.input
                        helper="If you are using Group chat with Topics, you can specify the topics ID. If empty, General topic will be used."
                        id="team.telegram_notifications_database_backups_message_thread_id" label="Custom Topic ID" />
                </div>
            </div>
        @endif
    </form>
</div>
