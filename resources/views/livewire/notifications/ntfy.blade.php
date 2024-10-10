<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>Ntfy.sh</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->ntfy_enabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave id="team.ntfy_enabled" label="Enabled" />
        </div>

        <x-forms.input type="text"
            helper="Enter your preferred ntfy host<br>Example: https://ntfy.sh" required
            id="team.ntfy_url" label="Host" />

        <x-forms.input type="text"
            helper="Ntfy topic you want to subscribe to" required
            id="team.ntfy_topic" label="Topic" />

        <div class="flex gap-2">
            <x-forms.input helper="If you have set up a user please enter its username"
                id="team.ntfy_username" label="Username" />
            <x-forms.input type="password"
                helper="If you have set up a user please enter its password"
                id="team.ntfy_password" label="Password" />
        </div>
    </form>
    @if (data_get($team, 'ntfy_enabled'))
        <h2 class="mt-4">Subscribe to events</h2>
        <div class="w-64">
            @if (isDev())
                <x-forms.checkbox instantSave="saveModel" id="team.ntfy_notifications_test" label="Test" />
            @endif
            <x-forms.checkbox instantSave="saveModel" id="team.ntfy_notifications_status_changes"
                label="Container Status Changes" />
            <x-forms.checkbox instantSave="saveModel" id="team.ntfy_notifications_deployments"
                label="Application Deployments" />
            <x-forms.checkbox instantSave="saveModel" id="team.ntfy_notifications_database_backups"
                label="Backup Status" />
            <x-forms.checkbox instantSave="saveModel" id="team.ntfy_notifications_scheduled_tasks"
                label="Scheduled Tasks Status" />
        </div>
    @endif
</div>

