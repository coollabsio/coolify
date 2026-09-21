<div>
    <x-slot:title>
        Microsoft Teams Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Microsoft Teams"
                description="Send team notifications to Microsoft Teams through an incoming webhook.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$microsoftTeamsEnabled" enabledProperty="microsoftTeamsEnabled"
                        toggleMethod="instantSaveMicrosoftTeamsEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" required id="microsoftTeamsWebhookUrl" label="Webhook URL"
                                helper="Create an incoming webhook (or Workflow) in your Microsoft Teams channel." />
                        @else
                            <x-forms.input disabled label="Webhook URL" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="microsoft_teams" />
    </div>
    </x-notification.settings-layout>
</div>
