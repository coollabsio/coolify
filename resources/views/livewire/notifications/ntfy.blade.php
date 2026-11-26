@php
    $priorityOptions = [
        ['value' => 1, 'label' => '1 - Min'],
        ['value' => 2, 'label' => '2 - Low'],
        ['value' => 3, 'label' => '3 - Default'],
        ['value' => 4, 'label' => '4 - High'],
        ['value' => 5, 'label' => '5 - Urgent'],
    ];
@endphp

<div>
    <x-slot:title>
        Ntfy Notifications | Coolify
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit" class="flex flex-col gap-6">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="Ntfy"
                description="Publish team alerts to a topic on ntfy.sh or your own ntfy server.">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$ntfyEnabled" enabledProperty="ntfyEnabled"
                        toggleMethod="instantSaveNtfyEnabled" :canUpdate="auth()->user()->can('update', $settings)"
                        :canResource="$settings" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    @can('update', $settings)
                        <x-forms.input required id="ntfyUrl" label="Server URL" placeholder="https://ntfy.sh"
                            helper="Use https://ntfy.sh or the URL of your own ntfy server. A server on a private network must be allowed in Settings → Advanced." />
                        <x-forms.input type="password" autocomplete="new-password" required id="ntfyTopic" label="Topic"
                            placeholder="coolify-alerts"
                            helper="Letters, numbers, hyphens, and underscores. On a public server, anyone who knows the topic can read it, so pick one that is hard to guess." />
                        <x-forms.listbox id="ntfyAuthMethod" label="Authentication" live :options="[
                            ['value' => 'none', 'label' => 'None'],
                            ['value' => 'token', 'label' => 'Access token'],
                            ['value' => 'basic', 'label' => 'Username and password'],
                        ]" />
                        @if ($ntfyAuthMethod === 'token')
                            <x-forms.input type="password" autocomplete="new-password" id="ntfyToken" label="Access token"
                                helper="Create a token with ntfy token add, or in the ntfy web app." />
                        @elseif ($ntfyAuthMethod === 'basic')
                            <x-forms.input autocomplete="off" id="ntfyUsername" label="Username" />
                            <x-forms.input type="password" autocomplete="new-password" id="ntfyPassword" label="Password" />
                        @endif
                    @else
                        <x-forms.input disabled label="Server URL" value="Hidden (only admins can view)" />
                        <x-forms.input disabled label="Topic" value="Hidden (only admins can view)" />
                        <x-forms.input disabled label="Credentials" value="Hidden (only admins can view)" />
                    @endcan
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Message priority"
                description="Priority controls sound and vibration on your devices. Set it for each kind of event.">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox canGate="update" :canResource="$settings" id="ntfyPrioritySuccessEvents"
                        label="Success" helper="Successful deployments, backups, and cleanups, and reachable servers."
                        :options="$priorityOptions" />
                    <x-forms.listbox canGate="update" :canResource="$settings" id="ntfyPriorityInfoEvents"
                        label="Info" helper="General notices, server patches, and test notifications."
                        :options="$priorityOptions" />
                    <x-forms.listbox canGate="update" :canResource="$settings" id="ntfyPriorityWarningEvents"
                        label="Warning" helper="Container restarts, disk usage, SSL certificates, outdated Traefik, expiring API tokens, and backups with warnings."
                        :options="$priorityOptions" />
                    <x-forms.listbox canGate="update" :canResource="$settings" id="ntfyPriorityErrorEvents"
                        label="Error" helper="Failed deployments, backups, and tasks, stopped resources, unreachable servers, and reached restart limits."
                        :options="$priorityOptions" />
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="ntfy" />
    </div>
    </x-notification.settings-layout>
</div>
