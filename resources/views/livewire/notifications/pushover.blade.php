<div>
    <x-slot:title>
        Pushover Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Pushover"
                description="Deliver team alerts through your Pushover application.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$pushoverEnabled" enabledProperty="pushoverEnabled"
                        toggleMethod="instantSavePushoverEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    @can('update', $settings)
                        <x-forms.input type="password" required id="pushoverUserKey" label="User key"
                            helper="Find this in the Pushover dashboard." />
                        <x-forms.input type="password" required id="pushoverApiToken" label="API token"
                            helper="Create an application in Pushover to generate this token." />
                    @else
                        <x-forms.input disabled label="User key" value="Hidden (only admins can view)" />
                        <x-forms.input disabled label="API token" value="Hidden (only admins can view)" />
                    @endcan
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="pushover" />
    </div>
    </x-notification.settings-layout>
</div>
