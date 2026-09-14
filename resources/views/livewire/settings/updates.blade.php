<div>
    <x-slot:title>
        Update Settings | Coolify
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            <x-application.settings-section title="Update Coolify"
                helper="Install the latest Coolify version manually when an update is available.">
                <livewire:upgrade :full-button="true" key="settings-upgrade" />
            </x-application.settings-section>

            <x-application.settings-section title="Update channel">
                <div class="flex max-w-2xl flex-col gap-4">
                    <div class="max-w-md">
                        <x-forms.listbox id="update_channel" label="Manual update channel"
                            helper="Controls versions offered by manual update checks. Release candidates are never installed automatically."
                            onChange="saveUpdateChannel" :options="[
                                ['value' => 'stable', 'label' => 'Stable'],
                                ['value' => 'rc', 'label' => 'Release candidate'],
                            ]" />
                    </div>

                    @if ($update_channel === 'rc')
                        <x-callout type="warning" title="Release candidate channel">
                            Release candidates may be unstable and always require a manual upgrade. Automatic updates continue to install stable releases only.
                        </x-callout>
                    @elseif ($isWaitingForStable)
                        <x-callout type="info" title="Waiting for a newer stable release">
                            This instance is newer than the latest stable release. Coolify will not downgrade it and will wait until a newer stable version is available.
                        </x-callout>
                    @endif
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Update checks">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="checkManually">
                        <x-reicon name="refresh" class="size-3.5" />
                        Check now
                    </x-forms.button>
                </x-slot:actions>
                <x-forms.input required id="update_check_frequency" label="Check frequency"
                    placeholder="0 * * * *"
                    helper="A cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />
            </x-application.settings-section>

            <x-application.settings-section title="Automatic updates">
                <div class="grid gap-4 lg:grid-cols-2">
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

                    <x-forms.listbox id="auto_update_scope" label="Automatic update scope"
                        helper="Applies only to stable automatic updates. Manual updates can still offer newer minor versions and release candidates."
                        onChange="saveAutoUpdateScope" :options="[
                            ['value' => 'minor', 'label' => 'All minor and patch versions'],
                            ['value' => 'patch', 'label' => 'Patch versions only'],
                        ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Image registry">
                <div class="max-w-md">
                    <x-forms.listbox id="docker_registry_url" label="Docker registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        helper="Switch registries if the current source is rate limited." />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
