<div>
    <x-slot:title>
        Update Settings | Coolify
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency,update_channel,update_custom_tag" />

            <x-application.settings-section title="Updates"
                helper="Install the latest Coolify version manually when an update is available.">
                <x-slot:actions>
                    <livewire:upgrade :full-button="true" key="settings-upgrade" />
                    <x-forms.button type="button" wire:click="checkManually">
                        <x-reicon name="refresh" class="size-3.5" />
                        Check now
                    </x-forms.button>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="update_channel" label="Channel" live :options="[
                        ['value' => 'stable', 'label' => 'Stable', 'description' => 'Stable releases'],
                        ['value' => 'preview', 'label' => 'Preview', 'description' => 'Betas and release candidates, then the stable release'],
                        ['value' => 'nightly', 'label' => 'Nightly', 'description' => 'A build of the next version twice a day, then every release'],
                        ['value' => 'custom', 'label' => 'Custom image', 'description' => 'A pinned image, such as the build of a pull request'],
                    ]"
                        helper="The versions this instance upgrades to." />

                    @if ($update_channel === 'custom')
                        <x-forms.input required id="update_custom_tag" label="Image tag" placeholder="pr-1234"
                            helper="A tag of ghcr.io/coollabsio/coolify, such as pr-1234 for the build of pull request 1234." />
                    @else
                        <div class="hidden lg:block"></div>
                    @endif

                    @if ($update_channel === 'nightly')
                        <x-callout type="danger" title="Nightly builds can break this instance" class="lg:col-span-2">
                            Nightly builds are untested builds of the next version. Things can break at any time and
                            breaking changes are possible, including database changes a stable release does not expect.
                            Back up first and use nightly only on an instance you can rebuild. Switching back to stable
                            waits until a stable release is newer than the installed nightly.
                        </x-callout>
                    @elseif ($update_channel === 'custom')
                        <x-callout type="danger" title="A custom image can break this instance" class="lg:col-span-2">
                            The image installs only when you upgrade to it here, and automatic updates pause until you
                            switch back to a channel. Pull request builds are untested and can include database changes
                            a later release does not expect.
                        </x-callout>
                    @endif

                    <x-forms.input required id="update_check_frequency" label="Check frequency"
                        placeholder="0 * * * *"
                        helper="A cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />

                    <x-forms.listbox id="docker_registry_url" label="Docker registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        helper="Switch registries if the current source is rate limited." />

                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <x-forms.listbox disabled id="is_auto_update_enabled" label="Automatic updates"
                            helper="Controlled by the AUTOUPDATE environment variable." :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @else
                        <x-forms.listbox id="is_auto_update_enabled" label="Automatic updates"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @endif

                    @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                        <x-forms.input required id="auto_update_frequency" label="Update frequency"
                            placeholder="0 0 * * *"
                            helper="Cron expression or preset for installing updates." />
                    @else
                        <x-forms.input label="Update frequency" disabled placeholder="Disabled" />
                    @endif
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
