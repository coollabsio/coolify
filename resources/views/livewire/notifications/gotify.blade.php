<div>
    <x-slot:title>
        Gotify Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Gotify"
                description="Deliver team alerts through your self-hosted Gotify server.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$gotifyEnabled" enabledProperty="gotifyEnabled"
                        toggleMethod="instantSaveGotifyEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    @can('update', $settings)
                        <x-forms.input type="text" required id="gotifyUrl" label="Server URL"
                            helper="e.g. https://gotify.example.com" />
                        <x-forms.input type="password" required id="gotifyToken" label="Application token"
                            helper="Create an application in your Gotify server to generate this token." />
                    @else
                        <x-forms.input disabled label="Server URL" value="Hidden (only admins can view)" />
                        <x-forms.input disabled label="Application token" value="Hidden (only admins can view)" />
                    @endcan
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="gotify" />
    </div>
    </x-notification.settings-layout>
</div>
