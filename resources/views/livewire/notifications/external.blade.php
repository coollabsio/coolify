<div>
    <x-slot:title>
        Notifications | Coolify
    </x-slot>
    <x-notification.navbar />
    <form wire:submit='submit' class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2>External</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($team->external_enabled)
                <x-forms.button class="normal-case dark:text-white btn btn-xs no-animation btn-primary"
                    wire:click="sendTestNotification">
                    Send Test Notifications
                </x-forms.button>
            @endif
        </div>
        <div class="w-32">
            <x-forms.checkbox instantSave id="team.external_enabled" label="Enabled" />
        </div>
        <x-forms.input type="text" required id="team.external_url" label="URL" />
    </form>
</div>

