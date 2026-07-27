<div>
    <x-slot:title>
        Discord Notifications | Coolify
    </x-slot>

    <x-notification.navbar />

    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Discord"
                description="Send team notifications to a Discord channel through an incoming webhook.">
                <x-slot:actions>
                    <button type="button" class="button" wire:click="sendTestNotification"
                        @disabled(!$discordEnabled)>
                        <x-reicon name="notifications" class="size-3.5" />
                        Send test
                    </button>
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="discordEnabled" label="Discord delivery"
                        onChange="instantSaveDiscordEnabled"
                        :disabled="!auth()->user()->can('update', $settings)" :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                    <x-forms.listbox id="discordPingEnabled" label="Critical event mention"
                        helper="Mention @here when a critical event occurs."
                        onChange="instantSaveDiscordPingEnabled"
                        :disabled="!auth()->user()->can('update', $settings)" :options="[
                            ['value' => true, 'label' => 'Mention @here'],
                            ['value' => false, 'label' => 'Do not mention'],
                        ]" />
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" required id="discordWebhookUrl" label="Webhook URL"
                                helper="Create an incoming webhook in your Discord server settings." />
                        @else
                            <x-forms.input disabled label="Webhook URL" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="discord" />
    </div>
</div>
