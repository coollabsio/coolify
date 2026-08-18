<div>
    <x-slot:title>
        Webhook Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Webhook"
                description="Send JSON event payloads to your own HTTP endpoint.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$webhookEnabled" enabledProperty="webhookEnabled"
                        toggleMethod="instantSaveWebhookEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" required id="webhookUrl" label="Webhook URL"
                                helper="Coolify sends POST requests to this HTTP or HTTPS endpoint." />
                        @else
                            <x-forms.input disabled label="Webhook URL" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="webhook" />
    </div>
    </x-notification.settings-layout>
</div>
