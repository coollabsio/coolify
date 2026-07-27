<div>
    <x-slot:title>
        Pushover Notifications | Coolify
    </x-slot>

    <x-notification.navbar />

    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Pushover"
                description="Deliver team alerts through your Pushover application.">
                <x-slot:actions>
                    <button type="button" class="button" wire:click="sendTestNotification"
                        @disabled(!$pushoverEnabled)>
                        <x-reicon name="notifications" class="size-3.5" />
                        Send test
                    </button>
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <div class="w-full sm:w-72">
                            <x-forms.listbox id="pushoverEnabled" label="Pushover delivery"
                                onChange="instantSavePushoverEnabled"
                                :disabled="!auth()->user()->can('update', $settings)" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        </div>
                    </div>
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
</div>
