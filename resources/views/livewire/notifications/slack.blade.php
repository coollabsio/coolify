<div>
    <x-slot:title>
        Slack Notifications | Coolify
    </x-slot>

    <x-notification.navbar />

    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Slack"
                description="Send team notifications to Slack through an incoming webhook.">
                <x-slot:actions>
                    <button type="button" class="button" wire:click="sendTestNotification"
                        @disabled(!$slackEnabled)>
                        <x-reicon name="notifications" class="size-3.5" />
                        Send test
                    </button>
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <div class="w-full sm:w-72">
                            <x-forms.listbox id="slackEnabled" label="Slack delivery"
                                onChange="instantSaveSlackEnabled"
                                :disabled="!auth()->user()->can('update', $settings)" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        </div>
                    </div>
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" required id="slackWebhookUrl" label="Webhook URL"
                                helper="Create an incoming webhook in your Slack app settings." />
                        @else
                            <x-forms.input disabled label="Webhook URL" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="slack" />
    </div>
</div>
