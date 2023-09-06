<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Telegram</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->telegram_enabled)
                <x-forms.button class="text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-48">
            <x-forms.checkbox instantSave id="team.telegram_enabled" label="Notification Enabled" />
        </div>
        <div class="flex gap-2">
            <x-forms.input type="password" helper="Get it from the <a class='inline-block text-white underline' href='https://t.me/botfather' target='_blank'>BotFather Bot</a> on Telegram." required
                id="team.telegram_token" label="Token" />
            <x-forms.input type="password" helper="Recommended to add your bot to a group chat and add its Chat ID here." required
                id="team.telegram_chat_id" label="Chat ID" />
        </div>
    </form>
    @if (data_get($team, 'telegram_enabled'))
        <h3 class="mt-4">Subscribe to events</h3>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_test" label="Test" />
            @endif
            <h4 class="mt-4">General</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_status_changes"
                label="Container Status Changes" />
            <h4 class="mt-4">Applications</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_deployments"
                label="Deployments" />
            <h4 class="mt-4">Databases</h4>
            <x-forms.checkbox instantSave="saveModel" id="team.telegram_notifications_database_backups"
                label="Backup Statuses" />
        </div>
    @endif
</div>
