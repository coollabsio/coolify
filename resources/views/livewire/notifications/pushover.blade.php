<div>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Pushover</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->pushover_enabled)
                <x-forms.button class="dark:text-white normal-case btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave id="team.pushover_enabled" label="Enabled" />
        </div>
        <x-forms.input type="password" required
            id="team.pushover_token" label="Token" />

        <x-forms.input type="text" required
        id="team.pushover_user" label="User Key" />
    </form>
    @if (data_get($team, 'pushover_enabled'))
        <h2 class="mt-4">Subscribe to events</h2>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="team.pushover_notifications_test" label="Test" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="team.pushover_notifications_status_changes"
                label="Container Status Changes" />
            <x-forms.checkbox instantSave="saveModel" id="team.pushover_notifications_deployments"
                label="Application Deployments" />
            <x-forms.checkbox instantSave="saveModel" id="team.pushover_notifications_database_backups"
                label="Backup Status" />
        </div>
    @endif
</div>
